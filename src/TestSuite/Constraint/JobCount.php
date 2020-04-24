<?php
declare(strict_types=1);

namespace DelayedJobs\TestSuite\Constraint;

/**
 * JobCount
 *
 * @internal
 */
class JobCount extends JobConstraintBase
{
    /**
     * Checks constraint
     *
     * @param mixed $other Constraint check
     * @return bool
     */
    public function matches($other): bool
    {
        return count($this->getJobs()) === $other;
    }

    /**
     * Assertion message string
     *
     * @return string
     */
    public function toString(): string
    {
        return 'jobs were enqueued';
    }
}
