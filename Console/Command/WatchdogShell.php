<?php

App::import('Core', 'Controller');
App::import('Component', 'DelayedJobs.Lock');

class WatchdogShell extends AppShell
{

    var $Lock;
    public $uses = array(
        'DelayedJobs.Host'
    );

    public function main()
    {
        $this->Lock = & new LockComponent();
        $this->Lock->lock('DelayedJobs.WatchdogShell.main');

        $this->stdout->styles('fail', array('text' => 'red', 'blink' => false));
        $this->stdout->styles('warning', array('text' => 'red', 'blink' => true));
        $this->stdout->styles('success', array('text' => 'green', 'blink' => false));
        $this->stdout->styles('info', array('text' => 'cyan', 'blink' => false));

        if (!$this->Host->checkConfig())
            throw new CakeException("Could not load config, check your loadAll settings in bootstrap.php");

        $this->out('<info>App Name: ' . Configure::read("delayed.jobs.service.name") . ' </info>');

        $workers = 1;
        if (isset($this->args[0]))
            $workers = $this->args[0];

        if (!is_numeric($workers))
            $workers = 1;

        $workers = $workers * 1;
        
        $this->PlatformStatus = ClassRegistry::init('PlatformStatus');
        $platform_status = $this->PlatformStatus->status();
        if ($platform_status["PlatformStatus"]["status"] != "online")
        {
            $this->out('<warning>Maintenance Mode: ' . Configure::read("delayed.jobs.service.name") . ' KILLING ALL WORKERS</warning>');
            $workers = 0;
        }

        if ($workers > Configure::read("dj.max.hosts"))
        {
            $workers = Configure::read("dj.max.hosts");
            $this->out('<error>Too many hosts (max_hosts:' . Configure::read("dj.max.hosts") . ')</error>');
        }
        $this->out('<info>Starting Watchdog: ' . $workers . ' Hosts</info>');

        try
        {

            $host_name = php_uname("a");
            $base_path = APP . "Console/Command/.././cake DelayedJobs.Host ";

            for ($i = 1; $i <= $workers; $i++)
            {
                $worker_name = Configure::read("dj.service.name") . "_worker" . $i;

                $host = $this->Host->findByHost($host_name, $worker_name);

                if (!$host)
                {
                    //## Host not found in database, start it
                    $p = new Process($base_path . $worker_name);

                    $pid = $p->getPid();

                    $response = $this->Host->Started($host_name, $worker_name, $pid);

                    $status = $response["Host"]["status"];
                    $host_id = $response["Host"]["id"];
                    $pid = $response["Host"]["pid"];

                    sleep(2);

                    if (!$p->status())
                    {
                        $this->Host->Remove($host_id);
                        $this->out('<error>Worker: ' . $worker_name . ' Could not be started, Trying to find process to kill it?</error>');

                        $check_pid = $p->getPidByName("DelayedJobs.Host " . $worker_name);

                        if ($check_pid)
                        {
                            $p->setPid($check_pid);
                            $p->stop();

                            $this->out('<success>Worker: ' . $worker_name . ' Found a proccess and killed it</success>');
                        }
                        else
                        {
                            $this->out('<error>Worker: ' . $worker_name . ' Could not find any processes to kill</error>');
                        }
                    }
                    else
                    {
                        if (!$response)
                        {
                            $p->stop();
                            $this->out('<error>Worker: ' . $worker_name . ' Could not be started</error>');
                        }
                        else
                        {
                            $this->out('<success>Worker: ' . $worker_name . ' Started (pid:' . $pid . ')</success>');
                        }
                    }
                }
                else
                {
                    $status = $host["Host"]["status"];
                    $host_id = $host["Host"]["id"];
                    $pid = $host["Host"]["pid"];

                    $p = new Process();
                    $p->setPid($host["Host"]["pid"]);

                    $process_running = false;
                    if ($p->status())
                        $process_running = true;

                    $details = $p->details();

                    if (strpos($details, "DelayedJobs.Host " . $worker_name) !== false)
                        $process_running = true;
                    else
                        $process_running = false;

                    if ($status == DJ_HOST_STATUS_IDLE)
                    {
                        //## Host is idle, need to start it

                        if ($process_running)
                        {
                            //## Process is actually running, update status
                            $this->Host->setStatus($host_id, DJ_HOST_STATUS_RUNNING);
                            $this->out('<info>Worker: ' . $worker_name . ' Idle, Changing status (pid:' . $pid . ')</info>');
                        }
                        else
                        {
                            //## Process is not running, delete record
                            $this->Host->Remove($host_id);
                            $this->out('<error>Worker: ' . $worker_name . ' Not running but reported IDLE state, Removing database record (pid:' . $pid . ')</error>');
                        }
                    }
                    elseif ($status == DJ_HOST_STATUS_RUNNING)
                    {
                        //## Host is running, please confirm
                        if ($process_running)
                        {
                            //## Process is actually running, update status
                            $this->Host->setStatus($host_id, DJ_HOST_STATUS_RUNNING);
                            $this->out('<success>Worker: ' . $worker_name . ' Running normally (pid:' . $pid . ')</success>');
                        }
                        else
                        {
                            //## Process is not running, delete record
                            $this->Host->Remove($host_id);
                            $this->out('<error>Worker: ' . $worker_name . ' DB reported running, cant find process, remove db (pid:' . $pid . ')</error>');
                        }
                    }
                    elseif ($status == DJ_HOST_STATUS_TO_KILL)
                    {
                        //## Kill it with fire
                        if ($process_running)
                        {
                            $p->stop();

                            sleep(2); //## Give the system time to kill the process

                            if ($p->status())
                            {
                                echo "Process Could not be stopped";
                            }
                        }

                        $this->Host->Remove($host_id);
                        $this->out('<error>Worker: ' . $worker_name . ' Killed (pid:' . $pid . ')</error>');
                    }
                    else
                    {
                        //## Something went wrong, horribly wrong
                        if ($process_running)
                        {
                            //## Process is actually running, update status
                            $this->Host->setStatus($host_id, DJ_HOST_STATUS_RUNNING);
                            $this->out('<info>Worker: ' . $worker_name . ' Unknown Status, but running, changing status (pid:' . $pid . ')</info>');
                        }
                        else
                        {
                            //## Process is not running, delete record
                            $this->Host->Remove($host_id);
                            $this->out('<error>Worker: ' . $worker_name . ' Unknown status and not running, removing host (pid:' . $pid . ')</error>');
                        }
                    }
                }
            }

            //## Check that no other or more processes are running, if they are found, kill them
            for ($i = $workers + 1; $i <= Configure::read("dj.max.hosts"); $i++)
            {
                $worker_name = Configure::read("dj.service.name") . "_worker" . $i;

                $host = $this->Host->findByHost($host_name, $worker_name);

                $p = new Process();

                if ($host)
                {
                    //## Host is in the database, need to remove it
                    $status = $host["Host"]["status"];
                    $host_id = $host["Host"]["id"];
                    $pid = $host["Host"]["pid"];

                    $p->setPid($pid);

                    $p->stop();

                    sleep(2);

                    $this->Host->remove($host_id);

                    $this->out('<error>Worker: ' . $worker_name . ' Too many hosts, killing (pid:' . $pid . ')</error>');
                }
                else
                {
                    //## No Host record found, just kill if it exists
                    $check_pid = $p->getPidByName("DelayedJobs.Host " . $worker_name);

                    if ($check_pid)
                    {
                        $p->setPid($check_pid);
                        $p->stop();

                        $this->out('<success>Worker: ' . $worker_name . ' Found a proccess too many and killed it</success>');
                    }
                    else
                    {
                        //$this->out('<error>Worker: ' . $worker_name . ' Nope</error>');
                    }
                }
            }
        } catch (Exception $exc)
        {
            $this->out('<fail>Failed: ' . $exc->getMessage() . '</fail>');
        }
        
        exit();
        
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

    public function getPidByName($name)
    {
        $out = array();
        exec("ps aux | grep '$name' | grep -v grep | awk '{ print $2 }' | head -1", $out);

        if (isset($out[0]))
            return $out[0];
        else
            return false;
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

    public function details()
    {
        $command = 'ps -p ' . $this->pid . " -f";
        exec($command, $op);
        if (isset($op[1]))
            return $op[1];
        else
            return false;
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
