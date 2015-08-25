<?php
namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use DelayedJobs\Lock;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Process;

class HostShell extends Shell
{
    public $Lock;
    public $modelClass = 'DelayedJobs.DelayedJobs';
    protected $_workerId;
    protected $_workerName;
    protected $_runningJobs = [];

    public function main()
    {
        $this->_workerName = 'worker1';

        if (isset($this->args[0])) {
            $this->_workerName = $this->args[0];
        }

        $this->Lock = new Lock();
        if (!$this->Lock->lock('DelayedJobs.HostShell.main.' . $this->_workerName)) {
            $this->_stop(1);
        }

        $this->_workerId = $this->_workerName . ' - ' . php_uname('n');

        /*
         * Get Next Job
         * Get Exclusive Lock on Job
         * Fire Worker
         * Worker fires job
         * Worker monitors the exection time
         */

        $max_allowed_jobs = $this->param('maxJobs');

        //## Need to make sure that any running jobs for this host is in the array job_pids

        $db_jobs = $this->DelayedJobs
            ->getRunningByHost($this->_workerId)
            ->toArray();
        foreach ($db_jobs as $running_job) {
            $this->_runningJobs[$running_job->id] = [
                'pid' => $running_job->pid
            ];
        }

        $this->out(__('<info>Started up:</info> {0}', $this->_workerId), 1, Shell::VERBOSE);
        $this->hr();
        while (true) {
            $number_jobs = count($this->_runningJobs);
            if ($number_jobs < $max_allowed_jobs) {
                $this->_executeJob();
            }

            //## Check Status of Fired Jobs
            foreach ($this->_runningJobs as $job_id => $running_job) {
                $this->out(__('Job status: {0}', $job_id), 1, Shell::VERBOSE);
                $job = $this->DelayedJobs->get($job_id);

                if ($job->locked_by !== $this->_workerId) {
                    //Not our job, why are we looking at it?
                    continue;
                }

                $status = new Process();
                $status->setPid($running_job['pid']);
                if (!$status->status()) {
                    //## Make sure that this job is not marked as running
                    if ($job->status === DelayedJobsTable::STATUS_BUSY) {
                        $this->DelayedJobs->failed(
                            $job,
                            'Job not running, but db said it is, could be a runtime error'
                        );
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
                    } else {
                        $this->out(__(' - <comment>Still running:</comment> {0}, {1} seconds', $job_id, $busy_time), 1, Shell::VERBOSE);
                    }
                }
                $this->hr(1);
            }

            //## Sleep so that the system can rest
            usleep(500000);
        }
    }

    protected function _executeJob() {
        $job = $this->DelayedJobs->getOpenJob($this->_workerId);

        if (!$job) {
            $this->out(__('<comment>No open job</comment>'), 1, Shell::VERBOSE);
            return;
        }

        $this->out(__('<success>Starting job:</success> {0}', $job->id), 1, Shell::VERBOSE);
        if (isset($this->_runningJobs[$job->id])) {
            $this->out(__(' - <error>Job already running</error>'), 1, Shell::VERBOSE);
            return;
        }

        $options = (array)$job->options;

        $this->out(__('  <comment>Job details</comment>'), 1, Shell::VERBOSE);
        $this->out(__('    <info>Runner: </info> {0}::{1}', $job->class, $job->method), 1, Shell::VERBOSE);
        $this->out(json_encode($options, JSON_PRETTY_PRINT), 1, Shell::VERBOSE);

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
        ];
        $this->out(' - <info>Runner started</info>', 1, Shell::VERBOSE);
        $this->hr(1);
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options
            ->addOption(
                'maxJobs',
                [
                    'help' => 'Number of jobs to run concurrently',
                    'short' => 'n',
                    'default' => 1
                ]
            )
            ->addArgument('workerName', [
                'help' => 'Custom worker name to use',
            ]);

        return $options;
    }

}