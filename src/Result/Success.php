<?php
declare(strict_types=1);

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
    public function getRetry(): bool
    {
        return false;
    }
}
