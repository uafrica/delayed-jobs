<?php

namespace DelayedJobs\Controller;

use App\Controller\AppController;

class DelayedJobsController extends AppController
{

    public $components = array('Paginator', 'Session');

    public function beforeFilter(Event $event)
    {
        parent::beforeFilter($event);
        //$this->Auth->allow($this->action);
    }

    public function index()
    {
        $jobs_per_second = $this->DelayedJob->jobsPerSecond();

        $this->set(compact('jobs_per_second'));

        $this->DelayedJob->recursive = 0;

        $this->Paginator->settings = array(
            'order' => array('DelayedJob.created' => 'DESC'),
            'limit' => 50,
        );

        $delayedJobs = $this->Paginator->paginate();

        $this->set('delayedJobs', $delayedJobs);
    }

    /**
     * view method
     *
     * @throws NotFoundException
     * @param string $id
     * @return void
     */
    public function view($id = null)
    {
        if (!$this->DelayedJob->exists($id)) {
            throw new NotFoundException(__('Invalid webhook request'));
        }
        $options = array('conditions' => array('DelayedJob.' . $this->DelayedJob->primaryKey => $id));
        $this->set('DelayedJob', $this->DelayedJob->find('first', $options));
    }

    public function run($id = null)
    {
        $this->layout = false;
        $this->autoRender = false;

        $options = array('conditions' => array('DelayedJob.id' => $id));

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
