<?php

namespace DelayedJobs\Result;

use DelayedJobs\DelayedJob\Job;

/**
 * Class Success
 */
class Success extends Result
{

    /**
     * @var \DateTimeInterface|null
     */
    private $_recur;

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return Job::STATUS_SUCCESS;
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
        return false;
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
}
