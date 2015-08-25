<?php

namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\Time;
use DelayedJobs\Lock;
use DelayedJobs\Model\Table\DelayedJobsTable;
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
     * Creates (cpu-count - 1) worker processes (Minimum of 1 worker)
     * @return int
     */
    protected function _autoWorker()
    {
        $worker_count = (int)exec('nproc') - 1;
        return $worker_count >= 1 ? $worker_count : 1;
    }

    /**
     * @return void
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
        if ($this->_workers < 0) {
            $this->_workers = 1;
        }

        $this->_workers *= (int)$this->param('parallel');

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

        $this->out('<success>' . $this->_workers . ' started.</success>.');

        $this->recuring();
        $this->clean();

        $this->out('<success>!! All done !!</success>');
        $this->Lock->unlock('DelayedJobs.WorkerShell.main');
    }

    public function recuring()
    {
        $this->out('Firing recuring event.');
        $event = new Event('DelayedJobs.recuring');
        $event->result = [];
        EventManager::instance()->dispatch($event);

        $this->loadModel('DelayedJobs.DelayedJobs');
        $this->out(__('{0} jobs to queue', count($event->result)), 1, Shell::VERBOSE);
        foreach ($event->result as $job) {
            if ($this->DelayedJobs->jobExists($job)) {
                $this->out(__('  <error>Already queued:</error> {0}::{1}', $job['class'], $job['method']), 1, Shell::VERBOSE);
                continue;
            }

            $dj_data = $job + [
                'priority' => 100,
                'options' => ['max_retries' => 5],
                'run_at' => new Time('+30 seconds')
            ];

            $job_event = new Event('DelayedJob.queue', $dj_data);
            EventManager::instance()->dispatch($job_event);
            $this->out(__('  <success>Queued:</success> {0}::{1}', $job['class'], $job['method']), 1, Shell::VERBOSE);
        }
    }

    public function clean()
    {
        $this->out('Cleaning jobs.');
        $this->loadModel('DelayedJobs.DelayedJobs');
        $cleaned = $this->DelayedJobs->clean();
        $this->out(sprintf('<success>Cleaned:</success> %d jobs', $cleaned));
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

    /**
     * Reloads all running hosts
     * @return void
     */
    public function reload()
    {
        $hostname = php_uname('n');

        $hosts = $this->Hosts->findByHostName($hostname);
        $host_count = $hosts->count();

        $this->out('Killing ' . $host_count . ' running hosts.', 1, Shell::VERBOSE);
        foreach ($hosts as $host) {
            $this->_kill($host->pid, $host->worker_name);
            $this->Hosts->delete($host);
        }

        $this->out('Restarting ' . $host_count . ' hosts.', 1, Shell::VERBOSE);
        for ($i = 1; $i <= $host_count; $i++) {
            $this->_startWorker($i);
        }
    }

    public function monitor()
    {
        $status_map = [
            DelayedJobsTable::STATUS_NEW => 'New',
            DelayedJobsTable::STATUS_BUSY => 'Busy',
            DelayedJobsTable::STATUS_BURRIED => 'Buried',
            DelayedJobsTable::STATUS_SUCCESS => 'Success',
            DelayedJobsTable::STATUS_KICK => 'Kicked',
            DelayedJobsTable::STATUS_FAILED => 'Failed',
            DelayedJobsTable::STATUS_UNKNOWN => 'Unknown',
        ];
        $this->loadModel('DelayedJobs.DelayedJobs');
        $hostname = php_uname('n');

        while (true) {
            $statuses = $this->DelayedJobs->find('list', [
                    'keyField' => 'status',
                    'valueField' => 'counter'
                ])
                ->select([
                    'status',
                    'counter' => $this->DelayedJobs->find()
                        ->func()
                        ->count('id')
                ])
                ->group(['status'])
                ->toArray();
            $created_per_second_hour = $this->DelayedJobs->jobsPerSecond();
            $created_per_second_15 = $this->DelayedJobs->jobsPerSecond([], 'created', '+15 minutes');
            $created_per_second_5 = $this->DelayedJobs->jobsPerSecond([], 'created', '+5 minutes');
            $completed_per_second_hour = $this->DelayedJobs->jobsPerSecond([
                'status' => DelayedJobsTable::STATUS_SUCCESS
            ], 'modified');
            $completed_per_second_15 = $this->DelayedJobs->jobsPerSecond([
                'status' => DelayedJobsTable::STATUS_SUCCESS
            ], 'modified', '+15 minutes');
            $completed_per_second_5 = $this->DelayedJobs->jobsPerSecond([
                'status' => DelayedJobsTable::STATUS_SUCCESS
            ], 'modified', '+5 minutes');
            $last_failed = $this->DelayedJobs->find()
                ->select(['id', 'last_message', 'failed_at'])
                ->where([
                    'status' => DelayedJobsTable::STATUS_FAILED
                ])
                ->order([
                    'failed_at' => 'DESC'
                ])
                ->first();
            $last_burried = $this->DelayedJobs->find()
                ->select(['id', 'last_message', 'failed_at'])
                ->where([
                    'status' => DelayedJobsTable::STATUS_BURRIED
                ])
                ->order([
                    'failed_at' => 'DESC'
                ])
                ->first();
            $host_count = $this->Hosts->find()->count();
            $running_jobs = $this->DelayedJobs
                ->find()
                ->where([
                    'status' => DelayedJobsTable::STATUS_BUSY
                ])
                ->all();

            $this->clear();
            $this->out(__('Delayed Jobs monitor <info>{0} - {1}</info>', $hostname, date('H:i:s')));
            $this->hr();
            $this->out(__('Running hosts: <info>{0}</info>', $host_count));
            $this->out(__('Created / s: <info>{0}</info> <info>{1}</info> <info>{2}</info>', $created_per_second_5, $completed_per_second_15, $completed_per_second_hour));
            $this->out(__('Completed /s : <info>{0}</info> <info>{1}</info> <info>{2}</info>', $completed_per_second_5,
                $completed_per_second_15, $completed_per_second_hour));
            $this->hr();

            $this->out('Total job count');
            $this->out('');
            foreach ($status_map as $status => $name) {
                $this->out(__('{0}: <info>{1}</info>', $name, (isset($statuses[$status]) ? $statuses[$status] : 0)));
            }

            if (count($running_jobs) > 0) {
                $this->hr();
                $this->out('Running jobs:');
                $running_job_text = [];
                foreach ($running_jobs as $running_job) {
                    $running_job_text[] = __('{0} :: {1}', $running_job->id, $running_job->locked_by);
                }
                $this->out(implode(' | ', $running_job_text));
            }
            $this->hr();
            if ($last_failed) {
                $this->out(__('<info>{0}</info> failed because <info>{1}</info> at <info>{2}</info>', $last_failed->id, $last_failed->last_message, $last_failed->failed_at->i18nFormat()));
            }
            if ($last_burried) {
                $this->out(__('<info>{0}</info> was burried because <info>{1}</info> at <info>{2}</info>', $last_burried->id,
                    $last_burried->last_message, $last_burried->failed_at->i18nFormat()));
            }
            usleep(250000);
        }
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options
            ->addSubcommand('clean', [
                'help' => 'Cleans out jobs that are completed and older than 4 weeks'
            ])
            ->addSubcommand('recuring', [
                'help' => 'Fires the recuring event and creates the initial recuring job instance'
            ])
            ->addSubcommand('monitor', [
                'help' => 'Allows monitoring of the delayed job service'
            ])
            ->addSubcommand('reload', [
                'help' => 'Restarts all running worker hosts'
            ])
            ->addOption(
                'parallel',
                [
                    'help' => 'Number of parallel workers (worker count is multiplied by this)',
                    'default' => 1
                ]
            )
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