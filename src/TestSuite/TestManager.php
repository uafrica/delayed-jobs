<?php
declare(strict_types=1);

namespace DelayedJobs\TestSuite;

use Cake\Core\InstanceConfigTrait;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\ManagerInterface;

/**
 * Class TestDelayedJobManager
 */
class TestManager implements ManagerInterface
{
    use InstanceConfigTrait;

    /**
     * @var array
     */
    protected $_defaultConfig = [];
    /**
     * @var array
     */
    protected static $_jobs = [];

    /**
     * @return array
     */
    public static function getJobs(): array
    {
        return static::$_jobs;
    }

    /**
     * @return void
     */
    public static function clearJobs()
    {
        static::$_jobs = [];
    }

    /**
     * {@inheritDoc}
     */
    public function enqueue(Job $job): void
    {
        $jobId = time() + random_int(0, time());
        $job->setId($jobId);
        static::$_jobs[$jobId] = $job;

        return $job;
    }

    /**
     * {@inheritDoc}
     */
    public function enqueuePersisted($id, $priority): void
    {
        static::$_jobs[$id] = new Job(compact('id', 'priority'));
    }

    /**
     * {@inheritDoc}
     */
    public function enqueueBatch(array $jobs): void
    {
        foreach ($jobs as $job) {
            $this->enqueue($job);
        }

        return $jobs;
    }

    /**
     * {@inheritDoc}
     */
    public function failed(Job $job, $message, $burryJob = false)
    {
        return $job;
    }

    /**
     * {@inheritDoc}
     */
    public function completed(Job $job, $result = null, $duration = 0)
    {
        return $job;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchJob($jobId): Job
    {
        if (isset(static::$_jobs[$jobId])) {
            return static::$_jobs[$jobId];
        }

        throw new JobNotFoundException();
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus($jobId): int
    {
        return $this->fetchJob($jobId)->getStatus();
    }

    /**
     * {@inheritDoc}
     */
    public function lock(Job $job, $hostname = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Job $job, bool $force = false): ?\DelayedJobs\Result\ResultInterface
    {
        return $job;
    }

    /**
     * {@inheritDoc}
     */
    public function enqueueNextSequence(Job $job): void
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function isSimilarJob(Job $job): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function startConsuming(): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function stopConsuming(): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function requeueJob(Job $job): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function isConsuming(): bool
    {
        return false;
    }
}
