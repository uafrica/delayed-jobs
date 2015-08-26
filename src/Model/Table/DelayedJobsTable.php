<?php

namespace DelayedJobs\Model\Table;

use Cake\Core\Configure;
use Cake\Database\Schema\Table as Schema;
use Cake\Datasource\ConnectionManager;
use Cake\Event\Event;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use DelayedJobs\Model\Entity\DelayedJob;

/**
 * DelayedJob Model
 *
 */
class DelayedJobsTable extends Table
{
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
     * @param \Cake\Validation\Validator $validator
     * @return \Cake\Validation\Validator
     * @codeCoverageIgnore
     */
    public function validationDefault(Validator $validator)
    {
        $validator
            ->notEmpty('group')
            ->notEmpty('class')
            ->notEmpty('method');

        return $validator;
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

    public function completed(DelayedJob $job, $message = null)
    {
        if ($message) {
            $job->last_message = $message;
        }
        $job->status = self::STATUS_SUCCESS;
        $job->pid = null;

        return $this->save($job);
    }

    public function failed(DelayedJob $job, $message = '')
    {
        $max_retries = isset($job->options['max_retries']) ? $job->options['max_retries'] : Configure::read('dj.max.retries');

        $job->status = self::STATUS_FAILED;
        if ($job->retries + 1 > $max_retries) {
            $job->status = self::STATUS_BURRIED;
        }

        $growth_factor = 5 + pow($job->retries + 1, 4);

        $job->run_at = new Time("+{$growth_factor} seconds");
        $job->last_message = $message;
        $job->retries = $job->retries + 1;
        $job->failed_at = new Time();
        $job->pid = null;
        $job->locked_by = null;

        return $this->save($job);
    }

    public function lock(DelayedJob $job, $locked_by = '')
    {
        $job->status = self::STATUS_BUSY;
        $job->locked_by = $locked_by;
        $this->save($job);
    }

    public function release(DelayedJob $job)
    {
        $job->status = self::STATUS_NEW;
        $job->locked_by = null;
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
        $this->connection(ConnectionManager::get('buffered'));
        $result = $this->exists($conditions);
        $this->connection(ConnectionManager::get('default'));

        return $result;
    }

    /**
     * @return mixed
     */
    public function nextJob()
    {
        $allowed = [self::STATUS_FAILED, self::STATUS_NEW, self::STATUS_UNKNOWN];

        $job_query = $this
            ->find()
            ->select([
                'id',
                'sequence'
            ])
            ->where([
                'DelayedJobs.status in' => $allowed,
                'DelayedJobs.run_at <=' => new Time()
            ])
            ->order([
                'DelayedJobs.priority' => 'ASC',
                'DelayedJobs.id' => 'ASC'
            ])
            ->limit(1000) //We don't want to sit here for a million possible jobs, so we limit it to a reasonable limit
            ->hydrate(false)
            ->bufferResults(false);
        $statement = $job_query->execute();
        $result_set = new ResultSet($job_query, $statement);

        $count = 1;
        foreach ($result_set as $job) {
            if ($job && !$this->nextSequence($job)) {
                $statement->closeCursor();
                return $this->get($job['id']);
            }
            $count++;
        }
        $statement->closeCursor();

        return null;
    }

    public function getOpenJob($worker_id = '')
    {
        $job = $this->nextJob();

        if (!$job || !in_array($job->status, [self::STATUS_FAILED, self::STATUS_NEW, self::STATUS_UNKNOWN])) {
            return false;
        }

        $options = (array)$job->options;
        if (!isset($options['max_retries'])) {
            $options['max_retries'] = Configure::read('dj.max.retries');
        }

        if (!isset($options['max_execution_time'])) {
            $options['max_execution_time'] = Configure::read('dj.max.execution.time');
        }
        $job->options = $options;

        $this->lock($job, $worker_id);

        usleep(250000); //## Sleep for 0.25 seconds

        //## check if this job is still allocated to this worker
        $job = $this->get($job->id);
        $next_sequence = $this->nextSequence($job);

        /*
         * If this job is locked by us, and another same sequence isn't running we carry on
         * Otherwise, we release this job back into the pool
         */
        if ($job->locked_by === $worker_id && !$next_sequence) {
            return $job;
        } elseif ($job->locked_by === $worker_id && $next_sequence) {
            Log::debug($job->sequence . ' was grabbed by someone else', [
                'scope' => 'delayed_jobs'
            ]);
            $this->release($job);
        } else {
            Log::debug($job->id . ' was allocated to someone else', [
                'scope' => 'delayed_jobs'
            ]);
        }

        usleep(250000); //## Sleep for 0.25 seconds
    }

    /**
     * @param $host_id
     * @return \Cake\ORM\Query
     */
    public function getRunningByHost($host_id)
    {
        $conditions = [
            'DelayedJobs.locked_by' => $host_id,
            'DelayedJobs.status' => self::STATUS_BUSY,
        ];

        $jobs = $this
            ->find()
            ->select([
                'DelayedJobs.id',
                'DelayedJobs.pid'
            ])
            ->where($conditions)
            ->order([
                'DelayedJobs.priority' => 'ASC',
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
        $count = number_format($count / $second_count, 3);

        return $count;
    }

    /**
     * @return int
     */
    public function clean()
    {
        return $this->deleteAll([
            'status' => self::STATUS_SUCCESS,
            'modified <=' => new Time('-4 weeks')
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
            'group' => $job_details['group'],
            'class' => $job_details['class'],
            'method' => $job_details['method'],
            'status IN' => [
                self::STATUS_BUSY,
                self::STATUS_NEW,
                self::STATUS_FAILED,
                self::STATUS_UNKNOWN
            ]
        ];

        $exists = $this->exists($conditions);

        $this->connection()
            ->driver()
            ->autoQuoting($quoting);

        return $exists;
    }

    /**
     * @return void
     */
    public function beforeSave()
    {
        $this->quote = $this->connection()
            ->driver()
            ->autoQuoting();
        $this->connection()
            ->driver()
            ->autoQuoting(true);
    }

    /**
     * @return void
     */
    public function afterSave()
    {
        $this->connection()
            ->driver()
            ->autoQuoting($this->quote);
    }
}
