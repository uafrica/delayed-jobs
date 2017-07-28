<?php

namespace DelayedJobs\DelayedJob;

use Cake\Console\Shell;
use DelayedJobs\DelayedJob\Job;

/**
 * Interface DelayedJobManagerInterface
 */
interface ManagerInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return \DelayedJobs\DelayedJob\Job|bool
     */
    public function enqueue(Job $job);

    /**
     * @param int $id The ID to enqueue
     * @param int $priority The priority of the job
     * @return bool
     */
    public function enqueuePersisted($id, $priority);

    /**
     * Enqueues a batch of jobs
     *
     * @param \DelayedJobs\DelayedJob\Job[] $jobs Array of jobs to enqueue
     * @return bool
     */
    public function enqueueBatch(array $jobs);

    /**
     * Gets the Job instance for a specific job
     *
     * @param int $jobId Job to fetch
     * @return \DelayedJobs\DelayedJob\Job
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

    public function lock(Job $job);

    public function execute(Job $job, $force);

    public function enqueueNextSequence(Job $job);

    public function isSimilarJob(Job $job);

    public function startConsuming();

    public function stopConsuming();

    public function requeueJob(Job $job);
}
