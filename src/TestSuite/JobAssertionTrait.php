<?php

namespace DelayedJobs\TestSuite;

use DelayedJobs\DelayedJob\Job;

/**
 * Class JobAssertionTrait
 */
trait JobAssertionTrait
{
    /**
     * @param $expectedCount
     * @param $haystack
     * @param string $message
     * @return mixed
     */
    abstract public function assertCount($expectedCount, $haystack, $message = '');

    /**
     * @param $condition
     * @param string $message
     * @return mixed
     */
    abstract public static function assertTrue($condition, $message = '');

    /**
     * @param $count
     * @param string $message
     * @return void
     */
    public function assertJobCount($count, $message = '')
    {
        $this->assertCount($count, TestManager::getJobs(), $message);
    }

    /**
     * @param callable $callback
     * @param string $message
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
     * @param callable $callback
     * @param string $message
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
     * @param callable $callback
     * @param string $message
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
     * @param $worker
     * @param string $message
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
     * @param $worker
     * @param string $message
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
