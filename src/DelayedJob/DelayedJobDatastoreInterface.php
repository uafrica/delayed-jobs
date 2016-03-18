<?php

namespace DelayedJobs\DelayedJob;

/**
 * Interface DelayedJobDatastoreInterface
 */
interface DelayedJobDatastoreInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job to persist
     * @return \DelayedJobs\DelayedJob\DelayedJob|bool
     */
    public function persistJob(DelayedJob $job);

    /**
     * @param int $jobId The job to get
     * @return \DelayedJobs\DelayedJob\DelayedJob|null
     */
    public function fetchJob($jobId);

    /**
     * Returns true if a job of the same sequence is already persisted and waiting execution.
     *
     * @param \DelayedJobs\DelayedJob\DelayedJob $job The job to check for
     * @return bool
     */
    public function currentlySequenced(DelayedJob $job);

    /**
     * Gets the next job in the sequence
     *
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job to get next sequence for
     * @return \DelayedJobs\DelayedJob\DelayedJob|null
     */
    public function fetchNextSequence(DelayedJob $job);

    /**
     * Checks if there already is a job with the same class waiting
     *
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job to check
     * @return bool
     */
    public function isSimilarJob(DelayedJob $job);
}
