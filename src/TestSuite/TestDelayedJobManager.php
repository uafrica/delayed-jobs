<?php

namespace DelayedJobs\TestSuite;

use Cake\Utility\Text;
use Cake\Console\Shell;
use DelayedJobs\DelayedJob\DelayedJobManagerInterface;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\DelayedJob\DelayedJob;

/**
 * Class TestDelayedJobManager
 */
class TestDelayedJobManager implements DelayedJobManagerInterface
{
    protected $_jobs = [];

    public function getJobs()
    {
        return $this->_jobs;
    }

    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job
     * @return \DelayedJobs\DelayedJob\DelayedJob|bool
     */
    public function enqueue(DelayedJob $job)
    {
        $jobId = time() + mt_rand(0, time());
        $job->setId($jobId);
        $this->_jobs[$jobId] = $job;

        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job that failed
     * @param string $message Message to store with the jbo
     * @param bool $burryJob Should the job be burried
     * @return bool|\DelayedJobs\DelayedJob\DelayedJob
     */
    public function failed(DelayedJob $job, $message, $burryJob = false)
    {
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
        return $job;
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
        if (isset($this->_jobs[$jobId])) {
            return $this->_jobs[$jobId];
        } else {
            throw new JobNotFoundException();
        }
    }

    /**
     * Gets the current status for a requested job
     *
     * @param int $jobId Job to get status for
     * @return int
     */
    public function getStatus($jobId)
    {
        return $this->fetchJob($jobId)->getStatus();
    }

    public function lock(DelayedJob $job, $hostname = null)
    {
        return $job;
    }

    public function execute(DelayedJob $job, Shell $shell = null)
    {
        return $job;
    }

    public function enqueueNextSequence(DelayedJob $job)
    {
        return null;
    }

    public function isSimilarJob(DelayedJob $job)
    {
        return false;
    }

}
