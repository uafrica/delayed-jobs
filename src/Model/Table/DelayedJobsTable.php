<?php

namespace DelayedJobs\Model\Table;

use Cake\Core\Configure;
use Cake\Database\Schema\Table as Schema;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\DelayedJob\DelayedJobDatastoreInterface;
use DelayedJobs\DelayedJob\DelayedJob as Job;
use DelayedJobs\Model\Entity\DelayedJob;
use DelayedJobs\Traits\DebugTrait;

/**
 * DelayedJob Model
 *
 */
class DelayedJobsTable extends Table implements DelayedJobDatastoreInterface
{
    use DebugTrait;

    const SEARCH_LIMIT = 10000;
    const STATUS_NEW = 1;
    const STATUS_BUSY = 2;
    const STATUS_BURRIED = 3;
    const STATUS_SUCCESS = 4;
    const STATUS_KICK = 5;
    const STATUS_FAILED = 6;
    const STATUS_UNKNOWN = 7;
    const STATUS_TEST_JOB = 8;

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

        return parent::_initializeSchema($table);
    }

    public function release(DelayedJob $job)
    {
        $job->status = self::STATUS_NEW;
        $job->host_name = null;
        $this->save($job);
    }

    public function isBusy($job_id)
    {
        $conditions = [
            'DelayedJobs.status' => self::STATUS_BUSY,
            'DelayedJobs.id' => $job_id
        ];

        return $this->exists($conditions);
    }

    public function setPid(DelayedJob $job, $pid = 0)
    {
        $job->pid = $pid;

        return $this->save($job);
    }

    public function setStatus(DelayedJob $job, $status = self::STATUS_UNKNOWN)
    {
        $job->status = $status;

        return $this->save($job);
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
            'status in' => [self::STATUS_BUSY, self::STATUS_FAILED]
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
            'DelayedJobs.status' => self::STATUS_BUSY,
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
            'DelayedJobs.status' => self::STATUS_BUSY,
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
            'status' => self::STATUS_SUCCESS,
            'modified <=' => new Time('-2 weeks')
        ]);
    }

    public function jobExists($job_details)
    {
        $quoting = $this->connection()
            ->driver()
            ->autoQuoting();
        $this->connection()
            ->driver()
            ->autoQuoting(true);

        $conditions = [
            'class' => $job_details['class'],
            'status IN' => [
                self::STATUS_BUSY,
                self::STATUS_NEW,
                self::STATUS_FAILED,
                self::STATUS_UNKNOWN
            ]
        ];

        if (!empty($job_details['id'])) {
            $conditions['id !='] = $job_details['id'];
        }

        $exists = $this->exists($conditions);

        $this->connection()
            ->driver()
            ->autoQuoting($quoting);

        return $exists;
    }

    public function getJob($job_id, $all_fields = false)
    {
        $options = [];
        if (!$all_fields) {
            $options['fields'] = [
                'id',
                'pid',
                'host_name',
                'status',
                'options',
                'sequence',
                'class',
                'method',
                'priority'
            ];
        }

        return $this->get($job_id, $options);
    }

    /**
     * @param \DelayedJobs\Model\Entity\DelayedJob $dj
     * @return void
     */
    protected function _processJobForQueue(DelayedJob $dj)
    {
        if ($dj->isNew()) {
            $this->dj_log(__('Job {0} has been created', $dj->id));
        }

        if ($dj->isNew() || $dj->status === self::STATUS_FAILED) {
            $this->_queueJob($dj);
        }

        if ($dj->sequence && ($dj->status === self::STATUS_SUCCESS || $dj->status === self::STATUS_BURRIED)) {
            $this->_queueNextSequence($dj);
        }
    }

    public function currentlySequenced(Job $job)
    {
        return $this->exists([
            'id <' => $job->getId(),
            'sequence' => $job->getSequence(),
            'status in' => [Job::STATUS_NEW, Job::STATUS_BUSY, Job::STATUS_FAILED, Job::STATUS_UNKNOWN]
        ]);
    }

    protected function _queueJob(DelayedJob $dj, $check_sequence = true)
    {
        if ($check_sequence &&
            $dj->status === self::STATUS_NEW &&
            $dj->sequence &&
            $this->_existingSequence($dj)
        ) {
            $this->dj_log(__('{0} will not be queued because sequence exists: {1}', $dj->id, $dj->sequence));
            return;
        }
        $this->dj_log(__('{0} will be queued with sequence of {1}', $dj->id, $dj->sequence));
        $dj->queue();
    }

    protected function _queueNextSequence(DelayedJob $dj)
    {
        $next = $this->find()
            ->select([
                'id',
                'sequence',
                'priority',
                'run_at'
            ])
            ->where([
                'status' => self::STATUS_NEW,
                'sequence' => $dj->sequence,
            ])
            ->order([
                'priority' => 'ASC',
                'id' => 'ASC',
            ])
            ->first();

        if (!$next) {
            $this->dj_log(__('No more sequenced jobs found for {0}', $dj->sequence));
            return;
        }

        $this->_queueJob($next, false);
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
                'not' => ['status' => self::STATUS_NEW]
            ])
            ->group(['status'])
            ->toArray();
        $statuses['waiting'] = $this->find()
            ->where([
                'status' => self::STATUS_NEW,
                'run_at >' => new Time()
            ])
            ->count();
        $statuses[self::STATUS_NEW] = $this->find()
            ->where([
                'status' => self::STATUS_NEW,
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
     * @param \DelayedJobs\DelayedJob\DelayedJob $job The job to fetch next sequence for
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
                'status' => Job::STATUS_NEW,
                'sequence' => $job->getSequence(),
            ])
            ->order([
                'priority' => 'ASC',
                'id' => 'ASC',
            ])
            ->first();

        if (!$next) {
            $this->dj_log(__('No more sequenced jobs found for {0}', $job->getSequence()));

            return false;
        }

        $job = new Job($next->toArray());
        return $job;
    }

    /**
     * Checks if there already is a job with the same class waiting
     *
     * @param \DelayedJobs\DelayedJob\DelayedJob $job Job to check
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
            'class' => $job->getClass(),
            'status IN' => [
                self::STATUS_BUSY,
                self::STATUS_NEW,
                self::STATUS_FAILED,
                self::STATUS_UNKNOWN
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
