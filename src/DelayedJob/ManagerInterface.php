<?php
declare(strict_types=1);

namespace DelayedJobs\DelayedJob;

use DelayedJobs\Result\ResultInterface;

/**
 * Interface DelayedJobManagerInterface
 */
interface ManagerInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job
     * @return void
     */
    public function enqueue(Job $job): void;

    /**
     * @param int $id The ID to enqueue
     * @param int $priority The priority of the job
     * @return void
     */
    public function enqueuePersisted($id, $priority): void;

    /**
     * Enqueues a batch of jobs
     *
     * @param \DelayedJobs\DelayedJob\Job[] $jobs Array of jobs to enqueue
     * @return void
     */
    public function enqueueBatch(array $jobs): void;

    /**
     * Gets the Job instance for a specific job
     *
     * @param int $jobId Job to fetch
     * @return \DelayedJobs\DelayedJob\Job
     * @throws \DelayedJobs\DelayedJob\Exception\JobNotFoundException
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
     * @param \DelayedJobs\DelayedJob\Job $job Job to execute
     * @param bool $force Force the job to run regardless of it's status
     * @return \DelayedJobs\Result\ResultInterface|null
     */
    public function execute(Job $job, bool $force = false): ?ResultInterface;

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to enqueue next of sequence
     * @return void
     */
    public function enqueueNextSequence(Job $job): void;

    /**
     * @internal param \DelayedJobs\DelayedJob\Job $job
     * @param \DelayedJobs\DelayedJob\Job $job Job to check for similar
     * @return bool
     */
    public function isSimilarJob(Job $job): bool;

    /**
     * @return void
     */
    public function startConsuming(): void;

    /**
     * @return void
     */
    public function stopConsuming(): void;

    /**
     * @return bool
     */
    public function isConsuming(): bool;

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to requeue
     * @return void
     */
    public function requeueJob(Job $job): void;

    /**
     * @return int
     */
    public function getMaximumPriority(): int;
}
