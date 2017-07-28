<?php

namespace DelayedJobs\Result;

use DelayedJobs\DelayedJob\Job;

/**
 * Class Failed
 */
class Failed extends Result
{
    /**
     * @var bool
     */
    private $_retry = true;
    /**
     * @var \Throwable|null
     */
    private $_exception;

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->_retry ? Job::STATUS_FAILED : Job::STATUS_BURIED;
    }

    /**
     * Most results will not retry
     *
     * @return bool
     */
    public function getRetry(): bool
    {
        return $this->_retry;
    }

    /**
     * @param bool $retry
     * @return self
     */
    public function willRetry(bool $retry = true)
    {
        $this->_retry = $retry;

        return parent::willRetry($retry);
    }

    /**
     * @return null|\Throwable
     */
    public function getException()
    {
        return $this->_exception;
    }

    /**
     * @param null|\Throwable $exception
     * @return self
     */
    public function setException(\Throwable $exception = null)
    {
        $this->_exception = $exception;

        return $this;
    }

}
