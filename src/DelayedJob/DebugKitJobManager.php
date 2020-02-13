<?php
declare(strict_types=1);

namespace DelayedJobs\DelayedJob;

use DelayedJobs\Broker\BrokerInterface;
use DelayedJobs\Datasource\DatasourceInterface;

/**
 * Class DebugKitJobManager
 */
class DebugKitJobManager extends JobManager
{
    /**
     * A reference to the object were jobs will be pushed too for logging
     *
     * @var \ArrayObject
     */
    protected $jobLog;

    public function __construct(
        array $config = [],
        ?DatasourceInterface $datastore = null,
        ?BrokerInterface $messageBroker = null
    ) {
        $this->jobLog = $config['debugKitLog'];
        unset($config['debugKitLog']);

        parent::__construct($config, $datastore, $messageBroker);
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job instance
     * @return void
     */
    protected function pushToLog(Job $job)
    {
        $jobData = [
            'id' => $job->getId(),
            'worker' => $job->getWorker(),
            'sequence' => $job->getSequence(),
            'payload' => $job->getPayload(),
            'priority' => $job->getPriority(),
            'pushedToBroker' => $job->isPushedToBroker(),
        ];
        $this->jobLog[] = $jobData;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job that needs to be enqueued
     * @param bool $skipPersist Skip the persistance step (e.g. it's already been persisted
     * @return void
     */
    public function enqueue(Job $job, bool $skipPersist = false)
    {
        parent::enqueue($job, $skipPersist);

        $this->pushToLog($job);
    }

    /**
     * @param array $jobs
     * @return void
     */
    public function enqueueBatch(array $jobs)
    {
        parent::enqueueBatch($jobs);

        foreach ($jobs as $job) {
            $this->pushToLog($job);
        }
    }
}
