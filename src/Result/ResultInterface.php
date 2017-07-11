<?php

namespace DelayedJobs\Result;

use DelayedJobs\DelayedJob\Job;

/**
 * Interface ResultInterface
 */
interface ResultInterface
{
    /**
     * @return int
     */
    public function getStatus(): int;

    /**
     * @return string
     */
    public function getMessage(): string;

    /**
     * @return \DelayedJobs\DelayedJob\Job
     */
    public function getJob(): Job;

    /**
     * @return \DateTimeInterface|null
     */
    public function getRecur();

    /**
     * @return bool
     */
    public function getRetry(): bool;

    /**
     * @param bool $retry
     * @return self
     */
    public function willRetry(bool $retry = true);

    /**
     * @param string $message The message for this result.
     * @return self
     */
    public function setMessage(string $message = '');

    /**
     * @param \DateTimeInterface|null $recur When to re-queue the job for.
     * @return self
     */
    public function willRecur(\DateTimeInterface $recur = null);
}
