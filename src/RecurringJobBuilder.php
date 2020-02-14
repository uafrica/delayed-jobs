<?php
declare(strict_types=1);

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
     * @param array $jobInfo Job info
     * @return void
     */
    public static function add(array $jobInfo)
    {
        static::$_recurringJobs[] = $jobInfo;
    }

    /**
     * @return array
     */
    public static function retrieve(): array
    {
        return static::$_recurringJobs;
    }
}
