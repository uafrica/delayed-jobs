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
     * @param bool $batch Use batch technique to push job
     * @return void
     */
    public function publishJob(Job $job, bool $batch = false);

    /**
     * @return void
     */
    public function finishBatch(): void;

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
     * @param string $body Message body
     * @param string $exchange Exchange to route through
     * @param string $routing_key Routing key
     * @param int $priority Priority
     * @param array $headers Headers
     * @return mixed
     */
    public function publishBasic(string $body, $exchange = '', $routing_key = '', int $priority = 0, array $headers = []);
}
