<?php

namespace DelayedJobs\Controller;

use App\Controller\AppController;
use Cake\Event\Event;
use DelayedJobs\Model\Table\DelayedJobsTable;

/**
 * Class DelayedJobsController
 * @package DelayedJobs\Controller
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class DelayedJobsController extends AppController
{

    public $components = [
        'Crud.Crud' => [
            'actions' => [
                'Crud.Index',
                'Crud.View',
            ]
        ]
    ];

    /**
     * @param Event $event
     */
    public function beforeFilter(Event $event)
    {
        parent::beforeFilter($event);
        //$this->Auth->allow($this->action);
    }

    /**
     * @return mixed
     */
    public function index()
    {
        $jobs_per_second = $this->DelayedJobs->jobsPerSecond();

        $this->set(compact('jobs_per_second'));

        return $this->Crud->execute();
    }

    public function view($id)
    {
        return $this->Crud->execute();
    }

    /**
     * @param null $id Job ID.
     * @return \Cake\Network\Response|void
     */
    public function run($id = null)
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
}
