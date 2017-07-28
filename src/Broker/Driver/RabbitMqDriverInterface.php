<?php

namespace DelayedJobs\Broker\Driver;

use DelayedJobs\DelayedJob\Job;

/**
 * Interface BrokerInterface
 */
interface RabbitMqDriverInterface
{
    /**
     * @param array $jobData Job to publish
     * @return void
     */
    public function publishJob(array $jobData);

    public function consume(callable $callback, callable $heartbeat);

    public function stopConsuming();

    public function ack(Job $job);

    public function nack(Job $job, $requeue = false);

    public function getChannel();

    /**
     * @param string $body
     * @param string $exchange
     * @param string $routing_key
     * @param array $headers
     * @return mixed
     */
    public function publishBasic(string $body, $exchange = '', $routing_key = '', array $headers = []);
}
