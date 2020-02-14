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
 * @method \DelayedJobs\Model\Entity\Worker get($primaryKey, $options = [])
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
     * @param string $hostName Host name
     * @param string $workerName Worker name
     * @param int $pid PID
     * @return bool|\Cake\Datasource\EntityInterface|mixed
     */
    public function started($hostName, $workerName, $pid)
    {
        $data = [
            'host_name' => $hostName,
            'worker_name' => $workerName,
            'pid' => $pid,
            'status' => self::STATUS_RUNNING,
            'pulse' => new Time(),
        ];

        $host = $this->newEntity($data);
        $this->patchEntity($host, $data);

        return $this->save($host);
    }

    /**
     * @param \Cake\ORM\Query $query Query
     * @param array $options Options
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
     * @param string $hostName Host name
     * @param string $workerName Worker name
     * @return array|\Cake\Datasource\EntityInterface|null
     */
    public function getWorker($hostName, $workerName)
    {
        $conditions = [
            'Workers.host_name' => $hostName,
            'Workers.worker_name' => $workerName,
        ];

        $host = $this
            ->find()
            ->where($conditions)
            ->first();

        return $host;
    }

    /**
     * @param \DelayedJobs\Model\Entity\Worker $worker Worker instance
     * @param int $status Status
     * @return void
     */
    public function setStatus(Worker $worker, int $status): void
    {
        $worker->status = $status;
        $this->save($worker);
    }
}
