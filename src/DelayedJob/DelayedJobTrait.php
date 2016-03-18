<?php

namespace DelayedJobs\DelayedJob;

trait DelayedJobTrait
{
    /**
     * @param string|\DelayedJobs\DelayedJob\DelayedJob $class Class to enqueue (In CakePHP format), or a Job instance
     * @param mixed $payload The payload for the job
     * @param array $options
     * @return \DelayedJobs\DelayedJob\DelayedJob
     * @throws \DelayedJobs\DelayedJob\Exception\JobDataException
     */
    public function enqueue($class, $payload = null, array $options = [])
    {
        if ($class instanceof DelayedJob) {
            $job = $class;
        } else {
            $job = new DelayedJob();
            $job
                ->setClass($class)
                ->setPayload($payload)
                ->setData($options);
        }

        return DelayedJobManager::instance()->enqueue($job);
    }
}
