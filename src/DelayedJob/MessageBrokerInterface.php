<?php

namespace DelayedJobs\DelayedJob;

/**
 * Interface BrokerInterface
 */
interface MessageBrokerInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to publish
     * @return mixed
     */
    public function publishJob(Job $job);
}
