<?php

App::uses('DelayedJobAppModel', 'DelayedJobs.Model');

define("DJ_STATUS_NEW", 1);
define("DJ_STATUS_BUSY", 2);
define("DJ_STATUS_BURRIED", 3);
define("DJ_STATUS_SUCCESS", 4);
define("DJ_STATUS_KICK", 5);
define("DJ_STATUS_FAILED", 6);
define("DJ_STATUS_UNKNOWN", 7);
define("DJ_STATUS_TEST_JOB", 8);

/**
 * DelayedJob Model
 *
 */
class DelayedJob extends DelayedJobAppModel
{

    public $useTable = 'delayed_jobs';

    /**
     * Validation rules
     *
     * @var array
     */
    public $validate = array(
        'group' => array(
            'notempty' => array(
                'rule' => array('notempty'),
                //'message' => 'Your custom message here',
                'allowEmpty' => true,
                'required' => false,
            //'last' => false, // Stop validation after this rule
            //'on' => 'create', // Limit validation to 'create' or 'update' operations
            ),
        ),
        'class' => array(
            'notempty' => array(
                'rule' => array('notempty'),
            //'message' => 'Your custom message here',
            //'allowEmpty' => false,
            //'required' => false,
            //'last' => false, // Stop validation after this rule
            //'on' => 'create', // Limit validation to 'create' or 'update' operations
            ),
        ),
        'method' => array(
            'notempty' => array(
                'rule' => array('notempty'),
            //'message' => 'Your custom message here',
            //'allowEmpty' => false,
            //'required' => false,
            //'last' => false, // Stop validation after this rule
            //'on' => 'create', // Limit validation to 'create' or 'update' operations
            ),
        ),
        'status' => array(
            'numeric' => array(
                'rule' => array('numeric'),
            //'message' => 'Your custom message here',
            //'allowEmpty' => false,
            //'required' => false,
            //'last' => false, // Stop validation after this rule
            //'on' => 'create', // Limit validation to 'create' or 'update' operations
            ),
        ),
        'retries' => array(
            'numeric' => array(
                'rule' => array('numeric'),
            //'message' => 'Your custom message here',
            //'allowEmpty' => false,
            //'required' => false,
            //'last' => false, // Stop validation after this rule
            //'on' => 'create', // Limit validation to 'create' or 'update' operations
            ),
        ),
    );

    /*
     * $options
     * delay
     * priority
     */

    public function queue($data)
    {

        $default_options = array();

        if (!isset($data["class"]))
            throw new Exception("No Class Specified");

        if (!isset($data["method"]))
            throw new Exception("No Method Specified");

        if (!isset($data["group"]))
            $data["group"] = null;

        if (!isset($data["payload"]))
            $data["payload"] = array();

        if (!isset($data["options"]))
            $data["options"] = $default_options;

        if (!isset($data["priority"]))
            $data["priority"] = 100;


        $data["payload"] = serialize($data["payload"]);
        $data["options"] = serialize($data["options"]);

        $data = array(
            "DelayedJob" => array(
                'group' => $data["group"],
                'class' => $data["class"],
                'method' => $data["method"],
                'payload' => $data["payload"],
                'options' => $data["options"],
                'status' => DJ_STATUS_NEW,
                'run_at' => date('Y-m-d H:i:s', time() + 5),
                'priority' => $data["priority"],
            ),
        );

        $this->create();
        if ($this->save($data))
        {
            return $this->id;
        }
        else
        {
            throw new Exception("Could not create job");
            return false;
        }
    }

    public function get($job_id)
    {
        $options = array('conditions' => array("DelayedJob.id" => $job_id));

        $job = $this->find('first', $options);

        if ($job)
        {
            $job["DelayedJob"]["options"] = unserialize($job["DelayedJob"]["options"]);
            $job["DelayedJob"]["payload"] = unserialize($job["DelayedJob"]["payload"]);

            if (!isset($job["DelayedJob"]["options"]["max_retries"]))
                $job["DelayedJob"]["options"]["max_retries"] = Configure::read("dj.max.retries");

            if (!isset($job["DelayedJob"]["options"]["max_execution_time"]))
                $job["DelayedJob"]["options"]["max_execution_time"] = Configure::read("dj.max.execution.time");
        }

        return $job;
    }

    public function completed($job_id)
    {
        $data = array('DelayedJob' => array(
                "status" => DJ_STATUS_SUCCESS,
                "pid" => null,
        ));
        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function failed($job_id, $message = "")
    {

        $job = $this->get($job_id);

        $retries = $job["DelayedJob"]["retries"];

        $status = DJ_STATUS_FAILED;
        if ($retries + 1 > $job["DelayedJob"]["options"]["max_retries"])
            $status = DJ_STATUS_BURRIED;

        $growth_factor = 5 + pow($retries + 1, 4);

        $run_at = time() + $growth_factor;

        $data = array('DelayedJob' => array(
                "status" => $status,
                "last_message" => $message,
                "retries" => $retries + 1,
                "failed_at" => date('Y-m-d H:i:s'),
                "pid" => null,
                "run_at" => date('Y-m-d H:i:s', $run_at),
        ));

        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function lock($job_id, $locked_by = "")
    {
        $data = array('DelayedJob' => array(
                "status" => DJ_STATUS_BUSY,
                'locked_by' => $locked_by,
        ));
        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function isBusy($job_id)
    {
        $options = array('conditions' => array('DelayedJob.status' => DJ_STATUS_BUSY, 'DelayedJob.id' => $job_id));
        $job = $this->find('first', $options);

        if ($job)
            return true;

        return false;
    }

    public function setPid($job_id, $pid = 0)
    {
        $data = array('DelayedJob' => array(
                'pid' => $pid,
        ));
        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function setStatus($job_id, $status = DJ_STATUS_UNKNOWN)
    {
        $data = array('DelayedJob' => array(
                'status' => $status,
        ));
        $this->id = $job_id;
        $this->save($data);

        return true;
    }

    public function getOpenJob($worker_id = "")
    {

        $this->PlatformStatus = ClassRegistry::init('PlatformStatus');
        $platform_status = $this->PlatformStatus->status();
        if ($platform_status["PlatformStatus"]["status"] != "online")
        {
            return array();
        }
        
        $allowed = array(DJ_STATUS_FAILED, DJ_STATUS_NEW, DJ_STATUS_UNKNOWN);

        $options = array(
            'conditions' => array(
                "DelayedJob.status in (" . implode(",", $allowed) . ")",
                "DelayedJob.run_at <= NOW()"
            ),
            //'fields' => array('DelayedJob.id'),
            'order' => array("DelayedJob.priority" => "ASC", "DelayedJob.id" => "ASC"),
        );

        $job = $this->find('first', $options);

        if ($job)
        {
            //$job = $this->get($job["DelayedJob"]["id"]);
            $job["DelayedJob"]["options"] = unserialize($job["DelayedJob"]["options"]);
            $job["DelayedJob"]["payload"] = unserialize($job["DelayedJob"]["payload"]);

            if (!isset($job["DelayedJob"]["options"]["max_retries"]))
                $job["DelayedJob"]["options"]["max_retries"] = Configure::read("dj.max.retries");

            if (!isset($job["DelayedJob"]["options"]["max_execution_time"]))
                $job["DelayedJob"]["options"]["max_execution_time"] = Configure::read("dj.max.execution.time");

            $data = array('DelayedJob' => array(
                    "status" => DJ_STATUS_BUSY,
                    'locked_by' => $worker_id,
            ));
            $this->id = $job["DelayedJob"]["id"];
            $this->save($data);

            usleep(250000); //## Sleep for 0.25 seconds
            
            //## check if this job is still allocated to this worker

            $options = array('conditions' => array('DelayedJob.id' => $job["DelayedJob"]["id"], 'DelayedJob.locked_by' => $worker_id));
            $t_job = $this->find('first', $options);

            if ($t_job)
                return $job;
            else
                usleep(250000); //## Sleep for 0.25 seconds
        }

        return array();
    }

    public function getRunningByHost($host_id)
    {
        $options = array(
            'conditions' => array(
                "DelayedJob.locked_by" => $host_id,
                //"DelayedJob.run_at <= NOW()",
                "DelayedJob.status" => DJ_STATUS_BUSY,
            ),
            'fields' => array('DelayedJob.id', 'DelayedJob.pid'),
            'order' => array("DelayedJob.priority" => "ASC", "DelayedJob.id" => "ASC"),
        );

        $jobs = $this->find('all', $options);

        return $jobs;
    }

}
