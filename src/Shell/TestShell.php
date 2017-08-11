<?php
namespace DelayedJobs\Shell;

use App\Shell\AppShell;
use Cake\Console\ConsoleOptionParser;
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

    public function sequencing()
    {
        $this->out('<info>Creating 10 jobs with sequencing, 3 will fail</info>');

        $rand = random_int(0, time());
        $failures = [
            1, 5, 6
        ];
        for ($i = 0; $i < 10; $i++) {
            $this->enqueue(
                'DelayedJobs.Test',
                ['type' => in_array($i, $failures) ? 'fail' : 'success'],
                [
                    'maxRetries' => 2,
                    'sequence' => 'TestJob::' . $rand
                ]
            );
            sleep(1);
            if (in_array($i, $failures)) {
                $this->out('<error>' . $i . '</error>');
            } else {
                $this->out('<success>' . $i . '</success>');
            }
        }

        $this->out('<success>All queued. Check the table</success>');
    }

    /**
     * @return bool
     * @throws \Exception Exception.
     */
    public function main(): bool
    {
        $this->out('<info>Started up</info>', 1, Shell::VERBOSE);

        $job = $this->enqueue('DelayedJobs.Test', ['type' => 'success']);

        if (!$job) {
            throw new \Exception('Success Job could not be loaded');
        }

        $this->out(
            '<success>Loaded Successful Job: Waiting 15 seconds to check if it was successful</success>',
            1,
            Shell::VERBOSE
        );

        sleep(10);

        $job = JobManager::instance()->fetchJob($job->getId());

        if ($job->getStatus() !== Job::STATUS_SUCCESS) {
            throw new \Exception('Successful job was not successful');
        }

        $this->out('<success>Test Success</success>', 1, Shell::VERBOSE);

        //** Loading Job that will fail */
        $job = $this->enqueue('DelayedJobs.Test', ['type' => 'fail'], ['maxRetries' => 1]);

        if (!$job) {
            throw new \Exception('Failed Job could not be loaded');
        }

        $this->out('<success>Loaded Failed Job: Waiting 15 seconds to check if it failed</success>', 1, Shell::VERBOSE);

        sleep(10);

        $job = JobManager::instance()->fetchJob($job->getId());

        if ($job->getStatus() !== Job::STATUS_BURIED) {
            throw new \Exception('Failed Job did not fail');
        }

        $this->out('<success>Test Success</success>', 1, Shell::VERBOSE);

        $this->out('<info>All Done</info>', 1, Shell::VERBOSE);

        return true;
    }

    /**
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser(): ConsoleOptionParser
    {
        $options = parent::getOptionParser();

        $options->addSubcommand('sequencing', [
                'help' => 'Sequencing'
            ]);

        return $options;
    }
}
