<?php

namespace DelayedJobs\DelayedJob;

trait EnqueueTrait
{
    /**
     * @param string|\DelayedJobs\DelayedJob\Job $class Class to enqueue (In CakePHP format), or a Job instance
     * @param mixed $payload The payload for the job
     * @param array $options
     * @return \DelayedJobs\DelayedJob\Job
     * @throws \DelayedJobs\DelayedJob\Exception\JobDataException
     */
    public function enqueue($class, $payload = null, array $options = [])
    {
        if ($class instanceof Job) {
            $job = $class;
        } else {
            $job = new Job();
            $job
                ->setWorker($class)
                ->setPayload($payload)
                ->setData($options);
        }

        return Manager::instance()->enqueue($job);
    }
}
