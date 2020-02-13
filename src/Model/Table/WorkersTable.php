<?php
declare(strict_types=1);

namespace DelayedJobs\Model\Table;

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
    public const STATUS_IDLE = 1;
    public const STATUS_RUNNING = 2;
    public const STATUS_TO_KILL = 3;
    public const STATUS_UNKNOWN = 4;
    public const STATUS_SHUTDOWN = 5;
    public const STATUS_DEAD = 6;

    /**
     * @param array $config Config
     * @return void
     */
    public function initialize(array $config): void
    {
        $this->addBehavior('Timestamp');
        $this->setTable('delayed_job_workers');

        parent::initialize($config);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validate
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('host_name');

        return $validator;
    }

    /**
     * @param $host_name
     * @param $worker_name
     * @param $pid
     * @return bool|\Cake\Datasource\EntityInterface|mixed
     */
    public function started($host_name, $worker_name, $pid)
    {
        $data = [
            'host_name' => $host_name,
            'worker_name' => $worker_name,
            'pid' => $pid,
            'status' => self::STATUS_RUNNING,
            'pulse' => new Time(),
        ];

        $host = $this->newEntity($data);
        $this->patchEntity($host, $data);

        return $this->save($host);
    }

    /**
     * @param \Cake\ORM\Query $query
     * @param array $options
     * @return \Cake\ORM\Query
     */
    public function findForHost(Query $query, array $options): Query
    {
        return $query
            ->where([
                'Workers.host_name' => $options['host'],
            ])
            ->order([
                'Workers.worker_name',
            ]);
    }

    /**
     * @param $host_name
     * @param $worker_name
     * @return array|\Cake\Datasource\EntityInterface|null
     */
    public function getWorker($host_name, $worker_name)
    {
        $conditions = [
            'Workers.host_name' => $host_name,
            'Workers.worker_name' => $worker_name,
        ];

        $host = $this
            ->find()
            ->where($conditions)
            ->first();

        return $host;
    }

    /**
     * @param \DelayedJobs\Model\Entity\Worker $worker
     * @param $status
     * @return void
     */
    public function setStatus(Worker $worker, $status)
    {
        $worker->status = $status;
        $this->save($worker);
    }
}
