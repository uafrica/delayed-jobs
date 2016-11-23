<?php
namespace DelayedJobs\Shell;

use App\Shell\AppShell;
use Cake\Cache\Cache;
use Cake\Console\Exception\StopException;
use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\Event;
use Cake\I18n\FrozenTime;
use Cake\I18n\Time;
use Cake\Log\Log;
use DelayedJobs\Broker\PhpAmqpLibBroker;
use DelayedJobs\DelayedJob\JobManager;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\Model\Table\WorkersTable;
use DelayedJobs\Traits\DebugLoggerTrait;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class WorkerShell
 *
 * @property \DelayedJobs\Model\Table\WorkersTable $Workers
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 * @property \DelayedJobs\Shell\Task\WorkerTask $Worker
 * @property \DelayedJobs\Shell\Task\ProcessManagerTask $ProcessManager
 */
class WorkerShell extends AppShell
{
    use DebugLoggerTrait;

    const TIMEOUT = 10; //In seconds
    const MAXFAIL = 5;
    const SUICIDE_EXIT_CODE=100;
    public $modelClass = 'DelayedJobs.DelayedJobs';
    public $tasks = ['DelayedJobs.Worker', 'DelayedJobs.ProcessManager'];
    protected $_workerId;
    protected $_workerName;
    protected $_hostName;
    protected $_runningJobs = [];
    /**
     * @var \DelayedJobs\Model\Entity\Worker
     */
    protected $_worker;
    /**
     * @var \DelayedJobs\Broker\BrokerInterface
     */
    protected $_broker;
    /**
     * @var \DelayedJobs\DelayedJob\JobManager
     */
    protected $_manager;
    protected $_tag;
    protected $_startTime;
    protected $_jobCount = 0;
    protected $_lastJob;
    protected $_myPID;
    private $_pulse = false;
    /**
     * Should this worker be suicidal
     *
     * @var array {
     * @var bool $enabled Should this worker be suicidal
     * @var int $jobCount After how many jobs should this worker kill itself
     * @var int $idleTimeout After how many idle seconds should this worker kill itself
     * }
     */
    protected $_suicideMode = [
        'enabled' => false,
        'jobCount' => 100,
        'idleTimeout' => 120
    ];
    /**
     * Time that the last job was executed
     * @var float
     */
    protected $_timeOfLastJob;

    /**
     * @inheritDoc
     */
    public function startup()
    {
        if ($this->command !== 'main') {
            parent::startup();
            return;
        }
        $this->loadModel('DelayedJobs.Workers');
        $this->_myPID = getmypid();
        $this->_hostName = php_uname('n');
        $this->_startTime = time();

        $worker_count = $this->Workers->find()
            ->where([
                'host_name' => $this->_hostName,
                'status in' => [
                    WorkersTable::STATUS_RUNNING,
                    WorkersTable::STATUS_SHUTDOWN,
                    WorkersTable::STATUS_TO_KILL,
                ]
            ])
            ->count();
        $this->_workerName = $this->_hostName . '-' . $worker_count;

        $this->_worker = $this->Workers->started($this->_hostName, $this->_workerName, $this->_myPID);

        $this->_workerId = $this->_workerName . '.' . $this->_workerName;

        $this->_suicideMode = Configure::read('DelayedJobs.workers.suicideMode') + $this->_suicideMode;
        $this->_timeOfLastJob = microtime(true);

        cli_set_process_title(sprintf('DJ Worker :: %s :: Booting', $this->_workerId));

        $this->_enableListeners();

        parent::startup();
    }

    protected function _enableListeners()
    {
        $this->ProcessManager->eventManager()
            ->on('CLI.signal', [$this, 'stopHammerTime']);
        $this->ProcessManager->handleKillSignals();
    }

    protected function _welcome()
    {
        $this->clear();
        $this->out(sprintf('Started at: <info>%s</info>', new Time($this->_startTime)));
        $this->out(sprintf('WorkerID: <info>%s</info>', $this->_workerId));
        $this->out(sprintf('PID: <info>%s</info>', $this->_myPID));

        if ($this->_io->level() == Shell::NORMAL && $this->_jobCount > 0) {
            $this->out(sprintf('Last job: <info>%d</info>', $this->_lastJob));
            $this->out(sprintf('Jobs completed: <info>%d</info>', $this->_jobCount));
            $this->out(sprintf('Jobs completed/s: <info>%.2f</info>', $this->_jobCount / (time() - $this->_startTime)));
        }

        $this->hr();
        $this->nl();
    }

    public function stopHammerTime($exitCode = 0)
    {
        $this->out('Shutting down...');

        $this->_manager->stopConsuming();

        if ($this->_worker) {
            $this->Workers->delete($this->_worker);
        }

        $this->_stop($exitCode);
    }

    public function main()
    {
        $this->heartbeat();

        $this->_manager = JobManager::instance();
        $this->_manager->eventManager()->on('DelayedJob.beforeJobExecute', [$this, 'beforeExecute']);
        $this->_manager->eventManager()->on('DelayedJob.afterJobExecute', [$this, 'afterExecute']);
        $this->_manager->eventManager()->on('DelayedJob.heartbeat', [$this, 'heartbeat']);

        $this->_manager->startConsuming();

        $this->stopHammerTime();
    }

    public function heartbeat()
    {
        cli_set_process_title(sprintf('DJ Worker :: %s :: %s', $this->_workerId, $this->_pulse ? 'O' : '-'));
        $this->_pulse = !$this->_pulse;

        if ($this->_worker === null) {
            $this->stopHammerTime();

            return;
        }

        try {
            $this->_worker = $this->Workers->get($this->_worker->id);
        } catch (RecordNotFoundException $e) {
            $this->stopHammerTime();
            return;
        }

        //This isn't us, we aren't supposed to be alive!
        if ($this->_worker->pid !== $this->_myPID) {
            $this->stopHammerTime();

            return;
        }

        if ($this->_worker && $this->_worker->status === WorkersTable::STATUS_SHUTDOWN) {
            $this->stopHammerTime();

            return;
        }

        $this->_worker->pulse = new Time();
        $this->_worker->job_count = $this->_jobCount;

        $this->Workers->save($this->_worker);
        pcntl_signal_dispatch();

        $this->_checkSuicideStatus();
    }

    protected function _checkSuicideStatus()
    {
        if ($this->_suicideMode['enabled'] !== true) {
            return;
        }

        if ($this->_jobCount >= $this->_suicideMode['jobCount'] ||
            microtime(true) - $this->_timeOfLastJob >= $this->_suicideMode['idleTimeout']
        ) {
            $this->stopHammerTime(static::SUICIDE_EXIT_CODE);
        }
    }

    public function beforeExecute(Event $event, Job $job)
    {
        if ($this->_worker && ($this->_worker->status === WorkersTable::STATUS_SHUTDOWN || $this->_worker->status === WorkersTable::STATUS_TO_KILL)) {
            $event->stopPropagation();

            return false;
        }

        cli_set_process_title(sprintf('DJ Worker :: %s :: Working %s', $this->_workerId, $job->getId()));

        $this->out(__('<success>Starting job:</success> {0} :: ', $job->getId()), 1, Shell::VERBOSE);

        $this->out(sprintf(' - <info>%s</info>', $job->getWorker()), 1, Shell::VERBOSE);
        $this->out(' - Executing job', 1, Shell::VERBOSE);

        $job->setHostName($this->_hostName);

        pcntl_signal_dispatch();
        $this->_timeLastJob = microtime(true);

        return true;
    }

    public function afterExecute(Event $event, Job $job, $result, $duration)
    {
        $this->_lastJob = $job->getId();
        $this->_jobCount++;
        $this->out('');

        if ($result instanceof \Throwable) {
            $this->out(sprintf('<error> - Execution failed</error> :: <info>%s</info>', $result->getMessage()), 1, Shell::VERBOSE);
            $this->out($result->getTraceAsString(), 1, Shell::VERBOSE);
        } else {
            $this->out(sprintf('<success> - Execution successful</success> :: <info>%s</info>', $result), 1, Shell::VERBOSE);
        }

        $this->out(sprintf(' - Took: %.2f seconds', $duration / 1000), 1, Shell::VERBOSE);
        pcntl_signal_dispatch();

        $this->_timeLastJob = microtime(true);
        $this->_checkSuicideStatus();
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();
        $options->addSubcommand('worker', [
            'help' => 'Executes a job',
            'parser' => $this->Worker->getOptionParser(),
        ])
        ->addOption('debug', [
            'help' => 'Debug mode, will quit on error',
            'boolean' => true,
            'default' => false
        ]);
        return $options;
    }

}
