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

    /**
     * DebugKitJobManager constructor.
     *
     * @param array $config Config
     * @param \DelayedJobs\Datasource\DatasourceInterface|null $datastore Datastore
     * @param \DelayedJobs\Broker\BrokerInterface|null $messageBroker Message broker
     */
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
    protected function pushToLog(Job $job): void
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
     * @inheritDoc
     */
    public function enqueue(Job $job, bool $skipPersist = false): void
    {
        parent::enqueue($job, $skipPersist);

        $this->pushToLog($job);
    }

    /**
     * @inheritDoc
     */
    public function enqueueBatch(array $jobs): void
    {
        parent::enqueueBatch($jobs);

        foreach ($jobs as $job) {
            $this->pushToLog($job);
        }
    }
}
