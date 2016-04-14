<?php

namespace DelayedJobs\Model\Table;

use Cake\Core\Configure;
use Cake\I18n\Time;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use DelayedJobs\Model\Entity\Worker;

/**
 * DelayedJobs.Workers Model
 *
 */
class WorkersTable extends Table
{
    const STATUS_IDLE = 1;
    const STATUS_RUNNING = 2;
    const STATUS_TO_KILL = 3;
    const STATUS_UNKNOWN = 4;
    const STATUS_SHUTDOWN = 5;

    public function initialize(array $config)
    {
        $this->addBehavior('Timestamp');
        $this->table('delayed_job_workers');

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
            'status' => self::STATUS_RUNNING,
            'pulse' => new Time()
        ];

        $host = $this->getWorker($host_name, $worker_name);
        if (!$host) {
            $host = $this->newEntity();
        }
        $this->patchEntity($host, $data);

        return $this->save($host);
    }

    public function findForHost(Query $query, array $options)
    {
        return $query
            ->where([
                'Workers.host_name' => $options['host']
            ])
            ->order([
                'Workers.worker_name'
            ]);
    }

    public function getWorker($host_name, $worker_name)
    {
        $conditions = [
            'Workers.host_name' => $host_name,
            'Workers.worker_name' => $worker_name
        ];

        $host = $this
            ->find()
            ->where($conditions)
            ->first();

        return $host;
    }

    public function setStatus(Worker $worker, $status)
    {
        $worker->status = $status;
        $this->save($worker);
    }
}
