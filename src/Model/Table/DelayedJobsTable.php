<?php
declare(strict_types=1);

namespace DelayedJobs\Model\Table;

use Cake\Database\Schema\TableSchemaInterface;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use DelayedJobs\DelayedJob\DatastoreInterface;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\Model\Entity\DelayedJob;
use DelayedJobs\Traits\DebugLoggerTrait;

/**
 * DelayedJob Model
 *
 * @internal
 */
class DelayedJobsTable extends Table implements DatastoreInterface
{
    use DebugLoggerTrait;

    /**
     * @var bool
     */
    protected $_quote;

    /**
     * @param array $config Config array.
     * @return void
     * @codeCoverageIgnore
     */
    public function initialize(array $config): void
    {
        $this->addBehavior('Timestamp');

        parent::initialize($config);
    }

    /**
     * @param \Cake\Database\Schema\TableSchemaInterface $table Table schema
     * @return \Cake\Database\Schema\TableSchemaInterface
     */
    protected function _initializeSchema(TableSchemaInterface $table): TableSchemaInterface
    {
        $table->setColumnType('payload', 'serialize');
        $table->setColumnType('options', 'serialize');
        $table->setColumnType('history', 'json');

        return parent::_initializeSchema($table);
    }

    /**
     * {@inheritDoc}
     */
    public function currentlySequenced(Job $job): bool
    {
        return $this->exists([
            'id <' => $job->getId(),
            'sequence' => $job->getSequence(),
            'status in' => [
                Job::STATUS_NEW,
                Job::STATUS_BUSY,
                Job::STATUS_FAILED,
                Job::STATUS_UNKNOWN,
                Job::STATUS_PAUSED,
            ],
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function persistJob(Job $job): Job
    {
        $jobData = $job->getData();

        $jobEntity = $job->getEntity();

        if ($jobEntity === null) {
            $jobEntity = $this->newEmptyEntity();
        }

        if (!$jobEntity instanceof DelayedJob) {
            throw new \RuntimeException('Not correct entity type');
        }

        $this->patchEntity($jobEntity, $jobData, [
            'accessibleFields' => [
                '*' => true,
            ],
        ]);

        if (!$jobEntity->status) {
            $jobEntity->status = Job::STATUS_NEW;
        }

        $options = [
            'atomic' => !$this->getConnection()->inTransaction(),
        ];

        $this->saveOrFail($jobEntity, $options);

        $job->setId($jobEntity->id);
        $job->setEntity($jobEntity);

        return $job;
    }

    /**
     * {@inheritDoc}
     */
    public function persistJobs(array $jobs): array
    {
        if (empty($jobs)) {
            return [];
        }

        $query = $this->query()
            ->insert([
                'worker',
                'group',
                'priority',
                'payload',
                'options',
                'sequence',
                'run_at',
                'status',
                'failed_at',
                'last_message',
                'start_time',
                'end_time',
                'duration',
                'max_retries',
                'retries',
                'created',
                'modified',
                'history',
            ]);

        foreach ($jobs as $job) {
            $jobData = $job->getData();
            unset($jobData['id']);
            $jobData['created'] = date('Y-m-d H:i:s');
            $jobData['modified'] = date('Y-m-d H:i:s');
            $query->values($jobData);
        }

        $connection = $this->getConnection();
        $quote = $connection
            ->getDriver()
            ->isAutoQuotingEnabled();
        $connection
            ->getDriver()
            ->enableAutoQuoting();
        $connection->transactional(function () use ($query, &$jobs) {
            $statement = $query->execute();
            $firstId = (int)$statement->lastInsertId($this->getTable(), 'id');
            foreach ($jobs as $job) {
                $job->setId($firstId++);
            }

            return true;
        });

        $connection->getDriver()
            ->enableAutoQuoting($quote);

        return $jobs;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchJob(int $jobId): ?Job
    {
        $jobEntity = $this->find()
            ->where(['id' => $jobId])
            ->first();

        if (!$jobEntity) {
            return null;
        }

        return new Job($jobEntity);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchJobEntity(int $jobId): ?EntityInterface
    {
        return $this->get($jobId);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchNextSequence(Job $job): ?Job
    {
        if ($job->getSequence() === null) {
            return null;
        }

        $next = $this->find()
            ->where([
                'id !=' => $job->getId(),
                'status' => Job::STATUS_NEW,
                'sequence' => $job->getSequence(),
            ])
            ->order([
                'priority' => 'ASC',
                'id' => 'ASC',
            ])
            ->from([$this->getTable() . ' ' . $this->getAlias() . ' FORCE INDEX (status_2)'])
            ->enableHydration(false)
            ->first();

        if (!$next) {
            $this->djLog(__('No more sequenced jobs found for {0}', $job->getSequence()));

            return null;
        }

        return new Job($next);
    }

    /**
     * {@inheritDoc}
     */
    public function isSimilarJob(Job $job): bool
    {
        $quoting = $this->getConnection()
            ->getDriver()
            ->isAutoQuotingEnabled();
        $this->getConnection()
            ->getDriver()
            ->enableAutoQuoting();

        $conditions = [
            'group' => $job->getGroup(),
            'worker' => $job->getWorker(),
            'status IN' => [
                Job::STATUS_BUSY,
                Job::STATUS_NEW,
                Job::STATUS_PAUSED,
                Job::STATUS_FAILED,
                Job::STATUS_UNKNOWN,
            ],
        ];

        if ($job->getId() !== null) {
            $conditions['id !='] = $job->getId();
        }

        $exists = $this->exists($conditions);

        $this->getConnection()
            ->getDriver()
            ->enableAutoQuoting($quoting);

        return $exists;
    }

    /**
     * @return void
     */
    public function beforeSave()
    {
        $this->_quote = $this->getConnection()
            ->getDriver()
            ->isAutoQuotingEnabled();
        $this->getConnection()
            ->getDriver()
            ->enableAutoQuoting();
    }

    /**
     * @return void
     */
    public function afterSave()
    {
        $this->getConnection()
            ->getDriver()
            ->enableAutoQuoting($this->_quote);
    }
}
