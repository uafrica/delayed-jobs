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
use Cake\I18n\Time;
use Cake\Log\Log;
use DelayedJobs\Broker\PhpAmqpLibBroker;
use DelayedJobs\DelayedJob\JobManager;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\Model\Table\WorkersTable;
use DelayedJobs\Traits\DebugTrait;
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
    use DebugTrait;

    const TIMEOUT = 10; //In seconds
    const MAXFAIL = 5;
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
    private $__pulse = false;

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
        $this->_workerName = Configure::read('dj.service.name');
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
        $this->_workerName .= '-' . $worker_count;

        $this->_worker = $this->Workers->started($this->_hostName, $this->_workerName, $this->_myPID);

        $this->_workerId = $this->_workerName . '.' . $this->_workerName;

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

    public function stopHammerTime()
    {
        $this->out('Shutting down...');

        $this->_manager->stopConsuming();

        if ($this->_worker) {
            $this->Workers->delete($this->_worker);
        }

        $this->_stop();
    }

    public function main()
    {
        $this->_heartbeat();

        $this->_manager = JobManager::instance();
        $this->_manager->eventManager()->on('DelayedJob.beforeJobExecute', [$this, 'beforeExecute']);
        $this->_manager->eventManager()->on('DelayedJob.afterJobExecute', [$this, 'afterExecute']);

        $this->_manager->startConsuming();

        while (true) {
            if ($this->_worker && $this->_worker->status === WorkersTable::STATUS_SHUTDOWN) {
                $this->stopHammerTime();

                return;
            }

            $ran_job = $this->_broker->wait(self::TIMEOUT);
            $this->_heartbeat($ran_job);
        }

        $this->stopHammerTime();
    }

    protected function _heartbeat($job_ran = false)
    {
        cli_set_process_title(sprintf('DJ Worker :: %s :: %s', $this->_workerId, $this->__pulse ? 'O' : '-'));

        if ($this->_worker === null) {
            $this->stopHammerTime();

            return;
        }

        $this->__pulse = !$this->__pulse;
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

        $this->_worker->pulse = new Time();
        if ($job_ran) {
            $this->_worker->job_count++;
        }

        $this->Workers->save($this->_worker);
    }

    public function runWorker(AMQPMessage $message)
    {
        //@TODO: Move code into job manager
        $this->out('');
        if ($this->_io->level() == Shell::NORMAL) {
            $this->out('Got work');
        }
        $body = json_decode($message->body, true);
        $jobId = $body['id'];
        try {
            $job = $this->_manager->fetchJob($jobId);
            $this->_executeJob($job, $message);
        } catch (JobNotFoundException $e) {
            if (!isset($body['is-requeue'])) {
                Log::debug(__('Job {0} does not exist in the datasource - could be a transaction delay - we try once more!', $jobId));

                $this->_broker->ack($message);
                $this->_broker->requeueMessage($message);

                return;
            }
            $this->out(__('<error>Job {0} does not exist in the DB!</error>', $jobId), 1, Shell::VERBOSE);

            $this->_broker->nack($message);
        } catch (InvalidPrimaryKeyException $e) {
            $this->dj_log(__('Invalid PK for {0}', $message->body));
            $this->_broker->nack($message);
        } catch (\Exception $e) {
            $this->dj_log(__('General exception {0}', $e->getMessage()));
            $this->_broker->nack($message, true);
            throw $e;
        }

        if ($this->_io->level() == Shell::NORMAL) {
            $this->_welcome();
        }

        unset($job);

        pcntl_signal_dispatch();
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

        //@TODO: Move to job manager
        if ($job->getStatus() === Job::STATUS_SUCCESS || $job->getStatus() === Job::STATUS_BURRIED) {
            $this->out(__('Already processed'), 1, Shell::VERBOSE);
            return true;
        }

        if ($job->getStatus() === Job::STATUS_BUSY) {
            $this->out(__('Already being processed'), 1, Shell::VERBOSE);

            return true;
        }

        //@TODO: Move locker into executor
        JobManager::instance()->lock($job, $this->_hostName);
        $this->_manager->execute($job);

        $this->_lastJob = $job->getId();
        $this->_jobCount++;
        $this->out('');
        unset($job);

        return true;
    }

    public function afterExecute(Event $event, Job $job, $result, $duration)
    {
        $this->_lastJob = $job->getId();
        $this->_jobCount++;
        $this->out('');

        if ($result instanceof \Throwable) {
            $this->out(sprintf('<error> - Execution failed</error> :: <info>%s</info>', $exc->getMessage()), 1, Shell::VERBOSE);
            $this->out($exc->getTraceAsString(), 1, Shell::VERBOSE);
        } else {
            $this->out(sprintf('<success> - Execution successful</success> :: <info>%s</info>', $result), 1, Shell::VERBOSE);
        }

        $this->out(sprintf(' - Took: %.2f seconds', $duration), 1, Shell::VERBOSE);
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();
        $options->addSubcommand('worker', [
            'help' => 'Executes a job',
            'parser' => $this->Worker->getOptionParser(),
        ])
        ->addOption('qos', [
            'help' => 'Sets the QOS value for AMQP',
            'default' => 1
        ])
        ->addOption('debug', [
            'help' => 'Debug mode, will quit on error',
            'boolean' => true,
            'default' => false
        ]);
        return $options;
    }

}
