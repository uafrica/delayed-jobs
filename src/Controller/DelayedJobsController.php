<?php

namespace DelayedJobs\Controller;

use App\Controller\AppController;
use Cake\Event\Event;
use DelayedJobs\Model\Table\DelayedJobsTable;

/**
 * Class DelayedJobsController
 * @package DelayedJobs\Controller
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

    public function beforeFilter(Event $event)
    {
        parent::beforeFilter($event);
        //$this->Auth->allow($this->action);
    }

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

    public function run($id = null)
    {
        $this->layout = false;
        $this->autoRender = false;

        $job = $this->DelayedJobs->get($id);

        if ($job->status === DelayedJobsTable::STATUS_SUCCESS) {
            throw new \Exception("Job Already Completed");
        }

        $response = $job->execute();

        debug($response);

        exit();
    }
}
