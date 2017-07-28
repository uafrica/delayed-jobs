<?php
namespace DelayedJobs\Shell;

use App\Shell\AppShell;
use Cake\Console\Shell;
use DelayedJobs\DelayedJob\EnqueueTrait;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\JobManager;

/**
 * Class TestShell
 * @package DelayedJobs\Shell
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class TestShell extends AppShell
{
    use EnqueueTrait;
    
    public $modelClass = 'DelayedJobs.DelayedJobs';

    /**
     * @return bool
     * @throws \Exception Exception.
     */
    public function main()
    {
        $this->out('<info>Started up</info>', 1, Shell::VERBOSE);

        $job = $this->enqueue('DelayedJobs.Test', ['type' => 'success']);

        if (!$job) {
            throw new \Exception("Success Job could not be loaded");
        }

        $this->out(
            '<success>Loaded Successful Job: Waiting 15 seconds to check if it was successful</success>',
            1,
            Shell::VERBOSE
        );

        sleep(10);

        $job = JobManager::instance()->fetchJob($job->getId());

        if ($job->getStatus() !== Job::STATUS_SUCCESS) {
            throw new \Exception("Successful job was not successful");
        }

        $this->out('<success>Test Success</success>', 1, Shell::VERBOSE);

        //** Loading Job that will fail */
        $job = $this->enqueue('DelayedJobs.Test', ['type' => 'fail'], ['maxRetries' => 1]);

        if (!$job) {
            throw new \Exception("Failed Job could not be loaded");
        }

        $this->out('<success>Loaded Failed Job: Waiting 15 seconds to check if it failed</success>', 1, Shell::VERBOSE);

        sleep(10);

        $job = JobManager::instance()->fetchJob($job->getId());

        if ($job->getStatus() !== Job::STATUS_BURIED) {
            throw new \Exception("Failed Job did not fail");
        }

        $this->out('<success>Test Success</success>', 1, Shell::VERBOSE);

        $this->out('<info>All Done</info>', 1, Shell::VERBOSE);

        return true;
    }
}
