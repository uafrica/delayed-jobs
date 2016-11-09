<?php

namespace DelayedJobs;

/**
 * Class RecuringJobBuilder
 */
class RecurringJobBuilder
{
    protected static $_recurringJobs = [];

    public static function add(array $jobInfo)
    {
        static::$_recurringJobs[] = $jobInfo;
    }

    public static function retrieve()
    {
        return static::$_recurringJobs;
    }
}
