<?php

namespace DelayedJobs\Model\Table;

use Cake\Core\Configure;
use Cale\ORM\Table;

/**
 * DelayedJobs.Host Model
 *
 */
class HostsTable extends Table
{
    const STATUS_IDLE = 1;
    const STATUS_RUNNING = 2;
    const STATUS_TO_KILL = 3;
    const STATUS_UNKNOWN = 4;

    public function initialize(array $config)
    {
        $this->addBehavior('Timestamp');
        $this->table('delayed_job_hosts');

        parent::initialize($config);
    }


    public function validationDefault(Validator $validator)
    {
        $validator
            ->notEmpty('host_name');
        return $validator;
    }

    public function started($host_name, $worker_name, $pid)
    {
        $data = [
            'host_name' => $host_name,
            'worker_name' => $worker_name,
            'pid' => $pid,
            'status' => DJ_HOST_STATUS_RUNNING,
        ];

        $host = $this->findByHost($host_name, $worker_name);

        $this->patchEntity($host, $data);

        return $this->save($host);
    }

    public function findByHost($host_name, $worker_name)
    {
        $conditions = [
            'Hosts.host_name' => $host_name,
            'Hosts.worker_name' => $worker_name
        ];

        $host = $this
            ->find()
            ->where($conditions)
            ->first();

        if (!$host) {
            $host = $this->newEntity();
        }

        return $host;
    }

    public function getStatus($host_id)
    {
        
    }

    public function setStatus($host, $status)
    {
        $host->status = $status;

        return $this->save($host);
    }

    public function remove($host)
    {
        return $this->delete($host);
    }

    public function checkConfig()
    {
        return Configure::check('dj.service.name');
    }
}
