<?php

namespace DelayedJobs\Result;

use DelayedJobs\DelayedJob\Job;

/**
 * Class Result
 */
abstract class Result implements ResultInterface
{
    /**
     * @var string
     */
    private $_message;
    /**
     * @var \DelayedJobs\DelayedJob\Job
     */
    private $_job;
    /**
     * @var \DateTimeInterface|null
     */
    private $_recur;

    /**
     * Result constructor.
     *
     * @param \DelayedJobs\DelayedJob\Job $job The job
     * @param string $message The message
     */
    public function __construct(Job $job, $message = '')
    {
        $this->_message = $message;
        $this->_job = $job;
    }

    /**
     * @param string $message
     * @return self
     */
    public function setMessage(string $message = ''): Result
    {
        $this->_message = $message;

        return $this;
    }

    /**
     * @return \DelayedJobs\DelayedJob\Job
     */
    public function getJob(): Job
    {
        return $this->_job;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return (string)$this->_message;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getRecur()
    {
        return $this->_recur;
    }

    /**
     * @return bool
     */
    public function canRetry(): bool
    {
        return $this->getRetry() && $this->getJob()->getRetries() < $this->getJob()->getMaxRetries();
    }

    /**
     * @param \DateTimeInterface|null $recur When to re-queue the job for.
     * @return self
     */
    public function willRecur(\DateTimeInterface $recur = null)
    {
        $this->_recur = $recur;

        return $this;
    }

    /**
     * Most results will not retry
     *
     * @return bool
     */
    public function getRetry(): bool
    {
        return false;
    }

    /**
     * @param bool $retry
     * @return self
     */
    public function willRetry(bool $retry = true)
    {
        return $this;
    }
}
