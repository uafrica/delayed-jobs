<?php
declare(strict_types=1);

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
    public function publishJob(Job $job): void;

    /**
     * @param callable $callback Callback to run when a job is received
     * @param callable $heartbeat Callback to run on heartbeat
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
     * @param bool $requeue Should we requeue it?
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
        string $exchange = '',
        string $routing_key = '',
        int $priority = 0,
        array $headers = []
    ): void;
}
