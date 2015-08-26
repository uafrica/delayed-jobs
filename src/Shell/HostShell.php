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
    protected $_runningJobs = [];
    protected $_host;

    public function main()
    {
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

        /*
         * Get Next Job
         * Get Exclusive Lock on Job
         * Fire Worker
         * Worker fires job
         * Worker monitors the exection time
         */

        //## Need to make sure that any running jobs for this host is in the array job_pids
        $this->_updateRunning();
        $this->out(__('<info>Started up:</info> {0}', $this->_workerId), 1, Shell::VERBOSE);
        $counter = 0;
        while (true) {
            usleep(250000);

            $this->_startWorker();
            $this->_checkRunning();

            //Every 10 seconds we update our host entry to catch changes to worker count, or self shutdown
            $counter++;
            if ($counter === self::UPDATETIMER * 4) {
                $this->_host = $this->Hosts->find()
                    ->where([
                        'host_name' => $host_name,
                        'pid' => getmypid()
                    ])
                    ->first();
                $counter = 0;
            }

            if ($this->_host && $this->_host->status === HostsTable::STATUS_SHUTDOWN && empty($this->_runningJobs)) {
                break;
            }
        }

        if ($this->_host) {
            $this->Hosts->delete($this->_host);
        }
    }

    protected function _checkRunning()
    {
        $this->_updateRunning();
        foreach ($this->_runningJobs as $job_id => &$running_job) {
            $job = $this->DelayedJobs->get($job_id);
            $running_job['job'] = $job;

            if ($job->locked_by !== $this->_workerId) {
                //Not our job, why are we looking at it?
                unset($this->_runningJobs[$job_id]);
                continue;
            }

            $this->out(__('Job status: {0}', $job_id), 1, Shell::VERBOSE);

            $status = new Process();
            $status->setPid($running_job['pid']);
            if (!$status->status()) {
                //## Make sure that this job is not marked as running
                if ($job->status === DelayedJobsTable::STATUS_BUSY) {
                    $this->DelayedJobs->failed(
                        $job,
                        'Job not running, but db said it is, could be a runtime error'
                    );
                    unset($this->_runningJobs[$job_id]);
                    $this->out(__(' - <error>Job not running, but should be</error>'), 1, Shell::VERBOSE);
                } else {
                    $time = time() - (isset($running_job['start_time']) ? $running_job['start_time'] : time());
                    unset($this->_runningJobs[$job_id]);
                    $this->out(__(' - <success>Job\'s done:</success> {0}, took {1} seconds', $job_id, $time), 1, Shell::VERBOSE);
                }
            } else {
                //## Check if job has not reached it max exec time
                $busy_time = time() - $running_job['start_time'];

                if ($busy_time > $running_job['max_execution_time']) {
                    $this->out(__(' - <error>Job timeout:</error> {0}', $job_id), 1, Shell::VERBOSE);
                    $status->stop();

                    $this->DelayedJobs->failed($job, 'Job ran too long, killed');
                    unset($this->_runningJobs[$job_id]);
                } else {
                    $this->out(__(' - <comment>Still running:</comment> {0}, {1} seconds', $job_id, $busy_time), 1, Shell::VERBOSE);
                }
            }
        }
    }

    protected function _updateRunning()
    {
        $db_jobs = $this->DelayedJobs->getRunningByHost($this->_workerId);
        foreach ($db_jobs as $running_job) {
            if (empty($this->_runningJobs[$running_job->id])) {
                $this->_runningJobs[$running_job->id] = [
                    'pid' => $running_job->pid,
                    'job' => $running_job
                ];
            }
        }
    }

    protected function _startWorker() {
        if ($this->_host && ($this->_host->status === HostsTable::STATUS_SHUTDOWN ||
            $this->_host->status === HostsTable::STATUS_TO_KILL)) {
            return;
        }


        $worker_count = $this->param('workers') ?: ($this->_host ? $this->_host->worker_count : 1);
        if (count($this->_runningJobs) >= $worker_count) {
            return;
        }

        $job = $this->DelayedJobs->getOpenJob($this->_workerId);

        if (!$job) {
            $this->out(__('<comment>No open job</comment>'), 1, Shell::VERBOSE);
            return;
        }

        $this->out(__('<success>Starting job:</success> {0}', $job->id), 1, Shell::VERBOSE);
        if (isset($this->_runningJobs[$job->id])) {
            $this->out(__(' - <error>Already have this job</error>'), 1, Shell::VERBOSE);
            return;
        }

        $options = (array)$job->options;

        $this->out(__(' - <info>Runner: </info> {0}::{1}', $job->class, $job->method), 1, Shell::VERBOSE);

        if (!isset($options['max_execution_time'])) {
            $options['max_execution_time'] = 25 * 60;
        }

        $path = ROOT . '/bin/cake DelayedJobs.worker ' . $job->id;
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