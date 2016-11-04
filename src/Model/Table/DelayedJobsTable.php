<?php

namespace DelayedJobs\Model\Table;

use Cake\Database\Schema\Table as Schema;
use Cake\I18n\Time;
use Cake\ORM\Table;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\Model\Entity\DelayedJob;
use DelayedJobs\Traits\DebugLoggerTrait;

/**
 * DelayedJob Model
 *
 * @internal
 */
class DelayedJobsTable extends Table
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
     * @param \Cake\Database\Schema\Table $table Table schema
     * @return \Cake\Database\Schema\Table
     */
    protected function _initializeSchema(Schema $table)
    {
        $table->columnType('payload', 'serialize');
        $table->columnType('options', 'serialize');
        $table->columnType('history', 'json');

        return parent::_initializeSchema($table);
    }

    /**
     * Returns true if another job with the same sequence number is already busy
     *
     * @param array|\Cake\ORM\Entity $job
     * @return bool
     */
    public function nextSequence($job)
    {
        if (empty($job['sequence'])) {
            return false;
        }

        $conditions = [
            'id !=' => $job['id'],
            'sequence' => $job['sequence'],
            'status in' => [Job::STATUS_BUSY, Job::STATUS_FAILED]
        ];
        $result = $this->exists($conditions);

        return $result;
    }

    /**
     * @param $host_id
     * @return \Cake\ORM\Query
     */
    public function getRunningByHost($host_id)
    {
        $conditions = [
            'DelayedJobs.host_name' => $host_id,
            'DelayedJobs.status' => Job::STATUS_BUSY,
        ];

        $jobs = $this
            ->find()
            ->select([
                'id',
                'pid',
                'host_name',
                'status',
                'priority',
            ])
            ->where($conditions)
            ->order([
                'DelayedJobs.id' => 'ASC'
            ]);

        return $jobs;
    }

    public function getRunning()
    {
        $conditions = [
            'DelayedJobs.status' => Job::STATUS_BUSY,
        ];

        $jobs = $this->find()
            ->select([
                'id',
                'pid',
                'status',
                'sequence',
                'priority',
            ])
            ->where($conditions)
            ->order([
                'DelayedJobs.id' => 'ASC'
            ]);

        return $jobs;
    }

    public function jobsPerSecond($conditions = [], $field = 'created', $time_range = '-1 hour')
    {
        $start_time = new Time($time_range);
        $current_time = new Time();
        $second_count = $current_time->diffInSeconds($start_time);
        $conditions[$this->aliasField($field) . ' > '] = $start_time;

        $count = $this
            ->find()
            ->where($conditions)
            ->count();

        return $count / $second_count;
    }

    /**
     * @return int
     */
    public function clean()
    {
        return $this->deleteAll([
            'status' => Job::STATUS_SUCCESS,
            'modified <=' => new Time('-2 weeks')
        ]);
    }

    public function currentlySequenced(Job $job)
    {
        return $this->exists([
            'id <' => $job->getId(),
            'sequence' => $job->getSequence(),
            'status in' => [Job::STATUS_NEW, Job::STATUS_BUSY, Job::STATUS_FAILED, Job::STATUS_UNKNOWN]
        ]);
    }

    public function jobRates($field, $status = null)
    {
        $available_rates = [
            '30 seconds',
            '5 minutes',
            '1 hour'
        ];

        $conditions = [];
        if ($status) {
            $conditions = [
                'status' => $status
            ];
        }

        $return = [];
        foreach ($available_rates as $available_rate) {
            $return[] = $this->jobsPerSecond($conditions, $field, '-' . $available_rate);
        }

        return $return;
    }

    public function statusStats()
    {
        $statuses = $this->find('list', [
            'keyField' => 'status',
            'valueField' => 'counter'
        ])
            ->select([
                'status',
                'counter' => $this->find()
                    ->func()
                    ->count('id')
            ])
            ->where([
                'not' => ['status' => Job::STATUS_NEW]
            ])
            ->group(['status'])
            ->toArray();
        $statuses['waiting'] = $this->find()
            ->where([
                'status' => Job::STATUS_NEW,
                'run_at >' => new Time()
            ])
            ->count();
        $statuses[Job::STATUS_NEW] = $this->find()
            ->where([
                'status' => Job::STATUS_NEW,
                'run_at <=' => new Time()
            ])
            ->count();

        return $statuses;
    }

    public function persistJob(Job $job)
    {
        $job_data = $job->getData();
        $job_entity = $this->newEntity($job_data);

        if (!$job_entity->status) {
            $job_entity->status = DelayedJob::STATUS_NEW;
        }

        $options = [
            'atomic' => !$this->connection()->inTransaction()
        ];

        $result = $this->save($job_entity, $options);

        if (!$result) {
            return false;
        }

        $job->setId($job_entity->id);

        return $job;
    }

    public function persistJobs(array $jobs)
    {
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
            ]);

        $batchData = [];
        foreach ($jobs as $job) {
            $jobData = $job->getData();
            unset($jobData['id']);
            $jobData['created'] = date('Y-m-d H:i:s');
            $jobData['modified'] = date('Y-m-d H:i:s');
            $query->values($jobData);
        }

        $connection = $this->connection();
        $quote = $connection
            ->driver()
            ->autoQuoting();
        $connection
            ->driver()
            ->autoQuoting(true);
        $connection->transactional(function () use ($query, &$jobs) {
            $statement = $query->execute();
            $firstId = $statement->lastInsertId($this->table(), 'id');
            foreach ($jobs as $job) {
                $job->setId($firstId++);
            }

            return true;
        });

        $connection->driver()
            ->autoQuoting($quote);
        return true;
    }

    public function fetchJob($jobId)
    {
        $job_entity = $this->find()
            ->where(['id' => $jobId])
            ->first();

        if (!$job_entity) {
            return null;
        }

        $job = new Job($job_entity->toArray());
        return $job;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job to fetch next sequence for
     * @return bool
     */
    public function fetchNextSequence(Job $job)
    {
        if ($job->getSequence() === null) {
            return false;
        }

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
            ->from([$this->table() . ' ' . $this->alias() . ' FORCE INDEX (status_2)'])
            ->first();

        if (!$next) {
            $this->djLog(__('No more sequenced jobs found for {0}', $job->getSequence()));

            return false;
        }

        $job = new Job($next->toArray());
        return $job;
    }

    /**
     * Checks if there already is a job with the same worker waiting
     *
     * @param \DelayedJobs\DelayedJob\Job $job Job to check
     * @return bool
     */
    public function isSimilarJob(Job $job)
    {
        $quoting = $this->connection()
            ->driver()
            ->autoQuoting();
        $this->connection()
            ->driver()
            ->autoQuoting(true);

        $conditions = [
            'worker' => $job->getWorker(),
            'status IN' => [
                Job::STATUS_BUSY,
                Job::STATUS_NEW,
                Job::STATUS_FAILED,
                Job::STATUS_UNKNOWN
            ]
        ];

        if (!empty($job->getId())) {
            $conditions['id !='] = $job->getId();
        }

        $exists = $this->exists($conditions);

        $this->connection()
            ->driver()
            ->autoQuoting($quoting);

        return $exists;
    }

    public function beforeSave()
    {
        $this->_quote = $this->connection()
            ->driver()
            ->autoQuoting();
        $this->connection()
            ->driver()
            ->autoQuoting(true);
    }

    public function afterSave()
    {
        $this->connection()
            ->driver()
            ->autoQuoting($this->_quote);
    }

}
