<?php

namespace DelayedJobs\Traits;

use Cake\Event\Event;
use Cake\Event\EventManager;

/**
 * Class QueueJobTrait
 */
trait QueueJobTrait
{
    /**
     * Proxy method to make queuing jobs easier
     *
     * @param string $group Group
     * @param string $class Class
     * @param string $method Method
     * @param mixed $payload Payload for job
     * @param int $priority Priority
     * @param string $sequence Sequence for the job
     * @param array $options Options for job
     * @return void
     */
    protected function _queueJob($group, $class, $method, $payload, $priority = 40, $sequence = null, array $options = [])
    {
        $default = ['max_retries' => 10];
        $options = $options + $default;

        $dj_data = [
            'group' => $group,
            'class' => $class,
            'method' => $method,
            'payload' => $payload,
            'priority' => $priority,
            'sequence' => $sequence,
            'options' => $options,
        ];

        $job_event = new Event('DelayedJob.queue', $this, $dj_data);
        EventManager::instance()->dispatch($job_event);
    }
}