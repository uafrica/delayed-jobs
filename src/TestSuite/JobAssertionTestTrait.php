<?php
declare(strict_types=1);

namespace DelayedJobs\TestSuite;

use DelayedJobs\DelayedJob\Job;
use DelayedJobs\TestSuite\Constraint\JobCallback;
use DelayedJobs\TestSuite\Constraint\JobCount;
use PHPUnit\Framework\Constraint\Callback;

/**
 * Class JobAssertionTrait
 */
trait JobAssertionTestTrait
{
    /**
     * @param int $count How many jobs
     * @param string $message Message if bad
     * @return void
     */
    public function assertJobCount($count, $message = '')
    {
        $this->assertThat($count, new JobCount(), $message);
    }

    /**
     * @param callable $callback Callable
     * @param string $message Message
     * @return void
     */
    public function assertEachJob(callable $callback, $message = 'Job found that doesn\'t match the supplied callback.')
    {
        $this->assertThat(
            null,
            new JobCallback($callback, JobCallback::MATCH_ALL),
            $message
        );
    }

    /**
     * @param callable $callback Callback
     * @param string $message Message
     * @return void
     */
    public function assertJob(callable $callback, $message = 'No jobs matched the supplied callback.')
    {
        $this->assertThat(
            null,
            new JobCallback($callback, JobCallback::MATCH_ANY),
            $message
        );
    }

    /**
     * @param callable $callback Callabke
     * @param string $message Message
     * @return void
     */
    public function assertNotJob(callable $callback, $message = 'Job matching the supplied callback.')
    {
        $this->assertThat(
            null,
            new JobCallback($callback, JobCallback::MATCH_NONE),
            $message
        );
    }

    /**
     * @param string $worker Worker name
     * @param string $message Message
     * @return void
     */
    public function assertJobWorker($worker, $message = '')
    {
        $callback = function (Job $job) use ($worker) {
            return $job->getWorker() === $worker;
        };

        $this->assertJob($callback, $message ?: sprintf('No job using the "%s" worker was triggered.', $worker));
    }

    /**
     * @param string $worker Worker name
     * @param string $message Message
     * @return void
     */
    public function assertNotJobWorker($worker, $message = '')
    {
        $callback = function (Job $job) use ($worker) {
            return $job->getWorker() === $worker;
        };

        $this->assertNotJob($callback, $message ?: sprintf('A job using the "%s" worker was triggered.', $worker));
    }
}
