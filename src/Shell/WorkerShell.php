<?php
namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use Cake\Core\Exception\Exception;
use DelayedJobs\Model\Table\DelayedJobsTable;

class WorkerShell extends Shell
{
    public $Lock;
    public $modelClass = 'DelayedJobs.DelayedJobs';

    public function main()
    {
        $job_id = null;

        if (isset($this->args[0])) {
            $job_id = $this->args[0];
        }

        if ($job_id === null) {
            throw new Exception("No Job ID received");
        }

//        $this->Lock = new LockComponent();
//        $this->Lock->lock('DelayedJobs.WorkerShell.main.' . $job_id);

        $this->out('<info>Starting Job: ' . $job_id . '</info>');

        try {
            $job = $this->DelayedJobs->get($job_id);
        } catch (RecordNotFoundException $e) {
            $this->out('<fail>Job ' . $job_id . ' not found (' . $e->getMessage() . ')</fail>');
            return;
        }

        try {
            if ($job) {
                //## First check if job is not locked
                if ($job->status == DelayedJobsTable::STATUS_SUCCESS) {
                    throw new Exception("Job previously completed, Why is is being called");
                }

                if ($job->status == DelayedJobsTable::STATUS_BURRIED) {
                    throw new Exception("Job Failed too many times, but why was it called again");
                }

                $response = $job->execute();

                if ($response) {
                    $this->DelayedJobs->completed($job);
                    $this->out('<success>Job ' . $job->id . ' Completed</success>');
                } else {
                    throw new Exception("Invalid response received");
                }
            }
        } catch (\Exception $exc) {
            //## Job Failed
            $this->DelayedJobs->failed($job, $exc->getMessage());
            $this->out('<fail>Job ' . $job_id . ' Failed (' . $exc->getMessage() . ')</fail>');
            // $this->Lock->lock('DelayedJobs.WorkerShell.main.' . $job_id);
        }

        //$this->Lock->unlock('DelayedJobs.WorkerShell.main.' . $job_id);
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options
            ->addArgument(
                'jobId',
                [
                    'help' => 'Job ID to run',
                    'required' => true
                ]
            );

        return $options;
    }
}
