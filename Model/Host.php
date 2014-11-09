<?php

App::uses('DelayedJobAppModel', 'DelayedJobs.Model');

define("DJ_HOST_STATUS_IDLE", 1);
define("DJ_HOST_STATUS_RUNNING", 2);
define("DJ_HOST_STATUS_TO_KILL", 3);
define("DJ_HOST_STATUS_UNKNOWN", 4);

/**
 * DelayedJobs.Host Model
 *
 */
class Host extends DelayedJobAppModel
{

    public $useTable = 'delayed_job_hosts';

    /**
     * Validation rules
     *
     * @var array
     */
    public $validate = array(
        'host_name' => array(
            'notempty' => array(
                'rule' => array('notempty'),
                'message' => 'host_name is required',
                'required' => false,
            ),
        ),
    );

    public function Started($host_name, $worker_name, $pid)
    {
        $data = array("Host" => array(
                "host_name" => $host_name,
                "worker_name" => $worker_name,
                "pid" => $pid,
                "status" => DJ_HOST_STATUS_RUNNING,
        ));

        $host = $this->findByHost($host_name, $worker_name);

        if (!$host)
            $this->create();

        if ($this->save($data))
        {
            $host = $this->findByHost($host_name, $worker_name);
            return $host;
        }
        else
        {
            return false;
        }
    }

    public function findByHost($host_name, $worker_name)
    {
        $options = array("conditions" => array("Host.host_name" => $host_name, "Host.worker_name" => $worker_name));

        $host = $this->find('first', $options);

        return $host;
    }

    public function getStatus($host_id)
    {
        
    }

    public function setStatus($host_id, $status)
    {
        $this->id = $host_id;

        $data = array("Host" => array(
                "status" => $status,
        ));

        return $this->save($data);
    }

    public function Remove($host_id)
    {
        $this->id = $host_id;
        return $this->delete();
    }

    public function checkConfig()
    {
        return Configure::check("dj.service.name");
    }

}
