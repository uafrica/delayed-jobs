<?php

App::import('Core', 'Controller');
App::import('Component', 'DelayedJobs.Lock');

class WorkerShell extends AppShell
{

    var $Lock;
    public $uses = array(
        'DelayedJobs.DelayedJob'
    );
    
    
    

    public function main()
    {
        $job_id = null;

        if (isset($this->args[0]))
            $job_id = $this->args[0];

        if ($job_id === null)
        {
            throw new Exception("No Job ID received");
        }
        
        
        
        

        $this->Lock = new LockComponent();
        $this->Lock->lock('DelayedJobs.WorkerShell.main.' . $job_id);
        
        $this->stdout->styles('fail', array('text' => 'red', 'blink' => false));
        $this->stdout->styles('success', array('text' => 'green', 'blink' => false));
        $this->stdout->styles('info', array('text' => 'cyan', 'blink' => false));

        //echo getmypid() . "\n";
        //echo $this->args[0] . "\n";

        $this->out('<info>Starting Job: ' . $job_id . '</info>');

        try
        {
            $job = $this->DelayedJob->get($job_id);
            if ($job)
            {
                //## First check if job is not locked
                if($job["DelayedJob"]["status"] == DJ_STATUS_SUCCESS)
                {
                    throw new Exception("Job previously completed, Why is is being called");
                }
                
                
                
                if($job["DelayedJob"]["status"] == DJ_STATUS_BURRIED)
                {
                    throw new Exception("Job Failed too many times, but why was it called again");
                }
                
                
                //## Execute Job'
                //debug($job);

                $model = ClassRegistry::init($job["DelayedJob"]["class"]);

                if ($model === null)
                {
                    throw new Exception("Model does not exists (" . $model . ")");
                }

                if (method_exists($model, $job["DelayedJob"]["method"]))
                {
                    $method = $job["DelayedJob"]["method"];
                    $response = $model->$method($job["DelayedJob"]["payload"]);

                    if($response)
                    {
                        $this->DelayedJob->completed($job_id);
                        $this->out('<success>Job ' . $job_id . ' Completed</success>');
                    }
                    else
                    {
                        throw new Exception("Invalid response received");
                    }
                }
                else
                {
                    throw new Exception("Method does not exists ({$model->name}::" . $job["DelayedJob"]["method"] . ")");
                }
            }
        } catch (Exception $exc)
        {
            //sleep(rand(5,10));
            //## Job Failed
            $this->DelayedJob->failed($job_id, $exc->getMessage());
            //debug($exc->getMessage());
            $this->out('<fail>Job ' . $job_id . ' Failed ('. $exc->getMessage() .')</fail>');
            //echo $exc->getTraceAsString();
           // $this->Lock->lock('DelayedJobs.WorkerShell.main.' . $job_id);
        }

        $this->Lock->unlock('DelayedJobs.WorkerShell.main.' . $job_id);
        
        
    }

}
