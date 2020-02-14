<?php
declare(strict_types=1);

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
    public function publishJob(array $jobData): void;

    /**
     * @param callable $callback Callback on job
     * @param callable $heartbeat Callback on heartbeat
     * @return void
     */
    public function consume(callable $callback, callable $heartbeat): void;

    /**
     * @return void
     */
    public function stopConsuming(): void;

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to acknowledge
     * @return void
     */
    public function acknowledge(Job $job): void;

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to negative acknowledge
     * @param bool $requeue Should be requeued
     * @return void
     */
    public function negativeAcknowledge(Job $job, bool $requeue = false): void;

    /**
     * @param string $body Message body
     * @param string $exchange Exchange to route through
     * @param string $routing_key Routing key
     * @param int $priority Priority
     * @param array $headers Headers
     * @return void
     */
    public function publishBasic(
        string $body,
        $exchange = '',
        $routing_key = '',
        int $priority = 0,
        array $headers = []
    ): void;
}
