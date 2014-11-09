<?php

App::uses('AppController', 'Controller');

class DelayedJobsController extends AppController
{
    public function beforeFilter()
    {
        parent::beforeFilter();
        $this->Auth->allow($this->action);
    }
    
    public function index()
    {
        
    }
    
    public function run($id = null)
    {
        $this->layout = false;
        $this->autoRender = false;
        
        $options = array('conditions' => array('DelayedJob.id' => $id));
        
        $job = $this->DelayedJob->find('first', $options);
        
        if(!$job)
            throw new NotFoundException("Could not find job");
        
        if($job["DelayedJob"]["status"] == 4)
        {
            throw new CakeException("Job Already Completed");
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