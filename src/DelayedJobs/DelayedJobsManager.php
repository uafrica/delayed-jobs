<?php

namespace DelayedJobs\DelayedJobs;

use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Datasource\RepositoryInterface;
use Cake\ORM\TableRegistry;
use DelayedJobs\DelayedJobs\Exception\EnqueueException;
use DelayedJobs\Model\Table\DelayedJobsTable;

/**
 * Class DelayedJobsManager
 */
class DelayedJobsManager
{
    const STATUS_NEW = 1;
    const STATUS_BUSY = 2;
    const STATUS_BURRIED = 3;
    const STATUS_SUCCESS = 4;
    const STATUS_KICK = 5;
    const STATUS_FAILED = 6;
    const STATUS_UNKNOWN = 7;
    const STATUS_TEST_JOB = 8;

    /**
     * The singleton instance
     *
     * @var \DelayedJobs\DelayedJobs\DelayedJobsManager
     */
    protected static $_instance = null;

    /**
     * @var \Cake\Datasource\RepositoryInterface
     */
    protected $_jobDatastore = null;

    /**
     * Constructor for class
     *
     * @param \Cake\Datasource\RepositoryInterface $jobDatastore
     */
    public function __construct(RepositoryInterface $jobDatastore = null)
    {
        if ($jobDatastore === null) {
            $this->_jobDatastore = TableRegistry::get('DelayedJobs.DelayedJobs');
        } else {
            $this->_jobDatastore = $jobDatastore;
        }
    }

    /**
     * Returns the globally available instance of a \DelayedJobs\DelayedJobs\DelayedJobsManager
     *
     * If called with the first parameter, it will be set as the globally available instance
     *
     * @param \DelayedJobs\DelayedJobs\DelayedJobsManager $manager Delayed jobs instance.
     * @return \DelayedJobs\DelayedJobs\DelayedJobsManager the global delayed jobs manager
     */
    public static function instance(DelayedJobsManager $manager = null)
    {
        if ($manager instanceof DelayedJobsManager) {
            static::$_instance = $manager;
        }
        if (empty(static::$_instance)) {
            static::$_instance = new DelayedJobsManager();
        }

        return static::$_instance;
    }

    /**
     * @param \DelayedJobs\DelayedJobs\Job $job
     * @return \DelayedJobs\DelayedJobs\Job
     */
    public function enqueueJob(Job $job)
    {
        $job_data = $job->getData();

        $job_entity = $this->_jobDatastore->newEntity($job_data, [
            'validate' => 'manager'
        ]);

        if (!$job_entity->status) {
            $job_entity->status = self::STATUS_NEW;
        }

        $options = [
            'atomic' => !$this->_delayedTable->connection()->inTransaction()
        ];

        $result = $this->_delayedTable->save($job_entity, $options);

        if (!$result) {
            throw new EnqueueException($job_entity->errors());
        }

        $job->setId($job_entity->id);

        return $job;
    }

    /**
     * Gets the Job instance for a specific job
     *
     * @param int $jobId Job to fetch
     * @return \DelayedJobs\DelayedJobs\Job
     * @throws \DelayedJobs\DelayedJobs\JobNotFoundException
     */
    public function fetchJob($jobId)
    {
        try {
            $job_entity = $this->_jobDatastore->get($jobId);
        } catch (RecordNotFoundException $e) {
            throw new JobNotFoundException(sprintf('Job with id "%s" does not exist in the datastore.', $jobId));
        }

        $job = new Job($job_entity->toArray());

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
        try {
            $job_entity = $this->_jobDatastore->get($jobId);
        } catch (RecordNotFoundException $e) {
            return self::STATUS_UNKNOWN;
        }

        return $job_entity->status;
    }
}
