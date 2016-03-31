<?php

namespace DelayedJobs\TestSuite;

use Cake\Utility\Text;
use Cake\Console\Shell;
use DelayedJobs\DelayedJob\ManagerInterface;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\DelayedJob\Job;

/**
 * Class TestDelayedJobManager
 */
class TestManager implements ManagerInterface
{
    protected static $_jobs = [];

    /**
     * @return array
     */
    public static function getJobs()
    {
        return self::$_jobs;
    }

    /**
     * @return void
     */
    public static function clearJobs()
    {
        self::$_jobs = [];
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return \DelayedJobs\DelayedJob\Job|bool
     */
    public function enqueue(Job $job)
    {
        $jobId = time() + mt_rand(0, time());
        $job->setId($jobId);
        self::$_jobs[$jobId] = $job;

        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job that failed
     * @param string $message Message to store with the jbo
     * @param bool $burryJob Should the job be burried
     * @return bool|\DelayedJobs\DelayedJob\Job
     */
    public function failed(Job $job, $message, $burryJob = false)
    {
        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job that has been completed
     * @param string|null $message Message to store with job
     * @param int $duration How long execution took
     * @return \DelayedJobs\DelayedJob\Job|bool
     */
    public function completed(Job $job, $message = null, $duration = 0)
    {
        return $job;
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
        if (isset(self::$_jobs[$jobId])) {
            return self::$_jobs[$jobId];
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

    public function lock(Job $job, $hostname = null)
    {
        return $job;
    }

    public function execute(Job $job, Shell $shell = null)
    {
        return $job;
    }

    public function enqueueNextSequence(Job $job)
    {
        return null;
    }

    public function isSimilarJob(Job $job)
    {
        return false;
    }

}
