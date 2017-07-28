<?php
namespace DelayedJobs\Shell\Task;

use Cake\Console\Shell;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\Time;
use Cake\Log\Log;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\JobManager;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\Exception\NonRetryableException;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Result\Failed;
use DelayedJobs\Result\Pause;
use DelayedJobs\Result\Success;
use DelayedJobs\Traits\DebugLoggerTrait;

class WorkerTask extends Shell
{
    use DebugLoggerTrait;

    /**
     * @var string
     */
    public $modelClass = 'DelayedJobs.DelayedJobs';

    public function main()
    {
        if (isset($this->args[0])) {
            $jobId = $this->args[0];
        }

        if (empty($jobId)) {
            $this->out("<error>No Job ID received</error>");
            $this->_stop(1);
        }

        $this->out('<info>Starting Job: ' . $jobId . '</info>', 1, Shell::VERBOSE);

        try {
            $job = JobManager::instance()->fetchJob($jobId);
            $this->out(' - Got job from DB', 1, Shell::VERBOSE);
        } catch (JobNotFoundException $e) {
            $this->out('<fail>Job ' . $jobId . ' not found (' . $e->getMessage() . ')</fail>', 1, Shell::VERBOSE);
            $this->_stop(1);
            return;
        }

        $this->executeJob($job);
    }

    public function executeJob(Job $job)
    {
        $this->out(sprintf(' - <info>%s</info>', $job->getWorker()), 1, Shell::VERBOSE);
        $this->out(' - Executing job', 1, Shell::VERBOSE);
        $this->djLog(__('Executing: {0}', $job->getId()));

        $start = microtime(true);
        $response = JobManager::instance()->execute($job, $this->param('force'));
        $this->djLog(__('Done with: {0}', $job->getId()));

        if ($response instanceof Failed) {
            $this->_failedJob($job, $response);
        } elseif ($response instanceof Success) {
            $this->out(sprintf('<success> - Execution successful</success> :: <info>%s</info>', $response->getMessage()), 1,
                Shell::VERBOSE);
        } elseif ($response instanceof Pause) {
            $this->out(sprintf('<info> - Execution paused</info> :: <info>%s</info>', $response->getMessage()), 1,
                Shell::VERBOSE);
        } else {

        }
        $end = microtime(true);
        $this->out(sprintf(' - Took: %.2f seconds', $end - $start), 1, Shell::VERBOSE);
    }

    protected function _failedJob(Job $job, Failed $response)
    {
        if ($this->param('debug')) {
            throw $response->getException();
        }

        $this->out(sprintf('<error> - Execution failed</error> :: <info>%s</info>', $response->getMessage()), 1, Shell::VERBOSE);
        if ($response->getException()) {
            $this->out($response->getException()->getTraceAsString(), 1, Shell::VERBOSE);
        }

        $this->djLog(__('Failed {0} because {1}', $job->getId(), $response->getMessage()));
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
