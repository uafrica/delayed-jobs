<?php

namespace DelayedJobs\DelayedJob;

use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Event\Event;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\I18n\Time;
use Cake\Log\Log;
use DelayedJobs\Broker\BrokerInterface;
use DelayedJobs\Broker\RabbitMqBroker;
use DelayedJobs\Datasource\DatasourceInterface;
use DelayedJobs\Datasource\TableDatasource;
use DelayedJobs\DelayedJob\Exception\EnqueueException;
use DelayedJobs\DelayedJob\Exception\JobExecuteException;
use DelayedJobs\Exception\NonRetryableException;
use DelayedJobs\Result\Failed;
use DelayedJobs\Result\ResultInterface;
use DelayedJobs\Result\Success;
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

    /**
     * The basic retry time in seconds
     */
    const BASE_RETRY_TIME = 5;
    /**
     * Factor to apply to retries
     */
    const RETRY_FACTOR = 4;

    /**
     * The singleton instance
     *
     * @var \DelayedJobs\DelayedJob\JobManager
     */
    protected static $_instance;

    /**
     * @var \DelayedJobs\Datasource\DatasourceInterface
     */
    protected $_datastore;

    /**
     * @var array
     */
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
     * @param \DelayedJobs\Broker\BrokerInterface $messageBroker Broker that handles message distribution
     */
    public function __construct(array $config = [], DatasourceInterface $datastore = null, BrokerInterface $messageBroker = null) {
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
     * @param bool $skipPersist Skip the persistance step (e.g. it's already been persisted
     * @return void
     */
    public function enqueue(Job $job, bool $skipPersist = false)
    {
        if ($skipPersist || $this->_persistToDatastore($job)) {
            if ($job->getSequence() && $this->getDatasource()->currentlySequenced($job)) {
                return;
            }
            $this->_pushToBroker($job);
        }
    }

    /**
     * @param int $id The ID to enqueue
     * @param int $priority The priority of the job
     * @return void
     */
    public function enqueuePersisted($id, $priority)
    {
        $job = new Job(compact('id', 'priority'));

        $this->getMessageBroker()->publishJob($job);
    }

    /**
     * @param array $jobs
     * @return void
     */
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
    }

    /**
     * @param $retryCount
     * @return \Cake\I18n\Time
     */
    protected function _calculateRetryTime($retryCount): Time
    {
        if ($this->config('DelayedJobs.retryTimes.' . $retryCount)) {
            $growthFactor = $this->config('DelayedJobs.retryTimes.' . $retryCount);
        } else {
            $growthFactor = static::BASE_RETRY_TIME + $retryCount ** static::RETRY_FACTOR;
        }

        $growthFactorRandom = random_int(1, 2) === 2 ? -1 : +1;
        $growthFactorRandom *= ceil(\log($growthFactor + random_int($growthFactor / 2, $growthFactor)));

        $growthFactor += $growthFactorRandom;

        return new Time("+{$growthFactor} seconds");
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @param \DateTimeInterface $result
     * @return void
     */
    protected function _enqueueRecurring(Job $job, \DateTimeInterface $result)
    {
        //Recurring job
        if ($this->isSimilarJob($job)) {
            return;
        }

        $recurringJob = clone $job;
        $recurringJob->setRunAt($result);
        $this->enqueue($recurringJob);
    }

    /**
     * Gets the Job instance for a specific job
     *
     * @param int $jobId Job to fetch
     * @return \DelayedJobs\DelayedJob\Job
     * @throws \DelayedJobs\DelayedJob\Exception\JobNotFoundException
     */
    public function fetchJob($jobId): Job
    {
        return $this->getDatasource()->fetchJob($jobId);
    }

    /**
     * Populates an existing job object with datasource data
     *
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return \DelayedJobs\DelayedJob\Job
     */
    public function loadJob(Job $job): Job
    {
        $this->getDatasource()->loadJob($job);

        if ($job->getBrokerMessageBody()) {
            $job->setPayloadKey('brokerMessageBody', $job->getBrokerMessageBody(), false);//If there is a message body, ensure that it's not lost on a retry!
        }

        return $job;
    }

    /**
     * Gets the current status for a requested jobid
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

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return \DelayedJobs\DelayedJob\Job|mixed
     */
    public function lock(Job $job)
    {
        $job->setStatus(Job::STATUS_BUSY)
            ->setStartTime(Time::now());

        return $this->_persistToDatastore($job);
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @param $result
     * @return \DelayedJobs\Result\ResultInterface
     */
    protected function _buildResultObject(Job $job, $result): ResultInterface
    {
        if ($result instanceof ResultInterface) {
            return $result;
        } elseif ($result instanceof \DateTimeInterface) {
            return (new Success($job, "Reoccur at {$result}"))->willRecur($result);
        } elseif ($result instanceof \Error || $result instanceof NonRetryableException) {
            return (new Failed($job, $result->getMessage()))
                ->willRetry(false)
                ->setException($result);
        } elseif ($result instanceof \Exception) {
            return (new Failed($job, $result->getMessage()))
                ->willRetry($job->getRetries() < $job->getMaxRetries())
                ->setException($result);
        }

        return new Success($job, $result);
    }

    /**
     * @param \DelayedJobs\Result\ResultInterface $result
     * @param $duration
     * @return void
     */
    protected function _handleResult(ResultInterface $result, $duration)
    {
        $job = $result->getJob();

        $job->setStatus($result->getStatus())
            ->setEndTime(Time::now())
            ->setDuration($duration)
            ->addHistory($result->getMessage());

        if ($result->canRetry()) {
            $job->incrementRetries()
                ->setRunAt($this->_calculateRetryTime($job->getRetries()));
            $this->enqueue($job);

            return;
        }

        if ($result->getRecur()) {
            $this->_enqueueRecurring($job, $result->getRecur());
        }

        if ($job->getSequence() !== null && in_array($job->getStatus(), [Job::STATUS_SUCCESS, Job::STATUS_BURIED])) {
            $this->enqueueNextSequence($job);
        }

        $this->_persistToDatastore($job);
    }

    /**
     * @param \DelayedJobs\Worker\JobWorkerInterface $jobWorker
     * @param $name
     * @param null $data
     * @param null $subject
     * @return \Cake\Event\Event
     */
    protected function _dispatchWorkerEvent(JobWorkerInterface $jobWorker, $name, $data = null, $subject = null): Event
    {
        $event = new Event($name, $subject ?? $this, $data);
        $this->eventManager()->dispatch($event);
        if ($jobWorker instanceof EventDispatcherInterface) {
            $jobWorker->eventManager()->dispatch($event);
        }

        return $event;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @param \DelayedJobs\Worker\JobWorkerInterface $jobWorker
     * @return void
     */
    protected function _executeJob(Job $job, JobWorkerInterface $jobWorker)
    {
        $event = $this->_dispatchWorkerEvent($jobWorker, 'DelayedJob.beforeJobExecute', [$job]);
        if ($event->isStopped()) {
            return;
        }

        $event = null;
        $result = false;
        $start = microtime(true);
        try {
            $result = $jobWorker($job);
        } catch (\Error $error) {
            //## Job Failed badly
            $result = $error;
            Log::emergency(sprintf(
                "Delayed job %d failed due to a fatal PHP error.\n%s\n%s",
                $job->getId(),
                $error->getMessage(),
                $error->getTraceAsString()
            ));
        } catch (\Exception $exc) {
            //## Job Failed
            $result = $exc;
        } finally {
            $this->getMessageBroker()->ack($job);

            if (!$result instanceof ResultInterface) {
                $result = $this->_buildResultObject($job, $result);
            }

            $duration = round((microtime(true) - $start) * 1000); //Duration in milliseconds
            $this->_dispatchWorkerEvent($jobWorker, 'DelayedJob.afterJobExecute', [$result, $duration]);

            $this->_handleResult($result, $duration);

            $this->_dispatchWorkerEvent($jobWorker, 'DelayedJob.afterJobCompleted', [$result]);
        }
    }

    public function execute(Job $job, $force = false)
    {
        $className = App::className($job->getWorker(), 'Worker', 'Worker');

        if (!class_exists($className)) {
            throw new JobExecuteException("Worker does not exist (" . $className . ")");
        }

        $this->djLog(__('Received job {0}.', $job->getId()));

        if ($force === false && ($job->getStatus() === Job::STATUS_SUCCESS || $job->getStatus() === Job::STATUS_BURIED)) {
            $this->djLog(__('Job {0} has already been processed', $job->getId()));
            $this->getMessageBroker()->ack($job);

            return;
        }

        if ($force === false && $job->getStatus() === Job::STATUS_BUSY) {
            $this->djLog(__('Job {0} is already being processed', $job->getId()));
            $this->getMessageBroker()->ack($job);

            return;
        }
        $this->lock($job);

        $jobWorker = new $className();

        if (!$jobWorker instanceof JobWorkerInterface) {
            Log::emergency("Worker class {$className} for job {$job->getId()} must be an instance of " . JobWorkerInterface::class);
            $this->getMessageBroker()->ack($job);
            return;
        }

        $this->_executeJob($job, $jobWorker);
    }

    public function enqueueNextSequence(Job $job)
    {
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
     * @return void
     */
    protected function _pushToBroker(Job $job)
    {
        if ($job->getId() === null) {
            throw new EnqueueException('Job has not been persisted.');
        }

        try {
            $event = $this->dispatchEvent('DelayedJobs.beforeJobQueue', [$job]);
            if ($event->isStopped()) {
                return;
            }

            $this->getMessageBroker()->publishJob($job);

            $this->dispatchEvent('DelayedJobs.afterJobQueue', [$job]);
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
        $this->getMessageBroker()->consume(function (Job $job, $retried = false) {
            try {
                $this->loadJob($job);
            } catch (\Exception $e) {
                $this->djLog($e->getMessage());

                if ($retried) {
                    $this->getMessageBroker()->nack($job);

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
