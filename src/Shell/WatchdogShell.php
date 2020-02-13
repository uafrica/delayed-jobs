<?php
declare(strict_types=1);

namespace DelayedJobs\Shell;

use App\Shell\AppShell;
use Cake\Console\ConsoleOptionParser;
use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\Time;
use Cake\ORM\TableRegistry;
use DelayedJobs\DelayedJob\EnqueueTrait;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\JobManager;
use DelayedJobs\Model\Entity\Worker;
use DelayedJobs\Model\Table\WorkersTable;
use DelayedJobs\Process;
use DelayedJobs\RecurringJobBuilder;

/**
 * Class WatchdogShell
 *
 * @property \DelayedJobs\Model\Table\WorkersTable $Workers
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class WatchdogShell extends AppShell
{
    use EnqueueTrait;

    protected function _welcome()
    {
        if (!Configure::check('DelayedJobs')) {
            throw new Exception('Could not load config, check your load settings in bootstrap.php');
        }
        $hostname = php_uname('n');

        $this->clear();
        $this->out('Hostname: <info>' . $hostname . '</info>');
        $this->hr();
    }

    public function recurring()
    {
        $this->out('Firing recurring event.');

        //Event is deprecated
        $event = new Event('DelayedJobs.recurring', $this);
        $event->result = RecurringJobBuilder::retrieve();
        EventManager::instance()->dispatch($event);

        $this->out(__('{0} jobs to queue', count($event->result)), 1, Shell::VERBOSE);
        foreach ($event->result as $job) {
            if (!$job instanceof Job) {
                $job = new Job($job + [
                        'group' => 'Recurring',
                        'priority' => 100,
                        'maxRetries' => 5,
                        'runAt' => new Time('+30 seconds'),
                    ]);
            }

            if (JobManager::getInstance()->isSimilarJob($job)) {
                $this->out(__('  <error>Already queued:</error> {0}', $job->getWorker()), 1, Shell::VERBOSE);
                continue;
            }

            $this->enqueue($job);

            $this->out(__('  <success>Queued:</success> {0}', $job->getWorker()), 1, Shell::VERBOSE);
        }
    }

    public function requeue()
    {
        $job = TableRegistry::getTableLocator()
            ->get('DelayedJobs.DelayedJobs')
            ->get($this->args[0]);

        if (
            !in_array($job->status, [
            Job::STATUS_NEW,
            Job::STATUS_FAILED,
            Job::STATUS_PAUSED,
            ])
        ) {
            $this->out(__('<error>{0} could not be queued - status is {1}</error>', $job->id, $job->status));

            return false;
        }

        if (!JobManager::getInstance()->enqueuePersisted($job['id'], $job['priority'])) {
            $this->out(' :: <error>X</error>', 1, Shell::VERBOSE);

            return false;
        }

        $this->out(' :: <success>√</success>', 1, Shell::VERBOSE);
        $this->out(__('<success>{0} has been queued</success>', $job->id));

        return true;
    }

    public function revive()
    {
        $stats = JobManager::getInstance()->getMessageBroker()->queueStatus();
        if ($stats['messages'] > 0) {
            $this->out(__('<error>There are {0} messages currently queued</error>', $stats['messages']));
            $this->out('We cannot reliably determine which messages to requeue unless the RabbitMQ queue is empty.');
            $this->_stop(1);
        }

        $this->loadModel('DelayedJobs.DelayedJobs');
        $sequences = $this->DelayedJobs->find()
            ->select([
                'sequence',
            ])
            ->group('sequence')
            ->where([
                'status in' => [Job::STATUS_NEW, Job::STATUS_FAILED],
                'run_at <=' => Time::now(),
                'sequence is not' => null,
            ])
            ->enableHydration(false)
            ->map(function ($sequence) {
                return $this->DelayedJobs->find()
                    ->select(['id', 'priority'])
                    ->where([
                        'status in' => [Job::STATUS_NEW, Job::STATUS_FAILED],
                        'run_at <=' => Time::now(),
                        'sequence' => $sequence['sequence'],
                    ])
                    ->order([
                        'id' => 'ASC',
                    ])
                    ->enableHydration(false)
                    ->first();
            });

        $no_sequences = $this->DelayedJobs->find()
            ->select(['id', 'priority'])
            ->where([
                'status in' => [Job::STATUS_NEW, Job::STATUS_FAILED],
                'run_at <=' => Time::now(),
                'sequence is' => null,
            ])
            ->enableHydration(false)
            ->all();

        /** @var \DelayedJobs\Model\Entity\DelayedJob[] $allJobs */
        $allJobs = $sequences->append($no_sequences);
        $isVerbose = $this->_io->level() < Shell::VERBOSE;
        foreach ($allJobs as $job) {
            if ($isVerbose) {
                $this->out('.', 0, Shell::QUIET);
                continue;
            }

            $this->out(__(' - Queueing job <info>{0}</info>', $job['id']), 1, Shell::VERBOSE);
            JobManager::getInstance()->enqueuePersisted($job['id'], $job['priority']);
        }
    }

    /**
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser(): ConsoleOptionParser
    {
        $options = parent::getOptionParser();

        $options
            ->addSubcommand('recurring', [
                'help' => 'Fires the recurring event and creates the initial recurring job instance',
            ])
            ->addSubcommand('reload', [
                'help' => 'Restarts all running worker hosts',
            ])
            ->addSubcommand('revive', [
                'help' => 'Requeues all new or failed jobs that should be in RabbitMQ',
            ])
            ->addSubcommand('requeue', [
                'help ' => 'Requeues a job',
                'parser' => [
                    'arguments' => [
                        'id' => [
                            'help' => 'Job id',
                            'required' => true,
                        ],
                    ],
                ],
            ]);

        return $options;
    }
}
