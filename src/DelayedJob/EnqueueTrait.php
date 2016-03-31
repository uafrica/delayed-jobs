<?php

namespace DelayedJobs\DelayedJob;

trait EnqueueTrait
{
    /**
     * @param string|\DelayedJobs\DelayedJob\Job $worker Worker class to enqueue (In CakePHP format), or a Job instance
     * @param mixed $payload The payload for the job
     * @param array $options Array of options
     * @return \DelayedJobs\DelayedJob\Job
     * @throws \DelayedJobs\DelayedJob\Exception\JobDataException
     */
    public function enqueue($worker, $payload = null, array $options = [])
    {
        if ($worker instanceof Job) {
            $job = $worker;
        } else {
            $job = new Job();
            $job
                ->setWorker($worker)
                ->setPayload($payload)
                ->setData($options);
        }

        return Manager::instance()->enqueue($job);
    }
}
