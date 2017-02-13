<?php

namespace DelayedJobs\DelayedJob;

use Cake\Console\Shell;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\I18n\Time;
use Cake\Log\Log;
use DelayedJobs\Broker\PhpAmqpLibBroker;
use DelayedJobs\Broker\RabbitMqBroker;
use DelayedJobs\Datasource\DatasourceInterface;
use DelayedJobs\Datasource\TableDatasource;
use DelayedJobs\DelayedJob\Exception\EnqueueException;
use DelayedJobs\DelayedJob\Exception\JobExecuteException;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\Exception\NonRetryableException;
use DelayedJobs\Traits\DebugLoggerTrait;
use DelayedJobs\Worker\JobWorkerInterface;

/**
 * Manages the persisting and queuing of jobs, job execution and queue consumption
 */
class JobManager implements EventDispatcherInterface, ManagerInterface
{
    use EventDispatcherTrait;
    use InstanceConfigTrait;
    use DebugLoggerTrait;

    const BASE_RETRY_TIME = 5;
    const RETRY_FACTOR = 4;

    /**
     * The singleton instance
     *
     * @var \DelayedJobs\DelayedJob\JobManager
     */
    protected static $_instance = null;

    /**
     * @var \DelayedJobs\Datasource\DatasourceInterface
     */
    protected $_datastore = null;

    protected $_defaultConfig = [
        'maximum' => [
            'priority' => 255
        ],
        'datasource' => [
            'className' => TableDatasource::class
        ],
        'messageBroker' => [
            'className' => RabbitMqBroker::class
        ]
    ];

    /**
     * @var \DelayedJobs\Broker\BrokerInterface
     */
    protected $_messageBroker;

    /**
     * Constructor for class
     *
     * @param array $config Config array
     * @param \DelayedJobs\Datasource\DatasourceInterface $datastore Datastore to inject
     * @param \DelayedJobs\DelayedJob\MessageBrokerInterface $messageBroker Broker that handles message distribution
     */
    public function __construct(array $config = [], DatasourceInterface $datastore = null, MessageBrokerInterface $messageBroker = null) {
        $this->_datastore = $datastore;
        $this->_messageBroker = $messageBroker;

        $this->config($config);
    }

    /**
     * Returns the globally available instance of a \DelayedJobs\DelayedJobs\DelayedJobsManager
     *
     * If called with the first parameter, it will be set as the globally available instance
     *
     * @param \DelayedJobs\DelayedJob\ManagerInterface $manager Delayed jobs instance.
     * @return \DelayedJobs\DelayedJob\JobManager the global delayed jobs manager
     */
    public static function instance(ManagerInterface $manager = null)
    {
        if ($manager instanceof ManagerInterface) {
            static::$_instance = $manager;
        }
        if (empty(static::$_instance)) {
            static::$_instance = new JobManager(Configure::read('DelayedJobs'));
        }

        return static::$_instance;
    }

    /**
     * @return \DelayedJobs\Datasource\DatasourceInterface
     */
    public function getDatasource()
    {
        if ($this->_datastore) {
            return $this->_datastore;
        }

        $config = $this->config('datasource');
        $this->_datastore = new $config['className']($config, $this);

        return $this->_datastore;
    }

    /**
     * @return \DelayedJobs\Broker\BrokerInterface
     */
    public function getMessageBroker()
    {
        if ($this->_messageBroker) {
            return $this->_messageBroker;
        }

        $config = $this->config('messageBroker');
        $this->_messageBroker = new $config['className']($config, $this);

        return $this->_messageBroker;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job that needs to be enqueued
     * @return \DelayedJobs\DelayedJob\Job|bool
     */
    public function enqueue(Job $job)
    {
        if ($this->_persistToDatastore($job)) {
            if ($job->getSequence() && $this->getDatasource()->currentlySequenced($job)) {
                return true;
            }
            return $this->_pushToBroker($job);
        }

        return false;
    }

    /**
     * @param int $id The ID to enqueue
     * @param int $priority The priority of the job
     * @return bool
     */
    public function enqueuePersisted($id, $priority)
    {
        $job = new Job(compact('id', 'priority'));

        return $this->getMessageBroker()
            ->publishJob($job);
    }

    public function enqueueBatch(array $jobs)
    {
        if (!$this->getDatasource()->persistJobs($jobs) ) {
            throw new EnqueueException('Job batch could not be persisted');
        }

        $sequenceMap = [];

        collection($jobs)
            ->filter(function (Job $job) use (&$sequenceMap) {
                $jobSequence = $job->getSequence();
                if (!$jobSequence) {
                    return true;
                }

                if (isset($sequenceMap[$jobSequence])) {
                    return false;
                }

                $currentlySequenced = $this->getDatasource()->currentlySequenced($job);
                $sequenceMap[$jobSequence] = $currentlySequenced;

                return !$currentlySequenced;
            })
            ->each(function (Job $job) {
                $this->_pushToBroker($job);
            });

        return true;
    }

    protected function _calculateRetryTime($retryCount): Time
    {
        if ($this->config('DelayedJobs.retryTimes.' . $retryCount)) {
            $growthFactor = $this->config('DelayedJobs.retryTimes.' . $retryCount);
        } else {
            $growthFactor = static::BASE_RETRY_TIME + $retryCount ** static::RETRY_FACTOR;
        }

        $growthFactorRandom = mt_rand(1, 2) === 2 ? -1 : +1;
        $growthFactorRandom = $growthFactorRandom * ceil(\log($growthFactor + mt_rand($growthFactor / 2, $growthFactor)));

        $growthFactor += $growthFactorRandom;

        return new Time("+{$growthFactor} seconds");
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job that failed
     * @param string|\Throwable $message Message to store with the jbo
     * @param bool $burryJob Should the job be burried
     * @return bool|\DelayedJobs\DelayedJob\Job
     */
    public function failed(Job $job, $message, $burryJob = false)
    {
        $maxRetries = $job->getMaxRetries();
        $job->incrementRetries();

        $status = ($burryJob === true || $job->getRetries() >= $maxRetries) ? Job::STATUS_BURRIED : Job::STATUS_FAILED;

        $job->setStatus($status)
            ->setRunAt($this->_calculateRetryTime($job->getRetries()))
            ->addHistory($message)
            ->setTimeFailed(Time::now());

        if ($job->getStatus() === Job::STATUS_FAILED) {
            $this->enqueue($job);
        } elseif ($job->getSequence() !== null) {
            $this->enqueueNextSequence($job);
        } else {
            $this->_persistToDatastore($job);
        }

        $this->dispatchEvent('DelayedJob.jobFailed', [$job, $message]);

        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job that has been completed
     * @param string|null|\Cake\I18n\Time $result Message to store with job
     * @param int $duration How long execution took
     * @return \DelayedJobs\DelayedJob\Job|bool
     */
    public function completed(Job $job, $result = null, $duration = 0)
    {
        $job
            ->setStatus(Job::STATUS_SUCCESS)
            ->setEndTime(Time::now())
            ->setDuration($duration)
            ->addHistory($result);

        if ($job->getSequence() !== null) {
            $this->enqueueNextSequence($job);
        } else {
            $this->_persistToDatastore($job);
        }

        $event = $this->dispatchEvent('DelayedJob.jobCompleted', [$job, $result]);

        $this->_enqueueFollowup($job, $event->result ? $event->result : $result);

        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @param $result
     * @return void
     */
    protected function _enqueueFollowup(Job $job, $result)
    {
        //Recuring job
        if ($result instanceof \DateTime && !$this->isSimilarJob($job)) {
            $recuringJob = clone $job;
            $recuringJob->setData([
                'runAt' => $result,
                'status' => Job::STATUS_NEW,
                'retries' => 0,
                'lastMessage' => null,
                'failedAt' => null,
                'lockedBy' => null,
                'startTime' => null,
                'endTime' => null,
                'duration' => null,
                'id' => null
            ]);
            $this->enqueue($recuringJob);
        }
    }

    /**
     * Gets the Job instance for a specific job
     *
     * @param int $jobId Job to fetch
     * @return \DelayedJobs\DelayedJob\Job
     * @throws \DelayedJobs\DelayedJob\Exception\JobNotFoundException
     */
    public function fetchJob($jobId)
    {
        $job = $this->getDatasource()->fetchJob($jobId);

        return $job;
    }

    public function loadJob(Job $job)
    {
        return $this->getDatasource()->loadJob($job);
    }

    /**
     * Gets the current status for a requested job
     *
     * @param int $jobId Job to get status for
     * @return int
     */
    public function getStatus($jobId)
    {
        $job = $this->getDatasource()->fetchJob($jobId);
        if (!$job) {
            return Job::STATUS_UNKNOWN;
        }

        return $job->getStatus();
    }

    public function lock(Job $job)
    {
        $job->setStatus(Job::STATUS_BUSY)
            ->setStartTime(Time::now());

        return $this->_persistToDatastore($job);
    }

    public function execute(Job $job, $force = false)
    {
        $className = App::className($job->getWorker(), 'Worker', 'Worker');

        if (!class_exists($className)) {
            throw new JobExecuteException("Worker does not exist (" . $className . ")");
        }

        $jobWorker = new $className();

        if (!$jobWorker instanceof JobWorkerInterface) {
            throw new JobExecuteException("Worker class '{$className}' does not follow the required 'JobWorkerInterface");
        }
        $this->djLog(__('Received job {0}.', $job->getId()));

        $event = $this->dispatchEvent('DelayedJob.beforeJobExecute', [$job]);
        if ($event->isStopped()) {
            //@TODO: Requeue job if queueable job
            return $event->result;
        }

        if ($force === false && ($job->getStatus() === Job::STATUS_SUCCESS || $job->getStatus() === Job::STATUS_BURRIED)) {
            $this->djLog(__('Job {0} has already been processed', $job->getId()));
            $this->getMessageBroker()
                ->ack($job);

            return true;
        }

        if ($force === false && $job->getStatus() === Job::STATUS_BUSY) {
            $this->djLog(__('Job {0} has already being processed', $job->getId()));
            $this->getMessageBroker()
                ->ack($job);

            return true;
        }

        $this->lock($job);

        $event = null;
        $result = false;
        $start = microtime(true);
        try {
            $result = $jobWorker($job);

            $duration = round((microtime(true) - $start) * 1000);
            $this->completed($job, $result, $duration);
        } catch (\Error $error) {
            //## Job Failed badly
            $result = $error;
            $this->failed($job, $error, true);
            Log::emergency(sprintf("Delayed job %d failed due to a fatal PHP error.\n%s\n%s", $job->getId(), $error->getMessage(), $error->getTraceAsString()));
        } catch (\Exception $exc) {
            //## Job Failed
            $result = $exc;
            $this->failed($job, $exc, $exc instanceof NonRetryableException);
        } finally {
            $this->getMessageBroker()->ack($job);
            $duration = $duration ?? round((microtime(true) - $start) * 1000);
            $this->dispatchEvent('DelayedJob.afterJobExecute', [$job, $result, $duration]);

            unset($jobWorker, $job);
        }

        return $result;
    }

    public function enqueueNextSequence(Job $job)
    {
        $this->_persistToDatastore($job);
        $nextJob = $this->getDatasource()->fetchNextSequence($job);

        if ($nextJob) {
            return $this->_pushToBroker($nextJob);
        }
    }

    public function isSimilarJob(Job $job)
    {
        return $this->getDatasource()->isSimilarJob($job);
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job being persisted
     * @return \DelayedJobs\DelayedJob\Job|mixed
     */
    protected function _persistToDatastore(Job $job)
    {
        $event = $this->dispatchEvent('DelayedJobs.beforePersist', [$job]);
        if ($event->isStopped()) {
            return $event->result;
        }

        if (!$this->getDatasource()->persistJob($job)) {
            throw new EnqueueException('Job could not be persisted');
        }

        $this->dispatchEvent('DelayedJobs.afterPersist', [$job]);

        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job being pushed to broker
     * @return bool|mixed
     */
    protected function _pushToBroker(Job $job)
    {
        if ($job->getId() === null) {
            throw new EnqueueException('Job has not been persisted.');
        }

        try {
            $event = $this->dispatchEvent('DelayedJobs.beforeJobQueue', [$job]);
            if ($event->isStopped()) {
                return $event->result;
            }

            $this->getMessageBroker()->publishJob($job);

            $this->dispatchEvent('DelayedJobs.afterJobQueue', [$job]);

            return true;
        } catch (\Exception $e) {
            Log::emergency(__(
                'RabbitMQ server is down. Response was: {0} with exception {1}. Job #{2} has not been queued.',
                $e->getMessage(),
                get_class($e),
                $job->getId()
            ));

            throw new EnqueueException('Could not push job to broker.');
        }
    }

    public function startConsuming()
    {
        $time = microtime(true);
        $this->getMessageBroker()->consume(function (Job $job, $retried = false) use (&$time) {
            try {
                $this->loadJob($job);
            } catch (\Exception $e) {
                $this->djLog($e->getMessage());

                if ($retried) {
                    $this->getMessageBroker()->nack($job, false);

                    return;
                }

                $this->djLog(__('Will retry job {0}', $job->getId()));

                // Sleep 100ms before requeuing the job... sometimes RabbitMQ is just too fast.
                usleep(100 * 1000);

                $this->requeueJob($job);

                return;
            }

            $this->execute($job);
        }, function () {
            $this->dispatchEvent('DelayedJob.heartbeat');
        });
    }

    public function stopConsuming()
    {
        $this->getMessageBroker()->stopConsuming();
    }

    public function requeueJob(Job $job)
    {
        $this->getMessageBroker()->nack($job, true);
    }

}
