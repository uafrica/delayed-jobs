<?php
namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use Cake\Core\Exception\Exception;
use Cake\Datasource\Exception\RecordNotFoundException;
use DelayedJobs\Lock;
use DelayedJobs\Model\Table\DelayedJobsTable;

class WorkerShell extends Shell
{
    /**
     * @var \DelayedJobs\Lock
     */
    public $Lock;
    /**
     * @var string
     */
    public $modelClass = 'DelayedJobs.DelayedJobs';

    /**
     * @param \DelayedJobs\Lock $lock Inject lock object
     * @return void
     * @codeCoverageIgnore
     */
    public function startup(Lock $lock = null)
    {
        if (!$lock) {
            $lock = new Lock();
        }
        $this->Lock = $lock;
    }

    public function main()
    {
        if (isset($this->args[0])) {
            $job_id = $this->args[0];
        }

        if (empty($job_id)) {
            throw new Exception("No Job ID received");
        }

        if (!$this->Lock->lock('DelayedJobs.WorkerShell.main.' . $job_id)) {
            $this->_stop(1);
            return;
        }

        $this->out('<info>Starting Job: ' . $job_id . '</info>');

        try {
            $job = $this->DelayedJobs->get($job_id);
        } catch (RecordNotFoundException $e) {
            $this->out('<fail>Job ' . $job_id . ' not found (' . $e->getMessage() . ')</fail>');
            $this->_stop(1);
            return;
        }

        try {
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
        } catch (\Exception $exc) {
            //## Job Failed
            $this->DelayedJobs->failed($job, $exc->getMessage());
            $this->out('<fail>Job ' . $job_id . ' Failed (' . $exc->getMessage() . ')</fail>');
        }

        $this->Lock->unlock('DelayedJobs.WorkerShell.main.' . $job_id);
    }

    /**
     * @return \Cake\Console\ConsoleOptionParser
     * @codeCoverageIgnore
     */
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
