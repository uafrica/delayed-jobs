<?php
declare(strict_types=1);

namespace DelayedJobs\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\JobManager;
use DelayedJobs\Result\Failed;
use DelayedJobs\Result\Pause;
use DelayedJobs\Result\Success;
use DelayedJobs\Traits\DebugLoggerTrait;

/**
 * Class RunCommand
 */
class RunCommand extends Command
{
    use DebugLoggerTrait;

    /**
     * Arguments
     *
     * @var \Cake\Console\Arguments
     */
    protected $args;

    /**
     * Console IO
     *
     * @var \Cake\Console\ConsoleIo
     */
    protected $io;

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io)
    {
        $this->args = $args;
        $this->io = $io;

        $jobId = (int)$args->getArgument('jobId');

        $this->io->verbose('<info>Starting Job: ' . $jobId . '</info>');

        try {
            $job = JobManager::getInstance()->fetchJob($jobId);
            $this->io->verbose(' - Got job from DB');
        } catch (JobNotFoundException $e) {
            $this->io->verbose('<fail>Job ' . $jobId . ' not found (' . $e->getMessage() . ')</fail>');

            return self::CODE_ERROR;
        }

        $this->executeJob($job);

        return self::CODE_SUCCESS;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job
     * @return void
     */
    public function executeJob(Job $job): void
    {
        $startMem = (int)memory_get_usage();
        $this->io->verbose(sprintf(' - <info>%s</info>', $job->getWorker()));
        $this->io->verbose(' - Executing job');
        $this->djLog(__('Executing: {0}', $job->getId()));

        $job->setManualRun(true);

        $start = microtime(true);

        $response = JobManager::getInstance()
            ->execute($job, (bool)$this->args->getOption('force'));

        $this->djLog(__('Done with: {0}', $job->getId()));

        if ($response instanceof Failed) {
            $this->failedJob($job, $response);
        } elseif ($response instanceof Success) {
            $this->io->verbose(
                sprintf('<success> - Execution successful</success> :: <info>%s</info>', $response->getMessage())
            );
        } elseif ($response instanceof Pause) {
            $this->io->verbose(
                sprintf('<info> - Execution paused</info> :: <info>%s</info>', $response->getMessage())
            );
        }
        $end = microtime(true);
        $endMem = (int)memory_get_usage();
        $memUsage = ($endMem - $startMem) / 1000;
        $this->io->verbose(sprintf(' - Took: %.2f seconds', $end - $start));
        $this->io->verbose(sprintf(' - Memory usage: %u KB', $memUsage));
        $this->io->verbose(sprintf(' - Peak memory usage: %u KB', (int)memory_get_peak_usage() / 1000));
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job
     * @param \DelayedJobs\Result\Failed $response Failed response
     * @return void
     * @throws \Throwable
     */
    protected function failedJob(Job $job, Failed $response): void
    {
        if ($this->args->getOption('debug') && $response->getException() !== null) {
            throw $response->getException();
        }

        $this->io->verbose(
            sprintf(
                '<error> - Execution failed</error> :: <info>%s</info>',
                $response->getMessage()
            )
        );
        if ($response->getException() !== null) {
            $this->io->verbose(
                $response->getException()
                    ->getTraceAsString()
            );
        }

        $this->djLog(__('Failed {0} because {1}', $job->getId(), $response->getMessage()));
    }

    /**
     * @return \Cake\Console\ConsoleOptionParser
     * @codeCoverageIgnore
     */
    public function getOptionParser(): ConsoleOptionParser
    {
        $options = parent::getOptionParser();

        $options->addOption(
            'force',
            [
                    'short' => 'f',
                    'help' => 'Force the job to run, even if failed, or successful',
                    'boolean' => true,
                ]
        )
            ->addOption(
                'debug',
                [
                    'short' => 'd',
                    'help' => 'Re-throw the exception that caused the job to fail',
                    'boolean' => true,
                ]
            )
            ->addArgument(
                'jobId',
                [
                    'help' => 'Job ID to run',
                    'required' => true,
                ]
            );

        return $options;
    }
}
