<?php
declare(strict_types=1);

namespace DelayedJobs\TestSuite\Constraint;

/**
 * Class JobCallback
 */
class JobCallback extends JobConstraintBase
{
    public const MATCH_ALL = 0;
    public const MATCH_ANY = 1;
    public const MATCH_NONE = 2;

    /**
     * @var callable
     */
    private $callback;
    /**
     * @var int
     */
    private $matchType;

    /**
     * JobCallback constructor.
     *
     * @param callable $callback Callback
     * @param int $type The type of match
     */
    public function __construct(callable $callback, $type = self::MATCH_ANY)
    {
        $this->callback = $callback;
        $this->matchType = $type;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return 'is accepted by specified callback';
    }

    /**
     * @param mixed $other Other arguments
     * @return bool
     */
    protected function matches($other): bool
    {
        $jobs = $this->getJobs();
        $callback = $this->callback;
        foreach ($jobs as $job) {
            $result = $callback($job, $other);

            if ($result && $this->matchType === self::MATCH_ANY) {
                return true;
            }

            if (($result && $this->matchType === self::MATCH_NONE) || (!$result && $this->matchType === self::MATCH_ALL)) {
                return false;
            }
        }

        return true;
    }
}
