<?php

namespace DelayedJobs\Broker;

use DelayedJobs\DelayedJob\Job;

/**
 * Interface BrokerInterface
 */
interface BrokerInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to publish
     * @return void
     */
    public function publishJob(Job $job);

    /**
     * @param callable $callback
     * @param callable $heartbeat
     * @return mixed
     */
    public function consume(callable $callback, callable $heartbeat);

    public function stopConsuming();

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return mixed
     */
    public function ack(Job $job);

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @param bool $requeue
     * @return mixed
     */
    public function nack(Job $job, $requeue = false);

    /**
     * @param string $body
     * @param string $exchange
     * @param string $routing_key
     * @param array $headers
     * @return mixed
     */
    public function publishBasic(string $body, $exchange = '', $routing_key = '', int $priority = 0, array $headers = []);
}
