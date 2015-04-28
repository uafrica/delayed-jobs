<?php

namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Cake\Event\Event;
use Cake\Event\EventManager;
use DelayedJobs\Lock;
use DelayedJobs\Model\Table\HostsTable;
use DelayedJobs\Process;

/**
 * Class WatchdogShell
 * @package DelayedJobs\Shell
 * @property \DelayedJobs\Model\Table\HostsTable $Hosts
 *
 */
class WatchdogShell extends Shell
{

    const BASEPATH = ROOT . '/bin/cake DelayedJobs.host ';
    public $Lock;
    public $modelClass = 'DelayedJobs.Hosts';
    protected $_workers;

    /**
     *
     */
    public function main()
    {
        $this->Lock = new Lock();
        if (!$this->Lock->lock('DelayedJobs.WatchdogShell.main')) {
            $this->_stop(1);
        }

        if (!$this->Hosts->checkConfig()) {
            throw new Exception('Could not load config, check your load settings in bootstrap.php');
        }

        $this->out('<info>App Name: ' . Configure::read('dj.service.name') . ' </info>');

        $this->_workers = $this->param('workers');
        if (!is_numeric($this->_workers)) {
            $this->_workers = 1;
        }

        $this->_workers *= 1;

//        $this->PlatformStatus = ClassRegistry::init('PlatformStatus');
//        $platform_status = $this->PlatformStatus->status();
//        if ($platform_status['PlatformStatus']['status'] != 'online')
//        {
//            $this->out('<warning>Maintenance Mode: ' . Configure::read('delayed.jobs.service.name') . ' KILLING ALL WORKERS</warning>');
//            $workers = 0;
//        }


        if ($this->_workers > Configure::read('dj.max.hosts')) {
            $this->_workers = Configure::read('dj.max.hosts');
            $this->out('<error>Too many hosts (max_hosts:' . Configure::read('dj.max.hosts') . ')</error>');
        }
        $this->out('<info>Starting Watchdog: ' . $this->_workers . ' Hosts</info>');

        try {
            $host_name = php_uname('a');
            for ($i = 1; $i <= $this->_workers; $i++) {
                $this->_startWorker($i);
            }

            //## Check that no other or more processes are running, if they are found, kill them
            for ($i = $this->_workers + 1; $i <= Configure::read('dj.max.hosts'); $i++) {
                $worker_name = Configure::read('dj.service.name') . '_worker' . $i;

                $host = $this->Hosts->findByHost($host_name, $worker_name);

                $process = new Process();

                if ($host) {
                    //## Host is in the database, need to remove it
                    $process->setPid($host->pid);

                    $process->stop();

                    sleep(2);

                    $this->Hosts->remove($host);

                    $this->out(
                        '<error>Worker: ' . $worker_name . ' Too many hosts, killing (pid:' . $host->pid . ')</error>'
                    );
                } else {
                    //## No Host record found, just kill if it exists

                    $check_pid = $process->getPidByName('DelayedJobs.Host ' . $worker_name);

                    if ($check_pid) {
                        $process->setPid($check_pid);
                        $process->stop();

                        $this->out(
                            '<success>Worker: ' . $worker_name . ' Found a proccess too many and killed it</success>'
                        );
                    } else {
                        //$this->out('<error>Worker: ' . $worker_name . ' Nope</error>');
                    }
                }
            }
        } catch (Exception $exc) {
            //sleep(rand(5,10));
            //## Job Failed
            //$this->DelayedJob->failed($job_id, $exc->getMessage());
            //debug($exc->getMessage());
            $this->out('<fail>Failed: ' . $exc->getMessage() . '</fail>');
            //echo $exc->getTraceAsString();
        }

        $this->out('<success>' . $this->_workers . ' started.</success> Firing watchdog event.');

        $this->loadModel('DelayedJobs.DelayedJobs');
        $event = new Event('DelayedJobs.watchdog', $this->DelayedJobs);
        EventManager::instance()->dispatch($event);

        $this->out('<success>!! All done !!</success>');
        $this->Lock->unlock('DelayedJobs.WorkerShell.main');
    }

    /**
     * @param $host_name
     * @param $worker_name
     * @return mixed
     */
    protected function _startHost($host_name, $worker_name)
    {
        $base_path = self::BASEPATH;

        //## Host not found in database, start it
        $process = new Process($base_path . $worker_name);

        $pid = $process->getPid();

        $host = $this->Hosts->started($host_name, $worker_name, $pid);

        sleep(2);

        if (!$process->status()) {
            $this->Hosts->remove($host);
            $this->out(
                '<error>Worker: ' . $worker_name . ' Could not be started, Trying to find process to kill it?</error>'
            );

            $check_pid = $process->getPidByName('DelayedJobs.host ' . $worker_name);

            if ($check_pid) {
                $process->setPid($check_pid);
                $process->stop();

                $this->out(
                    '<success>Worker: ' . $worker_name . ' Found a proccess and killed it</success>'
                );
            } else {
                $this->out(
                    '<error>Worker: ' . $worker_name . ' Could not find any processes to kill</error>'
                );
            }
        } else {
            if (!$host) {
                $process->stop();
                $this->out('<error>Worker: ' . $worker_name . ' Could not be started</error>');
            } else {
                $this->out('<success>Worker: ' . $worker_name . ' Started (pid:' . $host->pid . ')</success>');
            }
        }

        return $host;
    }

    protected function _checkHost($host)
    {
        $process = new Process();
        $process->setPid($host->pid);

        $process_running = false;
        if ($process->status()) {
            $process_running = true;
        }

        $details = $process->details();

        if (strpos($details, 'DelayedJobs.host ' . $host->worker_name) !== false) {
            $process_running = true;
        } else {
            $process_running = false;
        }

        if ($host->status == HostsTable::STATUS_IDLE) {
            //## Host is idle, need to start it

            if ($process_running) {
                //## Process is actually running, update status
                $this->Hosts->setStatus($host, HostsTable::STATUS_RUNNING);
                $this->out(
                    '<info>Worker: ' . $host->worker_name . ' Idle, Changing status (pid:' . $host->pid . ')</info>'
                );
            } else {
                //## Process is not running, delete record
                $this->Hosts->remove($host);
                $this->out(
                    '<error>Worker: ' . $host->worker_name . ' Not running but reported IDLE state, Removing database record (pid:' . $host->pid . ')</error>'
                );
            }
        } elseif ($host->status == HostsTable::STATUS_RUNNING) {
            //## Host is running, please confirm
            if ($process_running) {
                //## Process is actually running, update status
                $this->Hosts->setStatus($host, HostsTable::STATUS_RUNNING);
                $this->out(
                    '<success>Worker: ' . $host->worker_name . ' Running normally (pid:' . $host->pid . ')</success>'
                );
            } else {
                //## Process is not running, delete record
                $this->Hosts->remove($host);
                $this->out(
                    '<error>Worker: ' . $host->worker_name . ' DB reported running, cant find process, remove db (pid:' . $host->pid . ')</error>'
                );
            }
        } elseif ($host->status == HostsTable::STATUS_TO_KILL) {
            //## Kill it with fire
            if ($process_running) {
                $process->stop();

                sleep(2); //## Give the system time to kill the process

                if ($process->status()) {
                    echo 'Process Could not be stopped';
                }
            }

            $this->Hosts->remove($host);
            $this->out('<error>Worker: ' . $host->worker_name . ' Killed (pid:' . $host->pid . ')</error>');
        } else {
            //## Something went wrong, horribly wrong
            if ($process_running) {
                //## Process is actually running, update status
                $this->Hosts->setStatus($host, HostsTable::STATUS_RUNNING);
                $this->out(
                    '<info>Worker: ' . $host->worker_name . ' Unknown Status, but running, changing status (pid:' . $host->pid . ')</info>'
                );
            } else {
                //## Process is not running, delete record
                $this->Hosts->remove($host);
                $this->out(
                    '<error>Worker: ' . $host->worker_name . ' Unknown status and not running, removing host (pid:' . $host->pid . ')</error>'
                );
            }
        }
    }

    protected function _startWorker($worker_number)
    {
        $host_name = php_uname('a');

        $worker_name = Configure::read('dj.service.name') . '_worker' . $worker_number;

        $host = $this->Hosts->findByHost($host_name, $worker_name);

        if (!$host) {
            $this->_startHost($host_name, $worker_name);
        } else {
            $this->_checkHost($host);
        }
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options
            ->addOption(
                'workers',
                [
                    'help' => 'Number of workers to run',
                    'default' => 1
                ]
            );

        return $options;
    }

}