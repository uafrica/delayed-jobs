<?php
declare(strict_types=1);

namespace DelayedJobs\TestSuite;

use DelayedJobs\DelayedJob\Job;

/**
 * Class JobAssertionTrait
 */
trait JobAssertionTrait
{
    /**
     * @param int $expectedCount How many expected
     * @param mixed $haystack The haystack
     * @param string $message The message if false
     * @return mixed
     */
    abstract public function assertCount($expectedCount, $haystack, $message = '');

    /**
     * @param bool $condition The condition
     * @param string $message The message
     * @return mixed
     */
    abstract public static function assertTrue($condition, $message = '');

    /**
     * @param int $count How many jobs
     * @param string $message Message if bad
     * @return void
     */
    public function assertJobCount($count, $message = '')
    {
        $this->assertCount($count, TestManager::getJobs(), $message);
    }

    /**
     * @param callable $callback Callable
     * @param string $message Message
     * @return void
     */
    public function assertEachJob(callable $callback, $message = '')
    {
        $jobs = TestManager::getJobs();
        foreach ($jobs as $job) {
            if (!$callback($job)) {
                $this->assertTrue(false, $message ?: 'Job found that doesn\'t match the supplied callback.');

                return;
            }
        }

        $this->assertTrue(true);
    }

    /**
     * @param callable $callback Callback
     * @param string $message Message
     * @return void
     */
    public function assertJob(callable $callback, $message = '')
    {
        $jobs = TestManager::getJobs();
        foreach ($jobs as $job) {
            if ($callback($job)) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->assertTrue(false, $message ?: 'No jobs matched the supplied callback.');
    }

    /**
     * @param callable $callback Callabke
     * @param string $message Message
     * @return void
     */
    public function assertNotJob(callable $callback, $message = '')
    {
        $jobs = TestManager::getJobs();
        foreach ($jobs as $job) {
            if ($callback($job)) {
                $this->assertTrue(false, $message ?: 'Job matching the supplied callback.');

                return;
            }
        }

        $this->assertTrue(true);
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
