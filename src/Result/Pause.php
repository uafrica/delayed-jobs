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
}
