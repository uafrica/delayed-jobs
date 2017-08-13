<?php

namespace DelayedJobs\Result;

use DelayedJobs\DelayedJob\Job;

/**
 * Class Pause
 */
class Pause extends Result
{
    /**
     * @return int
     */
    public function getStatus(): int
    {
        return Job::STATUS_PAUSED;
    }

    /**
     * @return bool
     */
    public function canRetry(): bool
    {
        return false;
    }
}
