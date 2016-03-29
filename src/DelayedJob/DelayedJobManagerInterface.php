<?php

namespace DelayedJobs\DelayedJob;

use Cake\Console\Shell;
use DelayedJobs\DelayedJob\DelayedJob;

/**
 * Interface DelayedJobManagerInterface
 */
interface DelayedJobManagerInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job
     * @return \DelayedJobs\DelayedJob\DelayedJob|bool
     */
    public function enqueue(DelayedJob $job);

    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job that failed
     * @param string $message Message to store with the jbo
     * @param bool $burryJob Should the job be burried
     * @return bool|\DelayedJobs\DelayedJob\DelayedJob
     */
    public function failed(DelayedJob $job, $message, $burryJob = false);

    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job that has been completed
     * @param string|null $message Message to store with job
     * @param int $duration How long execution took
     * @return \DelayedJobs\DelayedJob\DelayedJob|bool
     */
    public function completed(DelayedJob $job, $message = null, $duration = 0);

    /**
     * Gets the Job instance for a specific job
     *
     * @param int $jobId Job to fetch
     * @return \DelayedJobs\DelayedJob\DelayedJob
     * @throws \DelayedJobs\DelayedJob\Exception\JobNotFoundException
     */
    public function fetchJob($jobId);

    /**
     * Gets the current status for a requested job
     *
     * @param int $jobId Job to get status for
     * @return int
     */
    public function getStatus($jobId);

    public function lock(DelayedJob $job, $hostname = null);

    public function execute(DelayedJob $job, Shell $shell = null);

    public function enqueueNextSequence(DelayedJob $job);

    public function isSimilarJob(DelayedJob $job);
}
