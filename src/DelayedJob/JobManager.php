<?php
declare(strict_types=1);

namespace DelayedJobs\DelayedJob;

use Cake\Chronos\ChronosInterface;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Event\Event;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Event\EventInterface;
use Cake\I18n\Time;
use Cake\Log\Log;
use DelayedJobs\Broker\BrokerInterface;
use DelayedJobs\Broker\RabbitMqBroker;
use DelayedJobs\Datasource\DatasourceInterface;
use DelayedJobs\Datasource\TableDatasource;
use DelayedJobs\DelayedJob\Exception\EnqueueException;
use DelayedJobs\DelayedJob\Exception\JobExecuteException;
use DelayedJobs\Exception\NonRetryableException;
use DelayedJobs\Exception\PausedException;
use DelayedJobs\Result\Failed;
use DelayedJobs\Result\Pause;
use DelayedJobs\Result\ResultInterface;
use DelayedJobs\Result\Success;
use DelayedJobs\Traits\DebugLoggerTrait;
use DelayedJobs\Worker\JobWorkerInterface;
use Error;
use Exception;
use Throwable;

/**
 * Manages the persisting and queuing of jobs, job execution and queue consumption
 */
class JobManager implements EventDispatcherInterface, ManagerInterface
{
    use EventDispatcherTrait;
    use InstanceConfigTrait;
    use DebugLoggerTrait;

    /**
     * The basic retry time in seconds
     */
    public const BASE_RETRY_TIME = 5;
    /**
     * Factor to apply to retries
     */
    public const RETRY_FACTOR = 4;

    /**
     * The singleton instance
     *
     * @var \DelayedJobs\DelayedJob\ManagerInterface|null
     */
    protected static $_instance;

    /**
     * Flag to indicate if we are consuming at the moment or not
     *
     * @var bool
     */
    protected $consuming = false;

    /**
     * @var \DelayedJobs\Datasource\DatasourceInterface|null
     */
    protected $_datastore;

    /**
     * @var array
     */
    protected $_defaultConfig = [
        'maximum' => [
            'priority' => 255,
        ],
        'datasource' => [
            'className' => TableDatasource::class,
        ],
        'messageBroker' => [
            'className' => RabbitMqBroker::class,
        ],
    ];

    /**
     * @var \DelayedJobs\Broker\BrokerInterface|null
     */
    protected $_messageBroker;
    /**
     * @var null|\DelayedJobs\DelayedJob\Job
     */
    protected $_currentJob;
    /**
     * @var array
     */
    protected $_enqueuedJobs = [];
    /**
     * @var string
     */
    protected $_hostname = '';

    /**
     * Constructor for class
     *
     * @param array $config Config array
     * @param \DelayedJobs\Datasource\DatasourceInterface $datastore Datastore to inject
     * @param \DelayedJobs\Broker\BrokerInterface $messageBroker Broker that handles message distribution
     */
    public function __construct(
        array $config = [],
        ?DatasourceInterface $datastore = null,
        ?BrokerInterface $messageBroker = null
    ) {
        $this->_datastore = $datastore;
        $this->_messageBroker = $messageBroker;
        $this->_hostname = (string)gethostname();

        $this->setConfig($config);
    }

    /**
     * Returns the globally available instance of a \DelayedJobs\DelayedJobs\JobsManager
     *
     * @return \DelayedJobs\DelayedJob\ManagerInterface the global delayed jobs manager
     */
    public static function getInstance(): ManagerInterface
    {
        if (empty(static::$_instance)) {
            static::$_instance = new self(Configure::read('DelayedJobs'));
        }

        return static::$_instance;
    }

    /**
     * Set as the globally available instance
     *
     * @param \DelayedJobs\DelayedJob\ManagerInterface|null $manager The manager interface to inject
     * @return void
     */
    public static function setInstance(?ManagerInterface $manager): void
    {
        static::$_instance = $manager;
    }

    /**
     * @return string
     */
    public function getHostname(): string
    {
        if (empty($this->_hostname)) {
            $this->_hostname = (string)gethostname();
        }

        return $this->_hostname;
    }

    /**
     * @return \DelayedJobs\Datasource\DatasourceInterface
     */
    public function getDatasource(): DatasourceInterface
    {
        if ($this->_datastore) {
            return $this->_datastore;
        }

        $config = $this->getConfig('datasource');
        $this->_datastore = new $config['className']($config, $this);

        return $this->_datastore;
    }

    /**
     * @return \DelayedJobs\Broker\BrokerInterface
     */
    public function getMessageBroker(): BrokerInterface
    {
        if ($this->_messageBroker) {
            return $this->_messageBroker;
        }

        $config = $this->getConfig('messageBroker');
        $this->_messageBroker = new $config['className']($config, $this);

        return $this->_messageBroker;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job that needs to be enqueued
     * @param bool $skipPersist Skip the persistance step (e.g. it's already been persisted)
     * @return void
     */
    public function enqueue(Job $job, bool $skipPersist = false): void
    {
        if (!$skipPersist) {
            $this->_persistToDatastore($job);
        }

        $this->_enqueuedJobs[] = (int)$job->getId();
        if (
            $job->getSequence() &&
            $this->getDatasource()->currentlySequenced($job)
        ) {
            $this->addHistoryAndPersist($job, 'Not pushed to broker due to sequence.');

            return;
        }
        $this->_pushToBroker($job);
    }

    /**
     * {@inheritDoc}
     */
    public function enqueuePersisted($id, $priority): void
    {
        $job = new Job(compact('id', 'priority'));

        $this->getMessageBroker()
            ->publishJob($job);
    }

    /**
     * {@inheritDoc}
     */
    public function enqueueBatch(array $jobs): void
    {
        foreach ($jobs as $job) {
            $job->addHistory('Batch created', [
                'parentJob' => $this->_currentJob ? $this->_currentJob->getId() : null,
            ]);
        }
        $this->getDatasource()->persistJobs($jobs);

        collection($jobs)
            ->filter(function (Job $job) {
                $this->_enqueuedJobs[] = (int)$job->getId();
                $jobSequence = $job->getSequence();
                if (!$jobSequence) {
                    return true;
                }

                //If true then the job sequence is already enqueued, don't push to the broker
                $currentlySequenced = $this->getDatasource()->currentlySequenced($job);

                if ($currentlySequenced) {
                    $this->addHistoryAndPersist($job, 'Not pushed to broker due to sequence.');
                }

                return $currentlySequenced === false; //If not currently sequenced, then carry on
            })
            ->each(function (Job $job) {
                $this->_pushToBroker($job);
            });
    }

    /**
     * @param int $retryCount Number of times the job has retried
     * @return \Cake\I18n\Time
     */
    protected function _calculateRetryTime(int $retryCount): Time
    {
        if ($this->getConfig('DelayedJobs.retryTimes.' . $retryCount)) {
            $growthFactor = $this->getConfig('DelayedJobs.retryTimes.' . $retryCount);
        } else {
            $growthFactor = static::BASE_RETRY_TIME + $retryCount ** static::RETRY_FACTOR;
        }

        $growthFactorRandom = random_int(1, 2) === 2 ? -1 : + 1;
        $growthFactorRandom *= ceil(\log($growthFactor + random_int((int)($growthFactor / 2), $growthFactor)));

        $growthFactor += $growthFactorRandom;

        return new Time("+{$growthFactor} seconds");
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to enqueue as recurring
     * @param \Cake\Chronos\ChronosInterface $runAt Time that the job should repeat
     * @return void
     */
    protected function _enqueueRecurring(Job $job, ChronosInterface $runAt)
    {
        //Recurring job
        if ($this->isSimilarJob($job)) {
            return;
        }

        $recurringJob = clone $job;
        $recurringJob->setRunAt($runAt);
        $this->enqueue($recurringJob);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchJob($jobId): Job
    {
        return $this->getDatasource()
            ->fetchJob($jobId);
    }

    /**
     * Populates an existing job object with datasource data
     *
     * @param \DelayedJobs\DelayedJob\Job $job Job to run
     * @return \DelayedJobs\DelayedJob\Job
     */
    public function loadJob(Job $job): Job
    {
        $this->getDatasource()
            ->loadJob($job);

        return $job;
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus($jobId): int
    {
        $job = $this->getDatasource()
            ->fetchJob($jobId);

        return $job->getStatus();
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to lock
     * @return void
     */
    protected function lock(Job $job): void
    {
        $job->setStatus(Job::STATUS_BUSY)
            ->setStartTime(Time::now())
            ->setHostName($this->getHostname())
            ->addHistory('Locked for execution');

        $this->_persistToDatastore($job);
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job we're busy with
     * @param mixed $result The result of the job
     * @return \DelayedJobs\Result\ResultInterface
     */
    protected function _buildResultObject(Job $job, $result): ResultInterface
    {
        if ($result instanceof ResultInterface) {
            return $result;
        }

        if ($result instanceof ChronosInterface) {
            return Success::create(sprintf('Reoccur at %s', $result->toDateTimeString()))
                ->setNextRun($result);
        }

        if ($result instanceof PausedException) {
            return new Pause('Execution paused');
        }

        if ($result instanceof Error || $result instanceof NonRetryableException) {
            return Failed::create($result->getMessage())
                ->willRetry()
                ->setException($result);
        }

        if ($result instanceof Exception) {
            return Failed::create($result->getMessage())
                ->willRetry()
                ->setException($result);
        }

        return new Success((string)$result);
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job
     * @param \DelayedJobs\Result\ResultInterface $result The result of the job
     * @param int $duration Duration of the job
     * @return void
     */
    protected function _handleResult(Job $job, ResultInterface $result, int $duration): void
    {
        $context = ['enqueuedJobs' => $this->_enqueuedJobs];

        if ($result instanceof Failed && $result->getException() !== null) {
            $exception = $result->getException();
            $context['className'] = get_class($exception);
            $context['code'] = $exception->getCode();
            $context['trace'] = explode("\n", $exception->getTraceAsString());
        }

        $job->setStatus($result->getStatus())
            ->setEndTime(Time::now())
            ->setDuration($duration)
            ->addHistory($result->getMessage(), $context);

        if (
            $job->getStatus() === Job::STATUS_FAILED &&
            $result->getRetry() &&
            $job->getRetries() < $job->getMaxRetries()
        ) {
            $job->incrementRetries();

            $retryTime = $result->getNextRun();
            if ($retryTime === null) {
                $retryTime = $this->_calculateRetryTime($job->getRetries());
            }
            $job->setRunAt($retryTime);
            $this->enqueue($job);

            return;
        } elseif ($job->getStatus() === Job::STATUS_FAILED) {
            $job->setStatus(Job::STATUS_BURIED);
        }

        $this->_persistToDatastore($job);

        if ($result->getNextRun() !== null) {
            $this->_enqueueRecurring($job, $result->getNextRun());
        }

        if ($job->getSequence() !== null && \in_array($job->getStatus(), [Job::STATUS_SUCCESS, Job::STATUS_BURIED])) {
            $this->enqueueNextSequence($job);
        }
    }

    /**
     * @param \DelayedJobs\Worker\JobWorkerInterface $jobWorker Job worker
     * @param string $name Event name
     * @param array|null $data Data for the event
     * @param mixed|null $subject Event subject (If not set, then the subject will be the job manager)
     * @return \Cake\Event\EventInterface
     */
    protected function _dispatchWorkerEvent(
        JobWorkerInterface $jobWorker,
        $name,
        ?array $data = null,
        $subject = null
    ): EventInterface {
        $event = new Event($name, $subject ?? $this, $data);
        try {
            $this->getEventManager()->dispatch($event);
            if ($jobWorker instanceof EventDispatcherInterface) {
                $jobWorker->getEventManager()->dispatch($event);
            }
        } catch (Throwable $e) {
            //Ignore any issues in worker events
        }

        return $event;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to run
     * @param \DelayedJobs\Worker\JobWorkerInterface $jobWorker Worker to run
     * @return \DelayedJobs\Result\ResultInterface|null
     */
    protected function _executeJob(Job $job, JobWorkerInterface $jobWorker): ?ResultInterface
    {
        $this->lock($job);

        $this->_currentJob = $job;

        $event = $this->_dispatchWorkerEvent($jobWorker, 'DelayedJob.beforeJobExecute', [$job]);
        if ($event->isStopped()) {
            return null;
        }

        $event = null;
        $result = false;
        $start = microtime(true);
        try {
            $result = $jobWorker($job);
        } catch (Error $error) {
            //## Job Failed badly
            $result = $error;
            Log::emergency(sprintf(
                "Delayed job %d failed due to a fatal PHP error.\n%s\n%s",
                $job->getId(),
                $error->getMessage(),
                $error->getTraceAsString()
            ));
        } catch (Exception $exc) {
            //## Job Failed
            $result = $exc;
        } finally {
            $this->getMessageBroker()
                ->acknowledge($job);

            if (!$result instanceof ResultInterface) {
                $result = $this->_buildResultObject($job, $result);
            }

            $duration = (int)ceil(round((microtime(true) - $start) * 1000)); //Duration in milliseconds
            $this->_dispatchWorkerEvent($jobWorker, 'DelayedJob.afterJobExecute', [$job, $result, $duration]);

            $this->_handleResult($job, $result, $duration);

            $this->_dispatchWorkerEvent($jobWorker, 'DelayedJob.afterJobCompleted', [$job, $result]);

            $this->_currentJob = null;
            $this->_enqueuedJobs = [];

            return $result;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Job $job, bool $force = false): ?\DelayedJobs\Result\ResultInterface
    {
        $className = App::className($job->getWorker(), 'Worker', 'Worker');

        if (!$className || !class_exists($className)) {
            throw new JobExecuteException("Worker does not exist ({$className})");
        }

        $this->djLog(__('Received job {0}.', $job->getId()));

        if (
            $force === false &&
            ($job->getStatus() === Job::STATUS_SUCCESS || $job->getStatus() === Job::STATUS_BURIED)
        ) {
            $this->djLog(__('Job {0} has already been processed', $job->getId()));
            $this->getMessageBroker()
                ->acknowledge($job);

            return null;
        }

        if ($force === false && $job->getStatus() === Job::STATUS_BUSY) {
            $this->djLog(__('Job {0} is already being processed', $job->getId()));
            $this->getMessageBroker()
                ->acknowledge($job);

            return null;
        }

        $jobWorker = new $className($job);

        if (!$jobWorker instanceof JobWorkerInterface) {
            Log::emergency("Worker class {$className} for job {$job->getId()} must be an instance of " .
                JobWorkerInterface::class);
            $this->getMessageBroker()
                ->acknowledge($job);

            return null;
        }

        return $this->_executeJob($job, $jobWorker);
    }

    /**
     * {@inheritDoc}
     */
    public function enqueueNextSequence(Job $job): void
    {
        $nextJob = $this->getDatasource()
            ->fetchNextSequence($job);

        if ($nextJob) {
            $this->_pushToBroker($nextJob);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isSimilarJob(Job $job): bool
    {
        return $this->getDatasource()->isSimilarJob($job);
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job instance
     * @param mixed $message Message to add to history
     *
     * @return \DelayedJobs\DelayedJob\Job
     */
    public function addHistoryAndPersist(Job $job, $message): Job
    {
        $job->addHistory($message, [], false);

        return $this->_persistToDatastore($job);
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job being persisted
     * @return \DelayedJobs\DelayedJob\Job
     */
    protected function _persistToDatastore(Job $job): Job
    {
        $event = $this->dispatchEvent('DelayedJobs.beforePersist', [$job]);
        if ($event->isStopped()) {
            return $job;
        }

        if (!$job->getId()) {
            $job
                ->setHostName($this->getHostname())
                ->addHistory('Created', [
                    'parentJob' => $this->_currentJob ? $this->_currentJob->getId() : null,
                ]);
        }

        if (!$this->getDatasource()->persistJob($job)) {
            throw new EnqueueException('Job could not be persisted');
        }

        $this->dispatchEvent('DelayedJobs.afterPersist', [$job]);

        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job being pushed to broker
     * @return void
     */
    protected function _pushToBroker(Job $job)
    {
        if ($job->getId() === null) {
            throw new EnqueueException('Job has not been persisted.');
        }

        $event = $this->dispatchEvent('DelayedJobs.beforeJobQueue', [$job]);
        if ($event->isStopped()) {
            return;
        }

        try {
            $this->getMessageBroker()->publishJob($job);
            $this->addHistoryAndPersist($job, 'Pushed to broker');
        } catch (Exception $e) {
            $this->addHistoryAndPersist($job, $e);
            Log::emergency(__(
                'Could not push job to broker. Response was: {0} with exception {1}. ' .
                'Job #{2} has not been queued. Hostname: {3}, Current job: {4}',
                $e->getMessage(),
                get_class($e),
                $job->getId(),
                gethostname(),
                $this->_currentJob ? $this->_currentJob->getId() : null
            ));

            throw new EnqueueException('Could not push job to broker.');
        }

        $this->dispatchEvent('DelayedJobs.afterJobQueue', [$job]);
    }

    /**
     * {@inheritDoc}
     */
    public function startConsuming(): void
    {
        $this->consuming = true;
        //This lambda is run for each message received from the broker
        $this->getMessageBroker()
            ->consume(function (Job $job, $retried = false) {
                try {
                    $this->loadJob($job); //Load the job data from the database
                } catch (Exception $e) {
                    //If there was a failure with loading the job, we either requeue the job, or we assume it's missing
                    $this->djLog($e->getMessage());

                    if ($retried) {
                        $this->djLog(__(
                            'Failed to load job {0} even after retrying.  Message was: {1}',
                            $job->getId(),
                            $e->getMessage()
                        ));
                        $this->getMessageBroker()
                            ->negativeAcknowledge($job);

                        return;
                    }

                    $this->djLog(__('Will retry job {0}', $job->getId()));

                    // Sleep 1s before requeuing the job... sometimes the broker (mostly RabbitMQ) is just too fast.
                    sleep(1);

                    $this->requeueJob($job);

                    return;
                }

                $this->execute($job); //Execute the job
            }, function () {
                $this->dispatchEvent('DelayedJob.heartbeat');
            });
    }

    /**
     * {@inheritDoc}
     */
    public function stopConsuming(): void
    {
        $this->consuming = false;

        $this->getMessageBroker()
            ->stopConsuming();
    }

    /**
     * {@inheritDoc}
     */
    public function isConsuming(): bool
    {
        return $this->consuming;
    }

    /**
     * {@inheritDoc}
     */
    public function requeueJob(Job $job): void
    {
        $this->getMessageBroker()
            ->negativeAcknowledge($job, true);
    }

    /**
     * @return int
     */
    public function getMaximumPriority(): int
    {
        return (int)$this->getConfig('maximum.priority');
    }
}
