<?php

namespace DelayedJobs\Model\Table;

use Cake\Event\Event;
use Cake\I18n\Time;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\ORM\Query;
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

    public function initialize(array $config)
    {
        $this->addBehavior('Timestamp');

        parent::initialize($config);
    }

    public function validationDefault(Validator $validator)
    {
        $validator
            ->notEmpty('group')
            ->notEmpty('class')
            ->notEmpty('method');
        return $validator;
    }

    public function beforeSave(Event $event, DelayedJob $entity)
    {
        if (!is_string($entity->options)) {
            $entity->options = serialize($entity->options);
        }
        if (!is_string($entity->payload)) {
            $entity->payload = serialize($entity->payload);
        }
    }

    public function completed(DelayedJob $job)
    {
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

    public function getOpenJob($worker_id = '')
    {

//        $this->PlatformStatus = ClassRegistry::init('PlatformStatus');
//        $platform_status = $this->PlatformStatus->status();
//        if ($platform_status['PlatformStatus']['status'] != 'online')
//        {
//            return array();
//        }
        
        $allowed = [self::STATUS_FAILED, self::STATUS_NEW, self::STATUS_UNKNOWN];

        $job = $this
            ->find()
            ->where([
                'DelayedJobs.status in' => $allowed,
                'DelayedJobs.run_at <=' => new Time()
            ])
            ->order([
                'DelayedJobs.priority' => 'ASC',
                'DelayedJobs.id' => 'ASC'
            ])
            ->first();

        if ($job) {
            $options = (array)unserialize($job->options);
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

            $conditions = [
                'DelayedJobs.id' => $job->id,
                'DelayedJobs.locked_by' => $worker_id
            ];
            if ($this->exists($conditions)) {
                return $job;
            } else {
                usleep(250000); //## Sleep for 0.25 seconds
            }              //  Log::write ('jobs', $job['DelayedJob']['id'] . ' was allocated to someone else');
        }

        return false;
    }

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
    
    public function jobsPerSecond()
    {
        $conditions = [
            'DelayedJobs.created > ' => new Time('-1 hour'),
        ];

        $count = $this
            ->find()
            ->where($conditions)
            ->count();
        $count = round($count / 60 / 60, 3);


        return $count;
    }
}
