<?php

namespace DelayedJobs\Result;

use DelayedJobs\DelayedJob\Job;

/**
 * Class Success
 */
class Success extends Result
{
    /**
     * @return int
     */
    public function getStatus(): int
    {
        return Job::STATUS_SUCCESS;
    }

    /**
     * @return bool
     */
    public function canRetry(): bool
    {
        return false;
    }
}
