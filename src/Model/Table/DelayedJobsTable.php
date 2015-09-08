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

/**
 * DelayedJob Model
 *
 */
class DelayedJobsTable extends Table
{
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
            'DelayedJobs.locked_by' => $host_id,
            'DelayedJobs.status' => self::STATUS_BUSY,
        ];

        $jobs = $this
            ->find()
            ->select([
                'id',
                'pid',
                'locked_by',
                'status'
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
                'sequence'
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
        $options = [
            'cache' => Configure::read('dj.service.cache'),
            'key' => 'dj::' . Configure::read('dj.service.name') . '::' . $job_id . '::' . ($all_fields ? 'all' : 'limit')
        ];
        if (!$all_fields) {
            $options['fields'] = [
                'id',
                'pid',
                'locked_by',
                'status',
                'options',
                'sequence',
                'class',
                'method'
            ];
        }

        return $this->get($job_id, $options);
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

        if ($dj->isNew()) {
            Log::debug(__('Job {0} has been created', $dj->id));
        }

        if ($dj->isNew() || $dj->status === self::STATUS_FAILED) {
            $this->_queueJob($dj);
        }

        if ($dj->sequence && $dj->status === self::STATUS_SUCCESS) {
            $this->_queueNextSequence($dj);
        }
    }

    protected function _existingSequence(DelayedJob $dj)
    {
        return $this->exists([
            'id !=' => $dj->id,
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
            Log::debug(__('{0} will not be queued', $dj->id));
            return;
        }
        Log::debug(__('{0} will be queued', $dj->id));
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
            Log::debug(__('No more sequenced jobs found for {0}', $dj->sequence));
            return;
        }

        $this->_queueJob($next, false);
    }
}
