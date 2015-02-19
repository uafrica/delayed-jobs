<?php

namespace DelayedJobs\Controller;

use App\Controller\AppController;
use Cake\Event\Event;
use DelayedJobs\Model\Table\DelayedJobsTable;

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

    public function run($id = null)
    {
        $this->layout = false;
        $this->autoRender = false;

        $options = ['conditions' => ['DelayedJob.id' => $id]];

        $job = $this->DelayedJob->find('first', $options);

        if (!$job) {
            throw new NotFoundException("Could not find job");
        }

        if ($job["DelayedJob"]["status"] == 4) {
            throw new \Exception("Job Already Completed");
        }

        debug($job);

        $Object = ClassRegistry::init($job["DelayedJob"]["class"]);

        $method = $job["DelayedJob"]["method"];
        $payload = unserialize($job["DelayedJob"]["payload"]);

        $response = $Object->$method($payload);


        debug($response);

        exit();
    }
}
