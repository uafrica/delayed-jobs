<?php
declare(strict_types=1);

namespace DelayedJobs\Result;

use DelayedJobs\DelayedJob\Job;
use Throwable;

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
     * @return null|\Throwable
     */
    public function getException(): ?Throwable
    {
        return $this->_exception;
    }

    /**
     * @param null|\Throwable $exception The exception
     * @return self
     */
    public function setException(?Throwable $exception = null): self
    {
        $this->_exception = $exception;

        return $this;
    }
}
