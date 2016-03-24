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
use Cake\ORM\TableRegistry;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\DelayedJob\Exception\EnqueueException;
use DelayedJobs\DelayedJob\Exception\JobExecuteException;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\Worker\JobWorkerInterface;

/**
 * Class DelayedJobsManager
 */
class DelayedJobManager implements EventDispatcherInterface
{
    use EventDispatcherTrait;
    use InstanceConfigTrait;

    /**
     * The singleton instance
     *
     * @var \DelayedJobs\DelayedJob\DelayedJobManager
     */
    protected static $_instance = null;

    /**
     * @var DelayedJobDatastoreInterface
     */
    protected $_jobDatastore = null;

    protected $_defaultConfig = [];

    /**
     * Constructor for class
     *
     * @param \Cake\Datasource\RepositoryInterface $jobDatastore
     */
    public function __construct(DelayedJobDatastoreInterface $jobDatastore = null, array $config = [])
    {
        if ($jobDatastore === null) {
            $this->_jobDatastore = TableRegistry::get('DelayedJobs.DelayedJobs');
        } else {
            $this->_jobDatastore = $jobDatastore;
        }

        $this->config($config);
    }

    /**
     * Returns the globally available instance of a \DelayedJobs\DelayedJobs\DelayedJobsManager
     *
     * If called with the first parameter, it will be set as the globally available instance
     *
     * @param \DelayedJobs\DelayedJob\DelayedJobManager $manager Delayed jobs instance.
     * @return \DelayedJobs\DelayedJob\DelayedJobManager the global delayed jobs manager
     */
    public static function instance(DelayedJobManager $manager = null)
    {
        if ($manager instanceof DelayedJobManager) {
            static::$_instance = $manager;
        }
        if (empty(static::$_instance)) {
            static::$_instance = new DelayedJobManager();
        }

        return static::$_instance;
    }

    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job
     * @return \DelayedJobs\DelayedJob\DelayedJob|bool
     */
    public function enqueue(DelayedJob $job)
    {
        if ($this->_persistToDatastore($job)) {
            if ($job->getSequence() && $this->_jobDatastore->currentlySequenced($job)) {
                return true;
            }
            return $this->_pushToBroker($job);
        }

        return false;
    }

    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job that failed
     * @param string $message Message to store with the jbo
     * @param bool $burryJob Should the job be burried
     * @return bool|\DelayedJobs\DelayedJob\DelayedJob
     */
    public function failed(DelayedJob $job, $message, $burryJob = false)
    {
        $maxRetries = $job->getMaxRetries();
        $job->incrementRetries();

        $status = ($burryJob === true || $job->getRetries() >= $maxRetries) ? DelayedJob::STATUS_BURRIED : DelayedJob::STATUS_FAILED;

        $growthFactor = 5 + $job->getRetries() ** 4;

        $growthFactorRandom = mt_rand(0, 100) % 2 ? -1 : +1;
        $growthFactorRandom = $growthFactorRandom * ceil(\log($growthFactor + mt_rand(0, $growthFactor)));

        $growthFactor += $growthFactorRandom;

        $job->setStatus($status)
            ->setRunAt(new Time("+{$growthFactor} seconds"))
            ->setLastMessage($message)
            ->setTimeFailed(new Time());

        if ($job->getStatus() === DelayedJob::STATUS_FAILED) {
            return $this->enqueue($job);
        } elseif ($job->getSequence() !== null) {
            $this->enqueueNextSequence($job);
        } else {
            $this->_persistToDatastore($job);
        }

        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job that has been completed
     * @param string|null $message Message to store with job
     * @param int $duration How long execution took
     * @return \DelayedJobs\DelayedJob\DelayedJob|bool
     */
    public function completed(DelayedJob $job, $message = null, $duration = 0)
    {
        if ($message) {
            $job->setLastMessage($message);
        }
        $job
            ->setStatus(DelayedJob::STATUS_SUCCESS)
            ->setEndTime(new Time())
            ->setDuration($duration);

        return $this->_persistToDatastore($job);
    }

    /**
     * Gets the Job instance for a specific job
     *
     * @param int $jobId Job to fetch
     * @return \DelayedJobs\DelayedJob\DelayedJob
     * @throws \DelayedJobs\DelayedJob\Exception\JobNotFoundException
     */
    public function fetchJob($jobId)
    {
        $job = $this->_jobDatastore->fetchJob($jobId);

        if (!$job) {
            throw new JobNotFoundException(sprintf('Job with id "%s" does not exist in the datastore.', $jobId));
        }

        return $job;
    }

    /**
     * Gets the current status for a requested job
     *
     * @param int $jobId Job to get status for
     * @return int
     */
    public function getStatus($jobId)
    {
        $job = $this->_jobDatastore->fetchJob($jobId);
        if (!$job) {
            return DelayedJob::STATUS_UNKNOWN;
        }

        return $job->getStatus();
    }

    public function lock(DelayedJob $job, $hostname = null)
    {
        $job->setStatus(DelayedJob::STATUS_BUSY)
            ->setStartTime(new Time())
            ->setHostName($hostname);

        return $this->_persistToDatastore($job);
    }

    public function execute(DelayedJob $job, Shell $shell = null)
    {
        $className = App::className($job->getClass(), 'Worker', 'Worker');

        if (!class_exists($className)) {
            throw new JobExecuteException("Worker does not exist (" . $className . ")");
        }

        $jobWorker = new $className();

        if (!$jobWorker instanceof JobWorkerInterface) {
            throw new JobExecuteException("Worker class '{$className}' does not follow the required 'JobWorkerInterface");
        }

        $event = $this->dispatchEvent('DelayedJob.beforeJobExecute', [$job]);
        if ($event->isStopped()) {
            return $event->result;
        }

        try {
            $result = $jobWorker($job, $shell);
        } catch (NonRetryableException $exc) {
            //Special case where something failed, but we still want to treat it as a 'success'.
            $result = $exc->getMessage();
        }

        $event = $this->dispatchEvent('DelayedJob.afterJobExecute', [$job, $result]);

        return $event->result ? $event->result : $result;
    }

    public function enqueueNextSequence(DelayedJob $job)
    {
        $this->_persistToDatastore($job);
        $nextJob = $this->_jobDatastore->fetchNextSequence($job);

        return $this->_pushToBroker($nextJob);
    }

    public function isSimilarJob(DelayedJob $job)
    {
        return $this->_jobDatastore->isSimilarJob($job);
    }

    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job being persisted
     * @return \DelayedJobs\DelayedJob\DelayedJob|mixed
     */
    protected function _persistToDatastore(DelayedJob $job)
    {
        $event = $this->dispatchEvent('DelayedJobs.beforePersist', [$job]);
        if ($event->isStopped()) {
            return $event->result;
        }

        if (!$this->_jobDatastore->persistJob($job)) {
            throw new EnqueueException('Job could not be persisted');
        }

        $this->dispatchEvent('DelayedJobs.afterPersist', [$job]);

        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job being pushed to broker
     * @return bool|mixed
     */
    protected function _pushToBroker(DelayedJob $job)
    {
        if ($job->getId() === null) {
            throw new EnqueueException('Job has not been persisted.');
        }

        if (Configure::read('dj.service.rabbit.disable') === true) {
            return true;
        }

        try {
            $event = $this->dispatchEvent('DelayedJobs.beforeJobQueue', [$job]);
            if ($event->isStopped()) {
                return $event->result;
            }

            //TODO: Move to an engine like interface
            $manager = AmqpManager::instance();
            $message = $manager->queueJob($job);

            $this->dispatchEvent('DelayedJobs.afterJobQueue', [$job, $message]);

            return true;
        } catch (\Exception $e) {
            Log::emergency(__('RabbitMQ server is down. Response was: {0} with exception {1}. Job #{2} has not been queued.',
                $e->getMessage(), get_class($e), $job->getId()));

            throw new EnqueueException('Could not push job to broker.');
        }
    }
}
