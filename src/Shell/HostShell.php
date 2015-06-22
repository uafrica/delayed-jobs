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

        $this->out(sprintf('<info>Started up:</info> %s', $this->_workerId), 1, Shell::VERBOSE);
        while (true) {
            $number_jobs = count($this->_runningJobs);
            if ($number_jobs < $max_allowed_jobs) {
                $this->_executeJob();
            }

            //## Check Status of Fired Jobs
            foreach ($this->_runningJobs as $job_id => $running_job) {
                $job = $this->DelayedJobs->get($job_id);

                $status = new Process();
                $status->setPid($running_job['pid']);
                if (!$status->status()) {
                    //## Make sure that this job is not marked as running
                    if ($job->status === DelayedJobsTable::STATUS_BUSY) {
                        $this->DelayedJobs->failed(
                            $job,
                            'Job not running, but db said it is, could be a runtime error'
                        );
                    }
                    $time = time() - $running_job['start_time'];
                    unset($this->_runningJobs[$job_id]);
                    $this->out(sprintf('<success>Job\'s done:</success> %s, took %d seconds', $job_id, $time), 2, Shell::VERBOSE);
                } else {
                    //## Check if job has not reached it max exec time
                    $busy_time = time() - $running_job['start_time'];

                    if ($busy_time > $running_job['max_execution_time']) {
                        $this->out(sprintf('<error>Job timeout:</error> %s', $job_id), 2, Shell::VERBOSE);
                        $status->stop();

                        $this->DelayedJobs->failed($job, 'Job ran too long, killed');
                    } else {
                        $this->out(sprintf('<info>Still running:</info> %s, %d seconds', $job_id, $busy_time), 1, Shell::VERBOSE);
                    }
                }
            }

            //## Sleep so that the system can rest
            sleep(2);
        }
    }

    protected function _executeJob() {
        $job = $this->DelayedJobs->getOpenJob($this->_workerId);

        if ($job) {
            $this->out(sprintf('<success>Starting job:</success> %s', $job->id), 1, Shell::VERBOSE);
            if (!isset($this->_runningJobs[$job->id])) {
                $options = unserialize($job->options);

                if (!isset($options['max_execution_time'])) {
                    $options['max_execution_time'] = 25 * 60;
                }

                $path = ROOT . '/bin/cake DelayedJobs.worker ' . $job->id;
                $p = new Process($path);

        $options = (array)$job->options;

                $this->DelayedJobs->setPid($job, $pid);

                $this->_runningJobs[$job->id] = [
                    'pid' => $pid,
                    'start_time' => time(),
                    'max_execution_time' => $options['max_execution_time'],
                ];
                $this->out(' - <info>Runner started</info>', 1, Shell::VERBOSE);
            }
        }
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