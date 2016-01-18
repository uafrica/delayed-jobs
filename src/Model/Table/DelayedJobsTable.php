<?php

namespace DelayedJobs\Model\Table;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Database\Schema\Table as Schema;
use Cake\Datasource\ConnectionManager;
use Cake\Event\Event;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\Model\Entity\DelayedJob;
use DelayedJobs\Traits\DebugTrait;

/**
 * DelayedJob Model
 *
 */
class DelayedJobsTable extends Table
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
        $job->end_time = new Time();

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

        return $this->save($job);
    }

    public function lock(DelayedJob $job, $host_name = '')
    {
        $job->start_time = new Time();
        $job->status = self::STATUS_BUSY;
        $job->host_name = $host_name;
        $this->save($job);
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

        $cache_key = 'dj::' .
            Configure::read('dj.service.name') .
            '::' .
            $job_id .
            '::' .
            ($all_fields ? 'all' : 'limit');

        return Cache::remember($cache_key, function () use ($job_id, $options) {
            return $this->get($job_id, $options);
        }, Configure::read('dj.service.cache'));
    }

    /**
     * @return void
     */
    public function beforeSave(Event $event, DelayedJob $dj)
    {
        $this->quote = $this->connection()
            ->driver()
            ->autoQuoting();
        $this->connection()
            ->driver()
            ->autoQuoting(true);

        $options = (array)$dj->options;
        if (!isset($options['max_retries'])) {
            $options['max_retries'] = Configure::read('dj.max.retries');
        }

        if (!isset($options['max_execution_time'])) {
            $options['max_execution_time'] = Configure::read('dj.max.execution.time');
        }
        $dj->options = $options;
        if (isset($dj->priority) && !is_numeric($dj->priority)) {
            $dj->priority = Configure::read('dj.service.rabbit.max_priority');
        }
    }

    /**
     * @return void
     */
    public function afterSave(Event $event, DelayedJob $dj, \ArrayObject $options)
    {
        /*
         * Special case for jobs that are created within a parent transaction
         */
        if (!$options['atomic'] || !$options['_primary']) {
            $this->_processJobForQueue($dj);
        }

        $this->connection()
            ->driver()
            ->autoQuoting($this->quote);
    }

    /**
     * @return void
     */
    public function afterSaveCommit(Event $event, DelayedJob $dj)
    {
        $this->_processJobForQueue($dj);
    }

    /**
     * @param \DelayedJobs\Model\Entity\DelayedJob $dj
     * @return void
     */
    protected function _processJobForQueue(DelayedJob $dj)
    {
        $cache_key = 'dj::' . Configure::read('dj.service.name') . '::' . $dj->id;
        Cache::delete($cache_key . '::all', Configure::read('dj.service.cache'));
        Cache::delete($cache_key . '::limit', Configure::read('dj.service.cache'));

        if ($dj->has('payload')) {
            Cache::write($cache_key . '::all', $dj, Configure::read('dj.service.cache'));
        } else {
            Cache::write($cache_key . '::limit', $dj, Configure::read('dj.service.cache'));
        }

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

    protected function _existingSequence(DelayedJob $dj)
    {
        return $this->exists([
            'id <' => $dj->id,
            'sequence' => $dj->sequence,
            'status in' => [self::STATUS_NEW, self::STATUS_BUSY, self::STATUS_FAILED, self::STATUS_UNKNOWN]
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
                'id' => 'ASC'
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
}
