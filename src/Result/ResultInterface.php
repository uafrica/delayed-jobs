<?php
declare(strict_types=1);

namespace DelayedJobs\Result;

use Cake\Chronos\ChronosInterface;

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
     * @return \Cake\Chronos\ChronosInterface|null
     */
    public function getNextRun(): ?ChronosInterface;

    /**
     * @return bool
     */
    public function getRetry(): bool;

    /**
     * @return self
     */
    public function willRetry();

    /**
     * @return self
     */
    public function wontRetry();

    /**
     * @param string $message The message for this result.
     * @return self
     */
    public function setMessage(string $message = '');

    /**
     * @param \Cake\Chronos\ChronosInterface|null $recur When to re-queue the job for.
     * @return self
     */
    public function setNextRun(?ChronosInterface $recur);
}
