<?php

namespace DelayedJobs\DelayedJobs;

trait DelayedJobsTrait
{
    /**
     * @param string|\DelayedJobs\DelayedJobs\Job $class Class to enqueue (In CakePHP format), or a Job instance
     * @param string|null $method Method name to run, or null if job instance is supplied
     * @param array $options
     * @return bool
     * @throws \DelayedJobs\DelayedJobs\Exception\JobDataException
     */
    public function enqueue($class, $method = null, array $options = [])
    {
        if ($class instanceof Job) {
            $job = $class;
        } else {
            $job = new Job();
            $job
                ->setClass($class)
                ->setMethod($method)
                ->setData($options);
        }

        return DelayedJobsManager::instance()->enqueueJob($job);
    }
}
