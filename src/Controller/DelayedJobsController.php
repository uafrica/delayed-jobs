<?php

namespace DelayedJobs\Controller;

use App\Controller\AppController;
use DelayedJobs\Model\Table\DelayedJobsTable;

/**
 * Class DelayedJobsController
 * @package DelayedJobs\Controller
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class DelayedJobsController extends AppController
{

    public function initialize()
    {
        parent::initialize();
        $this->loadComponent('Crud.Crud', [
            'actions' => [
                'Crud.Index',
                'Crud.View',
            ]
        ]);

        if (!$this->components()->has('Flash')) {
            $this->loadComponent('Flash');
        }
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
}
