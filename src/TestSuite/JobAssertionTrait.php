<?php

namespace DelayedJobs\TestSuite;

use DelayedJobs\DelayedJob\DelayedJob;

/**
 * Class JobAssertionTrait
 */
trait JobAssertionTrait
{
    abstract function assertCount($expectedCount, $haystack, $message = '');

    abstract static function assertTrue($condition, $message = '');

    public function assertJobCount($count, $message = '')
    {
        $this->assertCount($count, TestDelayedJobManager::getJobs(), $message);
    }

    public function assertJob(callable $callback, $message = '')
    {
        $jobs = TestDelayedJobManager::getJobs();
        foreach ($jobs as $job) {
            if ($callback($job)) {
                $this->assertTrue(true);
                return;
            }
        }

        $this->assertTrue(false, $message ?: 'No jobs matched the supplied callback.');
    }

    public function assertNotJob(callable $callback, $message = '')
    {
        $jobs = TestDelayedJobManager::getJobs();
        foreach ($jobs as $job) {
            if ($callback($job)) {
                $this->assertTrue(false, $message ?: 'Job matching the supplied callback.');

                return;
            }
        }

        $this->assertTrue(true);
    }

    public function assertJobWorker($worker, $message = '')
    {
        $callback = function (DelayedJob $job) use ($worker) {
            return $job->getClass() === $worker;
        };

        $this->assertJob($callback, $message ?: sprintf('No job using the "%s" worker was triggered.', $worker));
    }

    public function assertNotJobWorker($worker, $message = '')
    {
        $callback = function (DelayedJob $job) use ($worker) {
            return $job->getClass() === $worker;
        };

        $this->assertNotJob($callback, $message ?: sprintf('A job using the "%s" worker was triggered.', $worker));
    }
}
