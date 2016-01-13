<?php

namespace DelayedJobs\Controller;

use App\Controller\AppController;
use Cake\I18n\Time;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\Model\Table\DelayedJobsTable;

/**
 * Class DelayedJobsController
 * @package DelayedJobs\Controller
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class DelayedJobsController extends AppController
{

    /**
     * @return void
     * @codeCoverageIgnore
     */
    public function initialize()
    {
        parent::initialize();
        $this->loadComponent('Crud.Crud', [
            'actions' => [
                'listJobs' => 'Crud.Index',
                'Crud.View',
            ]
        ]);

        if (!$this->components()->has('Flash')) {
            $this->loadComponent('Flash');
        }
    }

    public function basicStats()
    {
        $this->loadModel('DelayedJobs.Hosts');

        $data = [];
        $data['statuses'] = $this->DelayedJobs->statusStats();
        $data['created_rate'] = $this->DelayedJobs->rates('created');
        $data['completed_rate'] = $this->DelayedJobs->rates('end_time', DelayedJobsTable::STATUS_SUCCESS);
        $data['hosts'] = $this->Hosts->find();

        $this->set('data', $data);
        $this->set('_serialize', 'data');
    }

    public function rabbitStats()
    {
        $data = [];
        $data['rabbit_status'] = AmqpManager::queueStatus();

        $this->set('data', $data);
        $this->set('_serialize', 'data');
    }

    public function runningJobs()
    {
        $data = [];
        $data['running_jobs'] = $this->DelayedJobs->find()
            ->select([
                'id',
                'group',
                'locked_by',
                'class',
                'method'
            ])
            ->where([
                'status' => DelayedJobsTable::STATUS_BUSY
            ]);
        $this->set('data', $data);
        $this->set('_serialize', 'data');
    }

    public function jobHistory()
    {
        $data = [];
        $data['last_completed'] = $this->DelayedJobs->find()
            ->select(['id', 'last_message', 'end_time', 'class', 'method'])
            ->where([
                'status' => DelayedJobsTable::STATUS_SUCCESS
            ])
            ->order([
                'end_time' => 'DESC'
            ])
            ->limit(5);
        $data['last_failed'] = $this->DelayedJobs->find()
            ->select(['id', 'last_message', 'failed_at', 'class', 'method'])
            ->where([
                'status' => DelayedJobsTable::STATUS_FAILED
            ])
            ->order([
                'failed_at' => 'DESC'
            ])
            ->limit(5);
        $data['last_buried'] = $this->DelayedJobs->find()
            ->select(['id', 'last_message', 'failed_at', 'class', 'method'])
            ->where([
                'status' => DelayedJobsTable::STATUS_BURRIED
            ])
            ->order([
                'failed_at' => 'DESC'
            ])
            ->limit(5);
        $time_diff = $this->DelayedJobs->find()
            ->func()
            ->timeDiff([
                'end_time' => 'literal',
                'start_time' => 'literal'
            ]);
        $data['longest_running'] = $this->DelayedJobs->find()
            ->select(['id', 'group', 'class', 'method', 'diff' => $time_diff])
            ->where([
                'status' => DelayedJobsTable::STATUS_SUCCESS
            ])
            ->orderDesc($time_diff)
            ->first();

        $this->set('data', $data);
        $this->set('_serialize', 'data');
    }

    /**
     * @return mixed
     */
    public function index()
    {
    }

    public function listJobs() {
        return $this->Crud->execute();
    }

    public function view()
    {
        return $this->Crud->execute();
    }

    /**
     * Runs a delayed job
     *
     * @param int $id Job ID.
     * @return \Cake\Network\Response|void
     */
    public function run($id)
    {
        $this->layout = false;
        $this->autoRender = false;

        $job = $this->DelayedJobs->get($id);

        if ($job->status === DelayedJobsTable::STATUS_SUCCESS) {
            $this->Flash->error("Job Already Completed");
            return $this->redirect(['action' => 'index']);
        }

        $response = $job->execute();

        $this->Flash->message("Job Completed: " . $response);
        return $this->redirect(['action' => 'index']);
    }

    /**
     * @return \Cake\Network\Response|void
     */
    public function inject()
    {
        $number_jobs = $this->request->query('count') ?: 1;

        for ($i = 0; $i < $number_jobs; $i++) {
            $delayed_job = $this->DelayedJobs->newEntity([
                'group' => 'test',
                'class' => 'DelayedJobs\\Worker\\TestWorker',
                'method' => 'test',
                'payload' => [
                    'type' => $this->request->query('type') ?: 'success'
                ],
                'options' => [],
                'priority' => rand(1, 9) * 10 + 100,
                'run_at' => new Time('+10 seconds'),
                'status' => DelayedJobsTable::STATUS_NEW,
            ]);
            $this->DelayedJobs->save($delayed_job);
        }

        return $this->redirect([
            'action' => 'index'
        ]);
    }
}
