<?php
declare(strict_types=1);

namespace DelayedJobs\Datasource;

use DelayedJobs\DelayedJob\Job;

/**
 * Interface DatastoreInterface
 */
interface DatasourceInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to persist
     * @return \DelayedJobs\DelayedJob\Job|bool
     */
    public function persistJob(Job $job);

    /**
     * @param \DelayedJobs\DelayedJob\Job[] $jobs Array of jobs
     * @return array
     */
    public function persistJobs(array $jobs): array;

    /**
     * @param int $jobId The job to get
     * @return \DelayedJobs\DelayedJob\Job
     * @throws \DelayedJobs\DelayedJob\Exception\JobNotFoundException
     */
    public function fetchJob(int $jobId): Job;

    /**
     * Returns true if a job of the same sequence is already persisted and waiting execution.
     *
     * @param \DelayedJobs\DelayedJob\Job $job The job to check for
     * @return bool
     */
    public function currentlySequenced(Job $job): bool;

    /**
     * Gets the next job in the sequence
     *
     * @param \DelayedJobs\DelayedJob\Job $job Job to get next sequence for
     * @return \DelayedJobs\DelayedJob\Job|null
     */
    public function fetchNextSequence(Job $job): ?Job;

    /**
     * Checks if there already is a job with the same class waiting
     *
     * @param \DelayedJobs\DelayedJob\Job $job Job to check
     * @return bool
     */
    public function isSimilarJob(Job $job): bool;

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job instance
     * @return \DelayedJobs\DelayedJob\Job
     * @throws \DelayedJobs\DelayedJob\Exception\JobNotFoundException
     */
    public function loadJob(Job $job): Job;
}
