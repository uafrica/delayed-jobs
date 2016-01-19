<?php
namespace DelayedJobs\Shell;

use Cake\Cache\Cache;
use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\Event;
use Cake\I18n\Number;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\Utility\Hash;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\Lock;
use DelayedJobs\Model\Entity\DelayedJob;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Model\Table\WorkersTable;
use DelayedJobs\Process;
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
class WorkerShell extends Shell
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
     * @var \DelayedJobs\Amqp\AmqpManager
     */
    protected $_amqpManager;
    protected $_tag;
    protected $_startTime;
    protected $_jobCount = 0;
    protected $_lastJob;
    private $__pulse = false;

    /**
     * @inheritDoc
     */
    public function startup()
    {
        $this->loadModel('DelayedJobs.Workers');
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

        $this->_worker = $this->Workers->started($this->_hostName, $this->_workerName, getmypid());

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
        $this->out(sprintf('PID: <info>%s</info>', getmypid()));

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

        if ($this->_tag) {
            $this->_amqpManager->stopListening($this->_tag);
            $this->_tag = null;
        }

        if ($this->_worker) {
            $this->Workers->delete($this->_worker);
        }

        $this->_stop();
    }

    public function main()
    {
        $this->_amqpManager = new AmqpManager();
        $this->_tag = $this->_amqpManager->listen([$this, 'runWorker']);

        $failure_count = 0;

        $this->_heartbeat();

        while ($failure_count <= self::MAXFAIL) {
            try {
                $this->_mainLoop();
                break;
            } catch (\Exception $e) {
                Log::emergency('Delayed job error: ' . $e->getMessage());
                $failure_count++;
            }
        }

        $this->stopHammerTime();
    }

    protected function _mainLoop()
    {
        while (true) {
            if ($this->_worker && $this->_worker->status === WorkersTable::STATUS_SHUTDOWN) {
                $this->stopHammerTime();
                return;
            }

            $ran_job = $this->_amqpManager->wait(self::TIMEOUT);
            $this->_heartbeat($ran_job);

            $this->out(sprintf('Memory usage: <info>%s</info>', Number::toReadableSize(memory_get_usage(true))), 1, Shell::VERBOSE);
            $this->out(sprintf('Peak memory usage: <info>%s</info>', Number::toReadableSize(memory_get_peak_usage(true))), 1, Shell::VERBOSE);
        }
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

        $this->_worker->pulse = new Time();
        if ($job_ran) {
            $this->_worker->job_count++;
        }

        $this->Workers->save($this->_worker);
    }

    public function runWorker(AMQPMessage $message)
    {
        $this->out('');
        if ($this->_io->level() == Shell::NORMAL) {
            $this->out('Got work');
        }
        $body = json_decode($message->body, true);
        $job_id = $body['id'];
        try {
            $job = $this->DelayedJobs->getJob($job_id, true);
            $result = $this->_executeJob($job, $message);
        } catch (RecordNotFoundException $e) {
            if (!isset($body['is-requeue'])) {
                $this->out(__('<error>Job {0} does not exist in the DB - could be a transaction delay - we try once more!</error>',
                    $job_id), 1, Shell::VERBOSE);

                //We do not want to cache the empty result
                $cache_key = 'dj::' . Configure::read('dj.service.name') . '::' . $job_id;
                Cache::delete($cache_key . '::all', Configure::read('dj.service.cache'));
                Cache::delete($cache_key . '::limit', Configure::read('dj.service.cache'));
                $this->_amqpManager->ack($message);
                $this->_amqpManager->requeueMessage($message);

                return;
            }
            $this->out(__('<error>Job {0} does not exist in the DB!</error>',
                $job_id), 1, Shell::VERBOSE);

            $this->_amqpManager->nack($message, false);
        } catch (InvalidPrimaryKeyException $e) {
            $this->dj_log(__('Invalid PK for {0}', $message->body));
            $this->_amqpManager->nack($message, false);
        }

        if ($this->_io->level() == Shell::NORMAL) {
            $this->_welcome();
        }

        unset($job);

        pcntl_signal_dispatch();
    }

    protected function _executeJob(DelayedJob $job, AMQPMessage $message)
    {
        if ($this->_worker && ($this->_worker->status === WorkersTable::STATUS_SHUTDOWN ||
            $this->_worker->status === WorkersTable::STATUS_TO_KILL)) {
            $this->_amqpManager->nack($message);
            return false;
        }

        cli_set_process_title(sprintf('DJ Worker :: %s :: Working %s', $this->_workerId, $job->id));

        $this->out(__('<success>Starting job:</success> {0} :: ', $job->id), 1, Shell::VERBOSE);

        if ($this->DelayedJobs->nextSequence($job)) {
            $this->out(__(' - Sequence <comment>{0}</comment> is already busy', $job->sequence), 1, Shell::VERBOSE);
            $this->dj_log(__('Sequence {0} is already busy', $job->sequence));
            $this->_amqpManager->nack($message, false);
            return false;
        }

        if ($job->status === DelayedJobsTable::STATUS_SUCCESS || $job->status === DelayedJobsTable::STATUS_BURRIED) {
            $this->out(__('Already processed'), 1, Shell::VERBOSE);
            $this->_amqpManager->ack($message);
            return true;
        }

        $default = [
            'max_execution_time' => 25 * 60
        ];
        $options = (array)$job->options + $default;

        $this->DelayedJobs->lock($job, $this->_hostName);
        $this->Worker->executeJob($job);
        $this->_amqpManager->ack($message);
        $this->_lastJob = $job->id;
        $this->_jobCount++;
        $this->out('');
        unset($job);

        return true;
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();
        $options->addSubcommand('worker', [
            'help' => 'Executes a job',
            'parser' => $this->Worker->getOptionParser(),
        ]);
        return $options;
    }

}
