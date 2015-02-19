<?php

namespace DelayedJobs\Model\Table;

use Cake\I18n\Time;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\ORM\Table;
use Cake\Validation\Validator;

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

//
//    public function get($job_id)
//    {
//        $options = array('conditions' => array("DelayedJob.id" => $job_id));
//
//        $job = $this->find('first', $options);
//
//        if ($job) {
//            $job["DelayedJob"]["options"] = unserialize($job["DelayedJob"]["options"]);
//            $job["DelayedJob"]["payload"] = unserialize($job["DelayedJob"]["payload"]);
//
//            if (!isset($job["DelayedJob"]["options"]["max_retries"])) {
//                $job["DelayedJob"]["options"]["max_retries"] = Configure::read("dj.max.retries");
//            }
//
//            if (!isset($job["DelayedJob"]["options"]["max_execution_time"])) {
//                $job["DelayedJob"]["options"]["max_execution_time"] = Configure::read("dj.max.execution.time");
//            }
//        }
//
//        return $job;
//    }

    public function completed($job_id)
    {
        $data = ['DelayedJob' => [
                "status" => DJ_STATUS_SUCCESS,
                "pid" => null,
        ]];
        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function failed($job_id, $message = "")
    {

        $job = $this->get($job_id);

        $retries = $job["DelayedJob"]["retries"];

        $status = DJ_STATUS_FAILED;
        if ($retries + 1 > $job["DelayedJob"]["options"]["max_retries"]) {
            $status = DJ_STATUS_BURRIED;
        }


        //debug(time());
        //debug($retries);

        $growth_factor = 5 + pow($retries + 1, 4);

        //debug($growth_factor);

        $run_at = time() + $growth_factor;
        //debug($run_at);
        //debug(date('Y-m-d H:i:s', $run_at));

        $data = ['DelayedJob' => [
                "status" => $status,
                "last_message" => $message,
                "retries" => $retries + 1,
                "failed_at" => date('Y-m-d H:i:s'),
                "pid" => null,
                "run_at" => date('Y-m-d H:i:s', $run_at),
        ]];

        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function lock($job_id, $locked_by = "")
    {
        $data = ['DelayedJob' => [
                "status" => DJ_STATUS_BUSY,
                'locked_by' => $locked_by,
        ]];
        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function isBusy($job_id)
    {
        $options = ['conditions' => ['DelayedJob.status' => DJ_STATUS_BUSY, 'DelayedJob.id' => $job_id]];
        $job = $this->find('first', $options);

        if ($job) {
            return true;
        }

        return false;
    }

    public function setPid($job_id, $pid = 0)
    {
        $data = ['DelayedJob' => [
                'pid' => $pid,
        ]];
        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function setStatus($job_id, $status = DJ_STATUS_UNKNOWN)
    {
        $data = ['DelayedJob' => [
                'status' => $status,
        ]];
        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function getOpenJob($worker_id = "")
    {

//        $this->PlatformStatus = ClassRegistry::init('PlatformStatus');
//        $platform_status = $this->PlatformStatus->status();
//        if ($platform_status["PlatformStatus"]["status"] != "online")
//        {
//            return array();
//        }
        
        $allowed = [DJ_STATUS_FAILED, DJ_STATUS_NEW, DJ_STATUS_UNKNOWN];

        $options = [
            'conditions' => [
                "DelayedJob.status in (" . implode(",", $allowed) . ")",
                "DelayedJob.run_at <= NOW()"
            ],
            //'fields' => array('DelayedJob.id'),
            'order' => ["DelayedJob.priority" => "ASC", "DelayedJob.id" => "ASC"],
        ];

        $job = $this->find('first', $options);

        if ($job) {
        //$job = $this->get($job["DelayedJob"]["id"]);
            $job["DelayedJob"]["options"] = unserialize($job["DelayedJob"]["options"]);
            $job["DelayedJob"]["payload"] = unserialize($job["DelayedJob"]["payload"]);

            if (!isset($job["DelayedJob"]["options"]["max_retries"])) {
                $job["DelayedJob"]["options"]["max_retries"] = Configure::read("dj.max.retries");
            }

            if (!isset($job["DelayedJob"]["options"]["max_execution_time"])) {
                $job["DelayedJob"]["options"]["max_execution_time"] = Configure::read("dj.max.execution.time");
            }

            $data = ['DelayedJob' => [
                    "status" => DJ_STATUS_BUSY,
                    'locked_by' => $worker_id,
            ]];
            $this->id = $job["DelayedJob"]["id"];
            $this->save($data);

            //sleep(1);
            usleep(250000); //## Sleep for 0.25 seconds
            
            //Log::write('jobs', $job["DelayedJob"]["id"] . " Allocated to " . $worker_id);

            //## check if this job is still allocated to this worker

            $options = ['conditions' => ['DelayedJob.id' => $job["DelayedJob"]["id"], 'DelayedJob.locked_by' => $worker_id]];
            $t_job = $this->find('first', $options);

            if ($t_job) {
                return $job;
            } else {
                usleep(250000); //## Sleep for 0.25 seconds
            }              //  Log::write ("jobs", $job["DelayedJob"]["id"] . " was allocated to someone else");
        }

        return [];
    }

    public function getRunningByHost($host_id)
    {
        $options = [
            'conditions' => [
                "DelayedJob.locked_by" => $host_id,
                //"DelayedJob.run_at <= NOW()",
                "DelayedJob.status" => DJ_STATUS_BUSY,
            ],
            'fields' => ['DelayedJob.id', 'DelayedJob.pid'],
            'order' => ["DelayedJob.priority" => "ASC", "DelayedJob.id" => "ASC"],
        ];

        $jobs = $this->find('all', $options);

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
