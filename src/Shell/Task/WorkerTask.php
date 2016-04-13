<?php
namespace DelayedJobs\Shell\Task;

use Cake\Console\Shell;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\Time;
use Cake\Log\Log;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\Manager;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\Exception\NonRetryableException;
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
            $job = Manager::instance()->fetchJob($job_id);
            $this->out(' - Got job from DB', 1, Shell::VERBOSE);
        } catch (JobNotFoundException $e) {
            $this->out('<fail>Job ' . $job_id . ' not found (' . $e->getMessage() . ')</fail>', 1, Shell::VERBOSE);
            $this->_stop(1);
            return;
        }
        //## First check if job is not locked
        if (!$this->param('force') && $job->getStatus() == Job::STATUS_SUCCESS) {
            $this->out("<error>Job previously completed, Why is is being called again</error>");
            $this->_stop(2);
        }

        if (!$this->param('force') && $job->getStatus() == Job::STATUS_BURRIED) {
            $this->out("<error>Job Failed too many times, but why was it called again</error>");
            $this->_stop(3);
        }

        Manager::instance()->lock($job);

        $this->executeJob($job);
    }

    public function executeJob(Job $job)
    {
        $this->out(sprintf(' - <info>%s</info>', $job->getWorker()), 1, Shell::VERBOSE);
        $this->out(' - Executing job', 1, Shell::VERBOSE);
        $this->dj_log(__('Executing: {0}', $job->getId()));

        $start = microtime(true);
        try {
            $response = Manager::instance()
                ->execute($job, $this);

            $this->dj_log(__('Done with: {0}', $job->getId()));

            $duration = round((microtime(true) - $start) * 1000);
            if (Manager::instance()
                ->completed($job, is_string($response) ? $response : null, $duration)
            ) {
                $this->dj_log(__('Marked as completed: {0}', $job->getId()));
            } else {
                $this->dj_log(__('Not marked as completed: {0}', $job->getId()));
            }

            $this->out(sprintf('<success> - Execution complete</success> :: <info>%s</info>', $response), 1,
                Shell::VERBOSE);

            //Recuring job
            if ($response instanceof \DateTime && !Manager::instance()->isSimilarJob($job)) {
                $recuringJob = clone $job;
                $recuringJob->setData([
                    'runAt' => $response,
                    'status' => Job::STATUS_NEW,
                    'retries' => 0,
                    'lastMessage' => null,
                    'failedAt' => null,
                    'lockedBy' => null,
                    'startTime' => null,
                    'endTime' => null,
                    'duration' => null,
                    'id' => null
                ]);
                Manager::instance()->enqueue($recuringJob);
            }
        } catch (\Error $error) {
            //## Job Failed badly
            $this->_failJob($job, $error, true);

            Log::emergency(sprintf("Delayed job %d failed due to a fatal PHP error.\n%s\n%s", $job->getId(), $error->getMessage(), $error->getTraceAsString()));
        } catch (\Exception $exc) {
            //## Job Failed
            $this->_failJob($job, $exc, false);
        } finally {
            $end = microtime(true);
            $this->out(sprintf(' - Took: %.2f seconds', $end - $start), 1, Shell::VERBOSE);
        }
    }

    protected function _failJob(Job $job, $exc, $hardFail = false)
    {
        Manager::instance()->failed($job, $exc->getMessage(), $hardFail);
        $this->out(sprintf('<error> - Execution completed</error> :: <info>%s</info>', $exc->getMessage()), 1,
            Shell::VERBOSE);
        $this->out($exc->getTraceAsString(), 1, Shell::VERBOSE);

        $this->dj_log(__('Failed {0} because {1}', $job->getId(), $exc->getMessage()));
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
