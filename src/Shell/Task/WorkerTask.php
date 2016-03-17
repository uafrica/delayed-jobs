<?php
namespace DelayedJobs\Shell\Task;

use Cake\Console\Shell;
use Cake\Core\Exception\Exception;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\Time;
use Cake\Log\Log;
use DelayedJobs\Lock;
use DelayedJobs\Model\Entity\DelayedJob;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Traits\DebugTrait;

class WorkerTask extends Shell
{
    use DebugTrait;

    /**
     * @var string
     */
    public $modelClass = 'DelayedJobs.DelayedJobs';

    public function main()
    {
        if (isset($this->args[0])) {
            $job_id = $this->args[0];
        }

        if (empty($job_id)) {
            $this->out("<error>No Job ID received</error>");
            $this->_stop(1);
        }

        $this->out('<info>Starting Job: ' . $job_id . '</info>', 1, Shell::VERBOSE);

        try {
            $job = $this->DelayedJobs->getJob($job_id, true);
            $this->out(' - Got job from DB', 1, Shell::VERBOSE);
        } catch (RecordNotFoundException $e) {
            $this->out('<fail>Job ' . $job_id . ' not found (' . $e->getMessage() . ')</fail>', 1, Shell::VERBOSE);
            $this->_stop(1);
            return;
        }
        //## First check if job is not locked
        if (!$this->param('force') && $job->status == DelayedJobsTable::STATUS_SUCCESS) {
            $this->out("<error>Job previously completed, Why is is being called again</error>");
            $this->_stop(2);
        }

        if (!$this->param('force') && $job->status == DelayedJobsTable::STATUS_BURRIED) {
            $this->out("<error>Job Failed too many times, but why was it called again</error>");
            $this->_stop(3);
        }

        $job->status = DelayedJobsTable::STATUS_BUSY;
        $job->pid = getmypid();
        $job->start_time = new Time();
        $this->DelayedJobs->save($job);

        $this->executeJob($job);
    }

    public function executeJob(DelayedJob $job)
    {
        $this->out(sprintf(' - <info>%s::%s</info>', $job->class, $job->method), 1, Shell::VERBOSE);
        $this->out(' - Executing job', 1, Shell::VERBOSE);
        $this->dj_log(__('Executing: {0}', $job->id));

        $start = microtime(true);
        try {
            $response = $job->execute($this);

            $this->dj_log(__('Done with: {0}', $job->id));

            $duration = round((microtime(true) - $start) * 1000);
            if ($this->DelayedJobs->completed($job, is_string($response) ? $response : null, $duration)) {
                $this->dj_log(__('Marked as completed: {0}', $job->id));
            } else {
                $this->dj_log(__('Not marked as completed: {0}', $job->id));
            }

            $this->out(sprintf('<success> - Execution complete</success> :: <info>%s</info>', $response), 1,
                Shell::VERBOSE);

            //Recuring job
            if ($response instanceof \DateTime && !$this->DelayedJobs->jobExists($job)) {
                $recuring_job = clone $job;
                $recuring_job->set([
                    'run_at' => $response,
                    'status' => DelayedJobsTable::STATUS_NEW,
                    'retries' => 0,
                    'last_message' => null,
                    'failed_at' => null,
                    'locked_by' => null,
                    'start_time' => null,
                    'end_time' => null,
                    'pid' => null,
                    'duration' => null,
                ]);
                unset($recuring_job->id);
                unset($recuring_job->created);
                unset($recuring_job->modified);
                $recuring_job->isNew(true);
                $this->DelayedJobs->save($recuring_job);
            }
        } catch (\Error $error) {
            //## Job Failed badly
            $this->_failJob($job, $error, true);

            Log::emergency(sprintf("Delayed job %d failed due to a fatal PHP error.\n%s\n%s", $job->id, $error->getMessage(), $error->getTraceAsString()));
        } catch (\Exception $exc) {
            //## Job Failed
            $this->_failJob($job, $exc, false);
        } finally {
            $end = microtime(true);
            $this->out(sprintf(' - Took: %.2f seconds', $end - $start), 1, Shell::VERBOSE);
        }
    }

    protected function _failJob(DelayedJob $job, $exc, $hardFail = false)
    {
        $this->DelayedJobs->failed($job, $exc->getMessage(), $hardFail);
        $this->out(sprintf('<error> - Execution completed</error> :: <info>%s</info>', $exc->getMessage()), 1,
            Shell::VERBOSE);
        $this->out($exc->getTraceAsString(), 1, Shell::VERBOSE);

        $this->dj_log(__('Failed {0} because {1}', $job->id, $exc->getMessage()));
    }

    /**
     * @return \Cake\Console\ConsoleOptionParser
     * @codeCoverageIgnore
     */
    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options
            ->addOption('force', [
                'short' => 'f',
                'help' => 'Force the job to run, even if failed, or successful',
                'boolean' => true
            ])
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
