<?php

namespace DelayedJobs\TestSuite;

use Cake\Core\InstanceConfigTrait;
use Cake\Utility\Text;
use Cake\Console\Shell;
use DelayedJobs\DelayedJob\ManagerInterface;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\DelayedJob\Job;

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
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return \DelayedJobs\DelayedJob\Job|bool
     */
    public function enqueue(Job $job)
    {
        $jobId = time() + random_int(0, time());
        $job->setId($jobId);
        static::$_jobs[$jobId] = $job;

        return $job;
    }

    /**
     * @param int $id The ID to enqueue
     * @param int $priority The priority of the job
     * @return void
     */
    public function enqueuePersisted($id, $priority)
    {
        static::$_jobs[$id] = new Job(compact('id', 'priority'));
    }

    /**
     * @param array $jobs
     * @return array
     */
    public function enqueueBatch(array $jobs)
    {
        foreach ($jobs as $job) {
            $this->enqueue($job);
        }

        return $jobs;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job that failed
     * @param string $message Message to store with the jbo
     * @param bool $burryJob Should the job be burried
     * @return bool|\DelayedJobs\DelayedJob\Job
     */
    public function failed(Job $job, $message, $burryJob = false)
    {
        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job that has been completed
     * @param null $result
     * @param int $duration How long execution took
     * @return bool|\DelayedJobs\DelayedJob\Job
     * @internal param null|string $message Message to store with job
     */
    public function completed(Job $job, $result = null, $duration = 0)
    {
        return $job;
    }

    /**
     * Gets the Job instance for a specific job
     *
     * @param int $jobId Job to fetch
     * @return \DelayedJobs\DelayedJob\Job
     * @throws \DelayedJobs\DelayedJob\Exception\JobNotFoundException
     */
    public function fetchJob($jobId): \DelayedJobs\DelayedJob\Job
    {
        if (isset(static::$_jobs[$jobId])) {
            return static::$_jobs[$jobId];
        }

        throw new JobNotFoundException();
    }

    /**
     * Gets the current status for a requested job
     *
     * @param int $jobId Job to get status for
     * @return int
     */
    public function getStatus($jobId): int
    {
        return $this->fetchJob($jobId)->getStatus();
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @param null $hostname
     * @return void
     */
    public function lock(Job $job, $hostname = null)
    {
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @param $force
     * @return \DelayedJobs\DelayedJob\Job
     */
    public function execute(Job $job, $force)
    {
        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return null
     */
    public function enqueueNextSequence(Job $job)
    {
        return null;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return bool
     */
    public function isSimilarJob(Job $job): bool
    {
        return false;
    }

    public function startConsuming()
    {
    }

    public function stopConsuming()
    {
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return void
     */
    public function requeueJob(Job $job)
    {
    }

    /**
     * @return bool
     */
    public function isConsuming(): bool
    {
        return false;
    }
}
