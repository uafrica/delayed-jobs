<?php

namespace DelayedJobs\DelayedJob;

/**
 * Interface DelayedJobsInterface
 */
interface DelayedJobInterface
{
    /**
     * @param string|\DelayedJobs\DelayedJob\DelayedJob $class Class to enqueue (In CakePHP format), or a Job instance
     * @param mixed $payload The payload for the job
     * @param array $options Options
     * @return \DelayedJobs\DelayedJob\DelayedJob
     * @throws \DelayedJobs\DelayedJob\Exception\JobDataException
     */
    public function enqueue($class, $payload = null, array $options = []);
}
