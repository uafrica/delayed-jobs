<?php
App::import('Core', 'Controller');
App::import('Component', 'DelayedJobs.Lock');

class HostShell extends AppShell
{

    var $Lock;
    public $uses = array(
        'DelayedJobs.DelayedJob'
    );

    public function main()
    {
        $worker_name = "worker1";

        if (isset($this->args[0]))
            $worker_name = $this->args[0];

        $this->Lock = & new LockComponent();
        $this->Lock->lock('DelayedJobs.HostShell.main.' . $worker_name);

        $this->stdout->styles('fail', array('text' => 'red', 'blink' => false));
        $this->stdout->styles('success', array('text' => 'green', 'blink' => false));
        $this->stdout->styles('info', array('text' => 'cyan', 'blink' => false));

        $worker_id = $worker_name . " - " . php_uname("a");

        /*
         * Get Next Job
         * Get Exclusive Lock on Job
         * Fire Worker
         * Worker fires job
         * Worker monitors the exection time
         */

        $job_pids = array();

        $max_allowed_jobs = 1;

        //## Need to make sure that any running jobs for this host is in the array job_pids

        $running_jobs = $this->DelayedJob->getRunningByHost($worker_id);

        foreach ($running_jobs as $running_job)
        {
            $job_pids[$running_job["DelayedJob"]["id"]] = array(
                "pid" => $running_job["DelayedJob"]["pid"],
            );
        }

        while (true)
        {

            if (count($job_pids) >= $max_allowed_jobs)
            {
                //CakeLog::write('jobs', "Max Number of Jobs running");
            }
            else
            {
                $getOpenJob = $this->DelayedJob->getOpenJob($worker_id);

                if ($getOpenJob)
                {

                    $job = $getOpenJob["DelayedJob"];
                    //## Get Next Job to run
                    //## Lock the job

                    if (!isset($job_pids[$job["id"]]))
                    {

                        //$this->DelayedJob->lock($job["id"], $worker_id);
                        $options = $job["options"];

                        if (!isset($options["max_execution_time"]))
                            $options["max_execution_time"] = 25 * 60;

                        $path = APP . "Console/Command/.././cake DelayedJobs.Worker " . $job["id"];
                        $p = new Process($path);

                        $pid = $p->getPid();

                        $this->DelayedJob->setPid($job["id"], $pid);

                        $job_pids[$job["id"]] = array(
                            "pid" => $pid,
                            "start_time" => time(),
                            "max_execution_time" => $options["max_execution_time"],
                        );

                    }
                }
                else
                {
                    //CakeLog::write('jobs', "No Jobs");
                }
            }




            //## Check Status of Fired Jobs
            foreach ($job_pids as $index => $running_jobs)
            {
                $status = new Process();
                $status->setPid($running_jobs["pid"]);
                if (!$status->status())
                {
                    //## Make sure that this job is not marked as running

                    $t_job = $this->DelayedJob->get($index);

                    if ($t_job["DelayedJob"]["status"] == DJ_STATUS_BUSY)
                    {
                        $this->DelayedJob->failed($index, "Job not running, but db said it is, could be a runtime error");
                        //$this->DelayedJob->setStatus($index, DJ_STATUS_UNKNOWN);
                    }
                    unset($job_pids[$index]);
                    //CakeLog::write('jobs', "Job: " . $index . " No longer running");
                }
                else
                {
                    //## Check if job has not reached it max exec time

                    $busy_time = time() - $running_jobs["start_time"];

                    if ($busy_time > $running_jobs["max_execution_time"])
                    {
                        echo "Job " . $index . " Running too long, need to kill it\n";
                        $status->stop();

                        $this->DelayedJob->failed($index, "Job ran too long, killed");
                    }
                    else
                    {
                        //CakeLog::write('jobs', "Job: " . $index . " still running: " . $busy_time . " ");
                    }
                }
            }


            //## Sleep so that the system can rest
            sleep(2);
        }
    }

}

/* An easy way to keep in track of external processes.
 * Ever wanted to execute a process in php, but you still wanted to have somewhat controll of the process ? Well.. This is a way of doing it.
 * @compability: Linux only. (Windows does not work).
 * @author: Peec
 */

class Process
{

    private $pid;
    private $command;

    public function __construct($cl = false)
    {
        if ($cl != false)
        {
            $this->command = $cl;
            $this->runCom();
        }
    }

    private function runCom()
    {
        $command = 'nohup ' . $this->command . ' > /dev/null 2>&1 & echo $!';
        exec($command, $op);
        $this->pid = (int) $op[0];
    }

    public function setPid($pid)
    {
        $this->pid = $pid;
    }

    public function getPid()
    {
        return $this->pid;
    }

    public function status()
    {
        $command = 'ps -p ' . $this->pid;
        exec($command, $op);
        if (!isset($op[1]))
            return false;
        else
            return true;
    }

    public function start()
    {
        if ($this->command != '')
            $this->runCom();
        else
            return true;
    }

    public function stop()
    {
        $command = 'kill ' . $this->pid;
        exec($command);
        if ($this->status() == false)
            return true;
        else
            return false;
    }

}

?>
