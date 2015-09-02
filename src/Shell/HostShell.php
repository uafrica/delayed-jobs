<?php
namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use Cake\Core\Configure;
use DelayedJobs\Lock;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Model\Table\HostsTable;
use DelayedJobs\Process;

class HostShell extends Shell
{
    const UPDATETIMER = 5; //In seconds
    public $Lock;
    public $modelClass = 'DelayedJobs.DelayedJobs';
    protected $_workerId;
    protected $_workerName;
    protected $_workerCount = 1;
    protected $_runningJobs = [];
    protected $_host;

    protected function _welcome()
    {
        $hostname = php_uname('n');

        $this->clear();
        $this->out('Hostname: <info>' . $hostname . '</info>', 1, Shell::VERBOSE);
        $this->hr();
    }

    public function main()
    {
        $this->out(__('Booting... My PID is <info>{0}</info>', getmypid()), 1, Shell::VERBOSE);

        //Wait 5 seconds for watchdog to finish
        sleep(5);

        $this->loadModel('DelayedJobs.Hosts');
        $host_name = php_uname('n');
        $this->_workerName = Configure::read('dj.service.name');

        if (isset($this->args[0])) {
            $this->_workerName = $this->args[0];
        }

        $this->Lock = new Lock();
        if (!$this->Lock->lock('DelayedJobs.HostShell.main')) {
            $this->_stop(1);
        }

        $this->_host = $this->Hosts
            ->find()
            ->where([
                'host_name' => $host_name,
                'pid' => getmypid()
            ])
            ->first();

        $this->_workerId = $host_name . '.' . $this->_workerName;
        $this->_updateWorkerCount();

        /*
         * Get Next Job
         * Get Exclusive Lock on Job
         * Fire Worker
         * Worker fires job
         * Worker monitors the exection time
         */

        //## Need to make sure that any running jobs for this host is in the array job_pids
        $this->out(__('<info>Started up:</info> {0}', $this->_workerId), 1, Shell::VERBOSE);
        $start_time = time();
        while (true) {
            $this->_startWorkers();
            usleep(50000);

            $this->_updateRunning();
            $this->_checkRunning();

            //Every couple of seconds we update our host entry to catch changes to worker count, or self shutdown
            if (time() - $start_time >= self::UPDATETIMER) {
                $this->out('<info>Updating myself...</info>', 2, Shell::VERBOSE);
                $this->_host = $this->Hosts->find()
                    ->where([
                        'host_name' => $host_name,
                        'pid' => getmypid()
                    ])
                    ->first();
                $start_time = time();
                $this->_updateWorkerCount();
            }

            if ($this->_host && $this->_host->status === HostsTable::STATUS_SHUTDOWN && empty($this->_runningJobs)) {
                $this->out('Time to die :(', 1, Shell::VERBOSE);
                break;
            }
        }

        if ($this->_host) {
            $this->Hosts->delete($this->_host);
        }
    }

    protected function _updateWorkerCount()
    {
        $this->_workerCount = $worker_count = $this->param('workers') ?:
            ($this->_host ? $this->_host->worker_count : 1);
    }

    protected function _checkRunning()
    {
        foreach ($this->_runningJobs as $job_id => &$running_job) {
            $job = $running_job['job'];

            $this->out(__('Job status: {0} :: ', $job_id), 0, Shell::VERBOSE);

            if ($job->locked_by !== $this->_workerId) {
                //Not our job, why are we looking at it?
                $this->out(__('<error>Not our job - ignoring it</error>'), 1, Shell::VERBOSE);
                unset($this->_runningJobs[$job_id]);
                continue;
            }

            $status = new Process();
            $status->setPid($running_job['pid']);
            $process_running = $status->status();
            if (!$process_running && $job->status === DelayedJobsTable::STATUS_BUSY) {
                //## Make sure that this job is not marked as running
                    $this->DelayedJobs->failed(
                        $job,
                        'Job not running, but db said it is, could be a runtime error'
                    );
                    unset($this->_runningJobs[$job_id]);
                    $this->out(__('<error>Job not running, but should be</error>'), 1, Shell::VERBOSE);
            } elseif (!$process_running) {
                $time = time() - (isset($running_job['start_time']) ? $running_job['start_time'] : time());
                unset($this->_runningJobs[$job_id]);
                $this->out(__('<success>Job\'s done:</success> took {0} seconds', $time), 1, Shell::VERBOSE);
            } else {
                //## Check if job has not reached it max exec time
                $busy_time = time() - $running_job['start_time'];

                if (isset($running_job['max_execution_time']) && $busy_time > $running_job['max_execution_time']) {
                    $this->out(__('<error>Job timeout</error>'), 1, Shell::VERBOSE);
                    $status->stop();

                    $this->DelayedJobs->failed($job, 'Job ran too long, killed');
                    unset($this->_runningJobs[$job_id]);
                } else {
                    $this->out(__('<comment>Still running</comment> - {0} seconds', $busy_time), 1, Shell::VERBOSE);
                }
            }
        }
    }

    protected function _updateRunning()
    {
        $db_jobs = $this->DelayedJobs->getByHost($this->_workerId);
        foreach ($db_jobs as $running_job) {
            if (empty($this->_runningJobs[$running_job->id])) {
                $this->_runningJobs[$running_job->id] = [
                    'pid' => $running_job->pid,
                ];
            }
            $this->_runningJobs[$running_job->id]['job'] = $running_job;
        }
    }

    protected function _startWorkers()
    {
        $start_time = time();
        while (count($this->_runningJobs) < $this->_workerCount)
        {
            $worker_started = $this->_startWorker();

            //No jobs available, or we've timed out on this round
            if (!$worker_started || time() - $start_time > 2) {
                break;
            }
        }

        $this->out(__('Full with <info>{0}</info>', count($this->_runningJobs)), 1, Shell::VERBOSE);
    }

    protected function _startWorker()
    {
        if ($this->_host && ($this->_host->status === HostsTable::STATUS_SHUTDOWN ||
            $this->_host->status === HostsTable::STATUS_TO_KILL)) {
            return false;
        }

        if (count($this->_runningJobs) >= $this->_workerCount) {
            return false;
        }

        $job = $this->DelayedJobs->getOpenJob($this->_workerId);

        if (!$job) {
            return false;
        }

        $this->out(__('<success>Starting job:</success> {0}', $job->id), 1, Shell::VERBOSE);
        if (isset($this->_runningJobs[$job->id])) {
            $this->out(__(' - <error>Already have this job</error>'), 1, Shell::VERBOSE);
            return true;
        }

        $options = (array)$job->options;

        $this->out(__(' - <info>Runner: </info> {0}::{1}', $job->class, $job->method), 1, Shell::VERBOSE);

        if (!isset($options['max_execution_time'])) {
            $options['max_execution_time'] = 25 * 60;
        }

        $path = ROOT . '/bin/cake DelayedJobs.worker -q ' . $job->id;
        $p = new Process($path);

        $pid = $p->getPid();

        $this->DelayedJobs->setPid($job, $pid);

        $this->_runningJobs[$job->id] = [
            'pid' => $pid,
            'start_time' => time(),
            'max_execution_time' => $options['max_execution_time'],
            'job' => $job
        ];
        $this->out(__(' - <info>Runner started ({0})</info>', $pid), 1, Shell::VERBOSE);

        return true;
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options
            ->addOption(
                'workers',
                [
                    'help' => 'Number of jobs to run concurrently'
                ]
            )
            ->addArgument('workerName', [
                'help' => 'Custom worker name to use',
            ]);

        return $options;
    }

}