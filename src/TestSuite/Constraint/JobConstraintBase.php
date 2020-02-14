<?php
declare(strict_types=1);

namespace DelayedJobs\TestSuite\Constraint;

use DelayedJobs\TestSuite\TestManager;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Base class for all mail assertion constraints
 *
 * @internal
 */
abstract class JobConstraintBase extends Constraint
{
    /**
     * @var int|null
     */
    protected $at;

    /**
     * Constructor
     *
     * @param int|null $at At
     * @return void
     */
    public function __construct(?int $at = null)
    {
        $this->at = $at;
    }

    /**
     * Gets the jobs to check
     *
     * @return \DelayedJobs\DelayedJob\Job[]
     */
    public function getJobs()
    {
        $jobs = TestManager::getJobs();

        if ($this->at) {
            if (!isset($jobs[$this->at])) {
                return [];
            }

            return [$jobs[$this->at]];
        }

        return $jobs;
    }
}
