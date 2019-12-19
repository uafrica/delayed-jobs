<?php

namespace DelayedJobs\Command;

use Cake\Console\Arguments;
use Cake\Console\Command;
use Cake\Console\ConsoleIo;
use Cake\Console\Shell;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\Time;
use DelayedJobs\DelayedJob\EnqueueTrait;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\JobManager;
use DelayedJobs\RecurringJobBuilder;

/**
 * Class RecurringCommand
 */
class RecurringCommand extends Command
{
    use EnqueueTrait;

    /**
     * {@inheritDoc}
     */
    public function execute(Arguments $args, ConsoleIo $io)
    {
        $io->out('Locating recurring jobs.');

        //Event is deprecated
        $event = new Event('DelayedJobs.recurring', $this);
        $event->result = RecurringJobBuilder::retrieve();
        EventManager::instance()
            ->dispatch($event);

        $io->verbose(__('{0} recurring jobs to queue', count($event->result)));
        $queueCount = 0;
        foreach ($event->result as $job) {
            if (!$job instanceof Job) {
                $job = new Job($job + [
                        'group' => 'Recurring',
                        'priority' => 100,
                        'maxRetries' => 5,
                        'runAt' => new Time('+30 seconds')
                    ]);
            }

            if (JobManager::getInstance()->isSimilarJob($job)) {
                $io->verbose(__('  <error>Already queued:</error> {0}', $job->getWorker()));
                continue;
            }

            $this->enqueue($job);

            $io->verbose(__('  <success>Queued:</success> {0}', $job->getWorker()));
            $queueCount++;
        }

        $io->success(__('{0} recurring jobs queued', $queueCount));
    }

}
