<?php
declare(strict_types=1);

namespace DelayedJobs\DelayedJob;

/**
 * Interface DelayedJobManagerInterface
 */
interface ManagerInterface
{
    /**
     * @param string $key
     * @return mixed
     */
    public function getConfig($key);

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return \DelayedJobs\DelayedJob\Job|bool
     */
    public function enqueue(Job $job);

    /**
     * @param int $id The ID to enqueue
     * @param int $priority The priority of the job
     * @return void
     */
    public function enqueuePersisted($id, $priority);

    /**
     * Enqueues a batch of jobs
     *
     * @param \DelayedJobs\DelayedJob\Job[] $jobs Array of jobs to enqueue
     * @return void
     */
    public function enqueueBatch(array $jobs);

    /**
     * Gets the Job instance for a specific job
     *
     * @param int $jobId Job to fetch
     * @return \DelayedJobs\DelayedJob\Job
     */
    public function fetchJob($jobId): Job;

    /**
     * Gets the current status for a requested job
     *
     * @param int $jobId Job to get status for
     * @return int
     */
    public function getStatus($jobId): int;

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return mixed
     */
    public function lock(Job $job);

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @param $force
     * @return \DelayedJobs\Result\ResultInterface|null
     */
    public function execute(Job $job, $force);

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return void
     */
    public function enqueueNextSequence(Job $job);

    /**
     * @internal param \DelayedJobs\DelayedJob\Job $job
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return bool
     */
    public function isSimilarJob(Job $job): bool;

    /**
     * @return void
     */
    public function startConsuming();

    /**
     * @return void
     */
    public function stopConsuming();

    /**
     * @return bool
     */
    public function isConsuming(): bool;

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return void
     */
    public function requeueJob(Job $job);
}
