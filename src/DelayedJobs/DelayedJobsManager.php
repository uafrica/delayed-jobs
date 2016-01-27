<?php

namespace DelayedJobs\DelayedJobs;

use Cake\ORM\TableRegistry;
use DelayedJobs\DelayedJobs\Exception\EnqueueException;

/**
 * Class DelayedJobsManager
 */
class DelayedJobsManager
{
    /**
     * The singleton instance
     *
     * @var \DelayedJobs\DelayedJobs\DelayedJobsManager
     */
    protected static $_instance = null;

    /**
     * @var \DelayedJobs\Model\Table\DelayedJobsTable
     */
    protected $_delayedTable = null;

    /**
     * Constructor for class
     */
    public function __construct()
    {
        $this->_delayedTable = TableRegistry::get('DelayedJobs.DelayedJobs');
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
     * @return bool
     */
    public function enqueueJob(Job $job)
    {
        $job_data = $job->getData();

        $job_entity = $this->DelayedJobs->newEntity($job_data, [
            'validate' => 'manager'
        ]);
        $job_entity->status = DelayedJobsTable::STATUS_NEW;

        $options = [
            'atomic' => !$this->_delayedTable->connection()->inTransaction()
        ];

        $result = $this->_delayedTable->save($job_entity, $options);

        if (!$result) {
            throw new EnqueueException($job_entity->errors());
        }

        return true;
    }
}
