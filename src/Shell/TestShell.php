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
     * @throws \Exception
     * @return void
     */
    public function bulk()
    {
        $number = $this->param('number');
        $failureRate = $this->param('failure');
        $this->out(__('<info>Creating {number} batch jobs, {failureRate}% will fail.</info>', compact('number', 'failureRate')));

        $start = microtime(true);
        $jobs = [];
        $this->out('Generating jobs');
        for ($i = 0; $i < $number; $i++) {
            $isFailure = random_int(0, 100) < $failureRate;

            $jobs[] = [
                '_payload' => [
                    'count' => $i,
                    'type' => $isFailure ? 'fail' : 'success'
                ]
            ];
        }

        $this->out('Queueing jobs');
        $this->enqueueBatch('DelayedJobs.Test', $jobs);

        $time = round(microtime(true) - $start, 5);
        $avgTime = round($time / $number, 5);
        $this->out(__('<success>All queued. Check the table. Took {time} seconds at {avgTime} seconds per job</success>', compact('time', 'avgTime')));
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

        $job = JobManager::getInstance()->fetchJob($job->getId());

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

        $job = JobManager::getInstance()->fetchJob($job->getId());

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

        $options
            ->addSubcommand('sequencing', [
                'help' => 'Sequencing'
            ])
            ->addSubcommand('bulk', [
                'help' => 'Push bulk messages',
                'parser' => [
                    'options' => [
                        'number' => [
                            'short' => 'n',
                            'help' => 'The number to bulk publish',
                            'default' => 1000
                        ],
                        'failure' => [
                            'help' => 'Failure rate in percentage. Default 10%',
                            'default' => 10
                        ]
                    ]
                ]
            ]);

        return $options;
    }
}
