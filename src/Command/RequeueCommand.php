<?php
declare(strict_types=1);

namespace DelayedJobs\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\JobManager;

/**
 * Class RequeueCommand
 */
class RequeueCommand extends Command
{
    /**
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser(): ConsoleOptionParser
    {
        $options = parent::getOptionParser();

        $options->addArgument('jobId', [
            'help' => 'Job id',
            'required' => true,
        ]);

        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Arguments $args, ConsoleIo $io)
    {
        /** @var \DelayedJobs\Model\Entity\DelayedJob $job */
        $job = TableRegistry::getTableLocator()
            ->get('DelayedJobs.DelayedJobs')
            ->get($args->getArgument('jobId'));

        if (
            !in_array(
                $job->status,
                [
                Job::STATUS_NEW,
                Job::STATUS_FAILED,
                Job::STATUS_PAUSED,
                ],
                true
            )
        ) {
            $io->out(__('<error>{0} could not be queued - status is {1}</error>', $job->id, $job->status));

            return self::CODE_ERROR;
        }

        JobManager::getInstance()
            ->enqueuePersisted($job['id'], $job['priority']);

        $io->out(__('<success>{0} has been queued</success>', $job->id));

        return self::CODE_SUCCESS;
    }
}
