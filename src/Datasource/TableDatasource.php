<?php

namespace DelayedJobs\Datasource;

use Cake\ORM\TableRegistry;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\DelayedJob\Job;

/**
 * Class DatabaseSource
 */
class TableDatasource extends BaseDatasource
{
    protected $_defaultConfig = [
        'tableName' => 'DelayedJobs.DelayedJobs'
    ];

    /**
     * @return \DelayedJobs\Model\Table\DelayedJobsTable
     */
    protected function _table()
    {
        return TableRegistry::get($this->config('tableName'));
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to persist
     * @return \DelayedJobs\DelayedJob\Job|bool
     */
    public function persistJob(Job $job)
    {
        return $this->_table()->persistJob($job);
    }

    public function persistJobs(array $jobs)
    {
        return $this->_table()->persistJobs($jobs);
    }

    /**
     * @param int $jobId The job to get
     * @return \DelayedJobs\DelayedJob\Job|null
     */
    public function fetchJob($jobId)
    {
        $job = $this->_table()->fetchJob($jobId);

        if (!$job) {
            throw new JobNotFoundException(sprintf('Job with id "%s" does not exist in the datastore.', $jobId));
        }

        return $job;
    }

    /**
     * Returns true if a job of the same sequence is already persisted and waiting execution.
     *
     * @param \DelayedJobs\DelayedJob\Job $job The job to check for
     * @return bool
     */
    public function currentlySequenced(Job $job)
    {
        return $this->_table()->currentlySequenced($job);
    }

    /**
     * Gets the next job in the sequence
     *
     * @param \DelayedJobs\DelayedJob\Job $job Job to get next sequence for
     * @return \DelayedJobs\DelayedJob\Job|null
     */
    public function fetchNextSequence(Job $job)
    {
        return $this->_table()->fetchNextSequence($job);
    }

    /**
     * Checks if there already is a job with the same class waiting
     *
     * @param \DelayedJobs\DelayedJob\Job $job Job to check
     * @return bool
     */
    public function isSimilarJob(Job $job)
    {
        return $this->_table()->isSimilarJob($job);
    }

    public function loadJob(Job $job)
    {
        $jobEntity = $this->_table()->find()
            ->where(['id' => $job->getId()])
            ->first();

        if ($jobEntity === null) {
            throw new JobNotFoundException(sprintf('Job with id "%s" does not exist in the datastore.', $job->getId()));
        }

        return $job
            ->setDataFromEntity($jobEntity);
    }
}
