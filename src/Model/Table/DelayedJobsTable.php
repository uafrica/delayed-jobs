<?php

namespace DelayedJobs\Model\Table;

use Cake\Database\Schema\TableSchema;
use Cake\I18n\Time;
use Cake\ORM\Query;
use Cake\ORM\Table;
use DelayedJobs\DelayedJob\DatastoreInterface;
use DelayedJobs\DelayedJob\Job;
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
     * @param array $config Config array.
     * @return void
     * @codeCoverageIgnore
     */
    public function initialize(array $config)
    {
        $this->addBehavior('Timestamp');

        parent::initialize($config);
    }

    /**
     * @param \Cake\Database\Schema\TableSchema $table Table schema
     * @return \Cake\Database\Schema\TableSchema
     */
    protected function _initializeSchema(TableSchema $table)
    {
        $table->columnType('payload', 'serialize');
        $table->columnType('options', 'serialize');
        $table->columnType('history', 'json');

        return parent::_initializeSchema($table);
    }

    /**
     * Returns true if a job of the same sequence is already persisted and waiting execution.
     *
     * @param \DelayedJobs\DelayedJob\Job $job The job to check for
     * @return bool
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
                Job::STATUS_PAUSED
            ]
        ]);
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return \DelayedJobs\DelayedJob\Job|null
     */
    public function persistJob(Job $job)
    {
        $jobData = $job->getData();
        $jobEntity = $job->getEntity();
        if (!$jobEntity) {
            $jobEntity = $this->newEntity();
        }
        $this->patchEntity($jobEntity, $jobData, [
            'accessibleFields' => [
                '*' => true
            ]
        ]);

        if (!$jobEntity->status) {
            $jobEntity->status = Job::STATUS_NEW;
        }

        $options = [
            'atomic' => !$this->getConnection()->inTransaction()
        ];

        $result = $this->save($jobEntity, $options);

        if (!$result) {
            return null;
        }

        $job->setId($jobEntity->id);
        $job->setEntity($jobEntity);

        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job[] $jobs
     * @return \DelayedJobs\DelayedJob\Job[]
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
            ->driver()
            ->autoQuoting();
        $connection
            ->driver()
            ->autoQuoting(true);
        $connection->transactional(function () use ($query, &$jobs) {
            $statement = $query->execute();
            $firstId = $statement->lastInsertId($this->getTable(), 'id');
            foreach ($jobs as $job) {
                $job->setId($firstId++);
            }

            return true;
        });

        $connection->driver()
            ->autoQuoting($quote);

        return $jobs;
    }

    /**
     * @param int $jobId
     * @return \DelayedJobs\DelayedJob\Job|null
     */
    public function fetchJob($jobId)
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
     * @param \DelayedJobs\DelayedJob\Job $job The job to fetch next sequence for
     * @return \DelayedJobs\DelayedJob\Job|null
     */
    public function fetchNextSequence(Job $job)
    {
        if ($job->getSequence() === null) {
            return null;
        }

        $this->getConnection()->driver()->autoQuoting(false);
        $next = $this->find()
            ->select([
                'id',
                'sequence',
                'priority',
                'run_at'
            ])
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
     * Checks if there already is a job with the same worker waiting
     *
     * @param \DelayedJobs\DelayedJob\Job $job Job to check
     * @return bool
     */
    public function isSimilarJob(Job $job): bool
    {
        $quoting = $this->getConnection()
            ->driver()
            ->autoQuoting();
        $this->getConnection()
            ->driver()
            ->autoQuoting(true);

        $conditions = [
            'worker' => $job->getWorker(),
            'status IN' => [
                Job::STATUS_BUSY,
                Job::STATUS_NEW,
                Job::STATUS_PAUSED,
                Job::STATUS_FAILED,
                Job::STATUS_UNKNOWN
            ]
        ];

        if (!empty($job->getId())) {
            $conditions['id !='] = $job->getId();
        }

        $exists = $this->exists($conditions);

        $this->getConnection()
            ->driver()
            ->autoQuoting($quoting);

        return $exists;
    }

    /**
     * @return void
     */
    public function beforeSave()
    {
        $this->_quote = $this->getConnection()
            ->driver()
            ->autoQuoting();
        $this->getConnection()
            ->driver()
            ->autoQuoting(true);
    }

    /**
     * @return void
     */
    public function afterSave()
    {
        $this->getConnection()
            ->driver()
            ->autoQuoting($this->_quote);
    }
}
