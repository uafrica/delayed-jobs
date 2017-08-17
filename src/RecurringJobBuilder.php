<?php

namespace DelayedJobs;

/**
 * Class RecuringJobBuilder
 */
class RecurringJobBuilder
{
    /**
     * @var array
     */
    protected static $_recurringJobs = [];

    /**
     * @param array $jobInfo
     * @return void
     */
    /**
     * @param array $jobInfo
     * @return void
     */
    public static function add(array $jobInfo)
    {
        static::$_recurringJobs[] = $jobInfo;
    }

    /**
     * @return array
     */
    /**
     * @return array
     */
    public static function retrieve(): array
    {
        return static::$_recurringJobs;
    }
}
