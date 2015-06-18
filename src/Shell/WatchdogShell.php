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
     * Creates 2 * (cpu-count - 1) worker processes (Minimum of 2 workers)
     * @return int
     */
    protected function _autoWorker()
    {
        $worker_count = (int)exec('nproc') - 1;
        return ($worker_count >= 1 ? $worker_count : 1) * 2;
    }

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
        $hostname = php_uname('n');

        $this->out('App Name: <info>' . Configure::read('dj.service.name') . '</info>');
        $this->out('Hostname: <info>' . $hostname . '</info>');

        $this->_workers = (int)$this->param('workers');
        if ($this->_workers <= 0) {
            $this->_workers = 1;
        }

        $this->_workers *= 1;

        if ($this->_workers > Configure::read('dj.max.hosts')) {
            $this->_workers = Configure::read('dj.max.hosts');
            $this->out('<error>Too many hosts (max_hosts:' . Configure::read('dj.max.hosts') . ')</error>');
        }
        $this->out('Starting Watchdog: <info>' . $this->_workers . ' Hosts</info>');

        try {
            for ($i = 1; $i <= $this->_workers; $i++) {
                $this->_startWorker($i);
            }

            //## Check that no other or more processes are running, if they are found, kill them
            $max_workers = Configure::read('dj.max.hosts');
            for ($i = $this->_workers + 1; $i <= $max_workers; $i++) {
                $worker_name = Configure::read('dj.service.name') . '_worker' . $i;

                $host = $this->Hosts->findByHost($hostname, $worker_name);

                if ($host) {
                    //## Host is in the database, need to remove it
                    $this->_kill($host->pid, $worker_name);
                    $this->Hosts->delete($host);
                } else {
                    //## No Host record found, just kill if it exists
                    $check_pid = (new Process())->getPidByName('DelayedJobs.Host ' . $worker_name);

                    if ($check_pid) {
                        $this->_kill($check_pid, $worker_name);
                    }
                }
            }
        } catch (Exception $exc) {
            $this->out('<fail>Failed: ' . $exc->getMessage() . '</fail>');
        }

        $this->out('<success>' . $this->_workers . ' started.</success> Firing watchdog event.');

        $this->loadModel('DelayedJobs.DelayedJobs');
        $event = new Event('DelayedJobs.watchdog', $this->DelayedJobs);
        EventManager::instance()->dispatch($event);

        $this->out('Cleaning jobs.');
        $this->loadModel('DelayedJobs.DelayedJobs');
        $cleaned = $this->DelayedJobs->clean();
        $this->out(sprintf('<success>Cleaned:</success> %d jobs', $cleaned));

        $this->out('<success>!! All done !!</success>');
        $this->Lock->unlock('DelayedJobs.WorkerShell.main');
    }

    /**
     * @param int $pid PID to kill
     * @param string $worker_name Worker name
     * @return void
     */
    protected function _kill($pid, $worker_name)
    {
        $this->out(
            sprintf(
                '<info>To kill:</info> %s (pid: %s)',
                $worker_name,
                $pid
            )
        );

        $process = new Process();
        $process->setPid($pid);
        $process->stop();

        if ($process->status()) {
            $this->out(
                sprintf(
                    '<error>Could not stop:</error> %s (pid: %s)',
                    $worker_name,
                    $pid
                )
            );
        } else {
            $this->out(
                sprintf(
                    '<error>Killed:</error> %s (pid: %s)',
                    $worker_name,
                    $pid
                ),
                2
            );
        }
    }

    /**
     * @param $host_name
     * @param $worker_name
     * @return mixed
     */
    protected function _startHost($host_name, $worker_name)
    {
        $this->out(
            sprintf(
                '<info>Starting:</info> %s',
                $worker_name
            )
        );

        $base_path = self::BASEPATH;

        //## Host not found in database, start it
        $process = new Process($base_path . $worker_name);
        $pid = $process->getPid();
        $host = $this->Hosts->started($host_name, $worker_name, $pid);

        sleep(2);

        if (!$process->status()) {
            $this->Hosts->delete($host);
            $this->out(
                '<error>Worker: ' . $worker_name . ' Could not be started, Trying to find process to kill it?</error>'
            );

            $check_pid = $process->getPidByName('DelayedJobs.host ' . $worker_name);

            if ($check_pid) {
                $process->setPid($check_pid);
                $process->stop();

                $this->out(
                    '<success>Worker: ' . $worker_name . ' Found a process and killed it</success>'
                );
            } else {
                $this->out(
                    '<error>Worker: ' . $worker_name . ' Could not find any processes to kill</error>'
                );
            }
        } elseif (!$host) {
            $process->stop();
            $this->out(
                sprintf(
                    '<error>Could not start:</error> %s',
                    $worker_name
                ),
                2
            );
        } else {
            $this->out(
                sprintf(
                    '<success>Started:</success> %s (pid: %s)',
                    $worker_name,
                    $host->pid
                ),
                2
            );
        }

        return $host;
    }

    protected function _checkHost($host)
    {
        $process = new Process();
        $process->setPid($host->pid);
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
                    '<info>Worker: ' . $host->worker_name . ' Idle, Changing status (pid:' . $host->pid . ')</info>',
                    2
                );
            } else {
                //## Process is not running, delete record
                $this->Hosts->delete($host);
                $this->out(
                    '<error>Worker: ' . $host->worker_name . ' Not running but reported IDLE state, Removing database
                     record (pid:' . $host->pid . ')</error>',
                    2
                );
            }
        } elseif ($host->status == HostsTable::STATUS_RUNNING) {
            //## Host is running, please confirm
            if ($process_running) {
                //## Process is actually running, update status
                $this->Hosts->setStatus($host, HostsTable::STATUS_RUNNING);
                $this->out(
                    sprintf(
                        '<success>Running:</success> %s (pid: %s)',
                        $host->worker_name,
                        $host->pid
                    )
                );
            } else {
                //## Process is not running, delete record and try to start it
                $this->Hosts->delete($host);
                $this->out(
                    sprintf(
                        '<error>Not running, restarting:</error> %s (pid: %s)',
                        $host->worker_name,
                        $host->pid
                    )
                );
                $this->_startHost($host->host_name, $host->worker_name);
            }
        } elseif ($host->status == HostsTable::STATUS_TO_KILL) {
            //## Kill it with fire
            if ($process_running) {
                $this->_kill($host->pid, $host->worker_name);
            }
            $this->Hosts->delete($host);
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
        $host_name = php_uname('n');

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
                    'default' => $this->_autoWorker()
                ]
            );

        return $options;
    }

}