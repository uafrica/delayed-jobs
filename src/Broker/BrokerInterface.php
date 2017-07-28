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

    public function consume(callable $callback, callable $heartbeat);

    public function stopConsuming();

    public function ack(Job $job);

    public function nack(Job $job, $requeue = false);

    /**
     * @param string $body
     * @param string $exchange
     * @param string $routing_key
     * @param array $headers
     * @return mixed
     */
    public function publishBasic(string $body, $exchange = '', $routing_key = '', array $headers = []);
}
