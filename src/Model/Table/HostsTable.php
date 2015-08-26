<?php

namespace DelayedJobs\Model\Table;

use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\Validation\Validator;

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
    const STATUS_SHUTDOWN = 5;

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

    public function started($host_name, $worker_name, $pid, $worker_count)
    {
        $data = [
            'host_name' => $host_name,
            'worker_name' => $worker_name,
            'pid' => $pid,
            'status' => self::STATUS_RUNNING,
            'worker_count' => $worker_count
        ];

        $host = $this->findByHost($host_name, $worker_name);
        if (!$host) {
            $host = $this->newEntity();
        }
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
}
