<?php
declare(strict_types=1);

namespace DelayedJobs\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Console\Exception\StopException;
use Cake\Core\Configure;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\I18n\FrozenTime;
use Cake\I18n\Time;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\JobManager;
use DelayedJobs\Model\Entity\Worker;
use DelayedJobs\Model\Table\WorkersTable;
use DelayedJobs\ProcessManager;
use DelayedJobs\Result\Failed;
use DelayedJobs\Result\Pause;
use DelayedJobs\Result\ResultInterface;
use DelayedJobs\Traits\DebugLoggerTrait;

/**
 * Class WorkerCommand
 *
 * @property \DelayedJobs\Model\Table\WorkersTable $Workers
 */
class WorkerCommand extends Command implements EventListenerInterface
{
    use DebugLoggerTrait;

    public const SUICIDE_EXIT_CODE = 100;
    public const NO_WORKER_EXIT_CODE = 110;
    public const WORKER_ERROR_EXIT_CODE = 120;
    public const HEARTBEAT_TIME = 30;
    public const MANAGER_SHUTDOWN = 40;

    /**
     * @var string
     */
    public $modelClass = 'DelayedJobs.Workers';

    /**
     * @var int
     */
    protected $workerId;
    /**
     * @var string
     */
    protected $workerName;
    /**
     * @var string
     */
    protected $hostName;
    /**
     * @var \DelayedJobs\Model\Entity\Worker
     */
    protected $worker;
    /**
     * @var \DelayedJobs\DelayedJob\JobManager
     */
    protected $manager;
    /**
     * @var float
     */
    protected $startTime;
    /**
     * @var int
     */
    protected $jobCount = 0;
    /**
     * @var int
     */
    protected $lastJob;
    /**
     * @var int
     */
    protected $myPID;
    /**
     * @var int
     */
    protected $beforeMemory;
    /**
     * Should this worker be suicidal
     *
     * @var array {
     * @var bool $enabled Should this worker be suicidal
     * @var int $jobCount After how many jobs should this worker kill itself
     * @var int $idleTimeout After how many idle seconds should this worker kill itself
     * }
     */
    protected $suicideMode = [
        'enabled' => false,
        'jobCount' => 100,
        'idleTimeout' => 120,
        'memoryLimit' => false,
    ];
    /**
     * Time that the last job was executed
     *
     * @var float
     */
    protected $timeOfLastJob;
    /**
     * @var int
     */
    protected $signalReceived;
    /**
     * @var bool
     */
    protected $busy = false;
    /**
     * @var \DelayedJobs\ProcessManager
     */
    protected $processManager;
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
     * @var bool
     */
    private $pulse = false;

    /**
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser(): ConsoleOptionParser
    {
        $options = parent::getOptionParser();
        $options->addOption(
            'stop-on-failure',
            [
                'short' => 's',
                'help' => 'The worker will immediately stop on any failure',
                'boolean' => true,
            ]
        );

        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Arguments $args, ConsoleIo $io)
    {
        $this->args = $args;
        $this->io = $io;

        $this->myPID = getmypid();
        $this->hostName = gethostname();
        $this->startTime = time();

        $workerCount = $this->Workers->find()
            ->where(
                [
                    'host_name' => $this->hostName,
                    'status in' => [
                        WorkersTable::STATUS_RUNNING,
                        WorkersTable::STATUS_SHUTDOWN,
                        WorkersTable::STATUS_TO_KILL,
                    ],
                ]
            )
            ->count();
        $this->workerName = $this->hostName . '-' . $workerCount;

        $this->worker = $this->Workers->started($this->hostName, $this->workerName, $this->myPID);

        $this->workerId = $this->workerName . '.' . $this->workerName;

        $this->suicideMode = Configure::read('DelayedJobs.workers.suicideMode') + $this->suicideMode;
        $this->timeOfLastJob = microtime(true);

        cli_set_process_title(sprintf('DJ Worker :: %s :: Booting', $this->workerId));

        $this->_enableListeners();

        $this->heartbeat();

        $this->manager = JobManager::getInstance();
        $this->manager->getEventManager()
            ->on($this);

        $this->manager->startConsuming();

        $this->stopHammerTime(Worker::SHUTDOWN_LOOP_EXIT);
    }

    /**
     * @return void
     */
    protected function _enableListeners(): void
    {
        $this->processManager = new ProcessManager();
        $this->processManager->getEventManager()
            ->on(
                'CLI.signal',
                function (EventInterface $event) {
                    $this->_processKillSignal(ProcessManager::$signals[$event->getData('signo')] ?? 'Unknown');
                }
            );
        $this->processManager->handleKillSignals();
    }

    /**
     * @return void
     */
    public function heartbeat(): void
    {
        $this->io->verbose('<success>Heartbeat</success>');

        cli_set_process_title(sprintf('DJ Worker :: %s :: %s', $this->workerId, $this->pulse ? 'O' : '-'));

        $this->pulse = !$this->pulse;
        $this->startTime = time();

        if ($this->worker === null) {
            $this->stopHammerTime(Worker::SHUTDOWN_NO_WORKER, static::NO_WORKER_EXIT_CODE);

            return;
        }

        try {
            $this->worker = $this->Workers->get($this->worker->id);
        } catch (RecordNotFoundException $e) {
            $this->stopHammerTime(Worker::SHUTDOWN_NO_WORKER, static::NO_WORKER_EXIT_CODE);

            return;
        }

        //This isn't us, we aren't supposed to be alive!
        if ($this->worker->pid !== $this->myPID) {
            $this->worker = null;
            $this->stopHammerTime(Worker::SHUTDOWN_WRONG_PID, static::NO_WORKER_EXIT_CODE);

            return;
        }

        if ($this->worker && $this->worker->status === WorkersTable::STATUS_SHUTDOWN) {
            $this->stopHammerTime(Worker::SHUTDOWN_STATUS);

            return;
        }

        $this->worker->pulse = new Time();
        $this->worker->job_count = $this->jobCount;
        $this->worker->memory_usage = memory_get_usage(true);
        $this->worker->idle_time = ceil(microtime(true) - $this->timeOfLastJob);
        $this->worker->last_job = $this->lastJob;

        $this->Workers->save($this->worker);
        pcntl_signal_dispatch();

        $this->_checkSuicideStatus();
    }

    /**
     * @param string $reason Reason for stop
     * @param int $exitCode Exit code
     * @return void
     */
    protected function stopHammerTime($reason, $exitCode = 0): void
    {
        $this->io->out('Shutting down...');

        if ($this->manager && $this->manager->isConsuming()) {
            $this->manager->stopConsuming();
        }

        if ($this->worker) {
            $this->worker->status = WorkersTable::STATUS_DEAD;
            $this->worker->shutdown_reason = $reason;
            $this->worker->shutdown_time = new FrozenTime();
            $this->Workers->save($this->worker);
        }

        throw new StopException($reason, $exitCode);
    }

    /**
     * @return void
     */
    protected function _checkSuicideStatus(): void
    {
        if ($this->signalReceived) {
            $this->stopHammerTime($this->signalReceived);
        }

        if ($this->suicideMode['enabled'] !== true) {
            return;
        }

        if (
            $this->jobCount >= $this->suicideMode['jobCount'] ||
            microtime(true) - $this->timeOfLastJob >= $this->suicideMode['idleTimeout'] ||
            ($this->suicideMode['memoryLimit'] !== false &&
                $this->suicideMode['memoryLimit'] * 1024 * 1024 <= memory_get_usage(true))
        ) {
            $this->stopHammerTime(Worker::SHUTDOWN_SUICIDE, static::SUICIDE_EXIT_CODE);
        }
    }

    /**
     * @return array
     */
    public function implementedEvents(): array
    {
        return [
            'DelayedJob.beforeJobExecute' => 'beforeExecute',
            'DelayedJob.afterJobExecute' => 'afterExecute',
            'DelayedJob.afterJobCompleted' => 'afterCompleted',
            'DelayedJob.heartbeat' => 'heartbeat',
            'DelayedJob.forceShutdown' => 'forceShutdown',
        ];
    }

    /**
     * @param \Cake\Event\EventInterface $event Event
     * @param \DelayedJobs\DelayedJob\Job $job Job
     * @return bool
     */
    public function beforeExecute(EventInterface $event, Job $job): bool
    {
        if (
            $this->worker &&
            ($this->worker->status === WorkersTable::STATUS_SHUTDOWN ||
                $this->worker->status === WorkersTable::STATUS_TO_KILL)
        ) {
            $event->stopPropagation();

            return false;
        }

        $this->busy = true;

        cli_set_process_title(sprintf('DJ Worker :: %s :: Working %s', $this->workerId, $job->getId()));

        $this->io->out(__('<info>Job: {0}</info>', $job->getId()));

        $this->beforeMemory = memory_get_usage(true);
        $this->io->verbose(sprintf(' - <info>%s</info>', $job->getWorker()));
        $this->io->verbose(
            sprintf(
                ' - Before job memory: <info>%s</info>',
                $this->_makeReadable($this->beforeMemory)
            )
        );
        $this->io->verbose(' - Executing job');

        pcntl_signal_dispatch();
        $this->timeOfLastJob = microtime(true);

        return true;
    }

    /**
     * @param float $size The size
     * @param int $precision How many
     * @return string
     */
    private function _makeReadable($size, $precision = 2): string
    {
        static $units = ['B', 'kiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'];
        $step = 1024;
        $i = 0;
        while ($size / $step > 0.9) {
            $size /= $step;
            $i++;
        }

        return round($size, $precision) . $units[$i];
    }

    /**
     * @param \Cake\Event\EventInterface $event The event
     * @param \DelayedJobs\DelayedJob\Job $job The Job
     * @param \DelayedJobs\Result\ResultInterface $result The result
     * @param int $duration The duration
     * @return void
     */
    public function afterExecute(EventInterface $event, Job $job, ResultInterface $result, $duration): void
    {
        $this->lastJob = $job->getId();
        $this->jobCount++;
        $this->io->nl();

        if ($result instanceof Failed) {
            $this->io->verbose(
                sprintf('<error> - Execution failed</error> :: <info>%s</info>', $result->getMessage())
            );
            if ($result->getException()) {
                $this->io->verbose(
                    $result->getException()
                        ->getTraceAsString()
                );
            }
        } elseif ($result instanceof Pause) {
            $this->io->verbose(
                sprintf(
                    '<info> - Execution paused</info> :: <info>%s</info>',
                    $result->getMessage()
                )
            );
        } else {
            $this->io->verbose(
                sprintf(
                    '<success> - Execution successful</success> :: <info>%s</info>',
                    $result->getMessage()
                )
            );
        }

        $nowMem = memory_get_usage(true);
        $this->io->verbose(
            sprintf(
                ' - After job memory: <info>%s</info> (Change %s)',
                $this->_makeReadable($nowMem),
                $this->_makeReadable($nowMem - $this->beforeMemory)
            )
        );
        $this->io->verbose(sprintf(' - Took: %.2f seconds', $duration / 1000));

        if ($this->io->level() === ConsoleIo::NORMAL) {
            $fin = '<success>✔</success>';
            if ($result instanceof Failed) {
                $fin = '<error>✘</error>';
            } elseif ($result instanceof Pause) {
                $fin = '<info>❙ ❙</info>';
            }
            $this->io->out(
                sprintf(
                    '%s %d %.2fs (%s)',
                    $fin,
                    $job->getId(),
                    $duration / 1000,
                    $this->_makeReadable($nowMem)
                )
            );
        }
    }

    /**
     * @param \Cake\Event\EventInterface $event The event
     * @param \DelayedJobs\DelayedJob\Job $job The Job
     * @param \DelayedJobs\Result\ResultInterface $result The result
     * @return void
     */
    public function afterCompleted(EventInterface $event, Job $job, ResultInterface $result): void
    {
        if ($result instanceof Failed && $this->args->getOption('stop-on-failure')) {
            $this->stopHammerTime(Worker::SHUTDOWN_ERROR, self::WORKER_ERROR_EXIT_CODE);
        }

        $this->busy = false;
        $this->timeOfLastJob = microtime(true);
        $this->_checkSuicideStatus();

        pcntl_signal_dispatch();

        if (time() - $this->startTime >= self::HEARTBEAT_TIME) {
            $this->heartbeat();
        }
    }

    /**
     * @return void
     */
    protected function forceShutdown(): void
    {
        $this->stopHammerTime(Worker::SHUTDOWN_MANAGER, static::MANAGER_SHUTDOWN);
    }
}
