<?php
namespace DelayedJobs\Shell;

use Cake\Cache\Cache;
use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\Utility\Hash;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\Lock;
use DelayedJobs\Model\Entity\DelayedJob;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Model\Table\HostsTable;
use DelayedJobs\Process;
use DelayedJobs\Traits\DebugTrait;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class HostShell
 *
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 * @property \DelayedJobs\Shell\Task\WorkerTask $Worker
 */
class HostShell extends Shell
{
    use DebugTrait;

    const UPDATETIMER = 5; //In seconds
    const MAXFAIL = 5;
    public $modelClass = 'DelayedJobs.DelayedJobs';
    public $tasks = ['DelayedJobs.Worker'];
    protected $_workerId;
    protected $_workerName;
    protected $_hostName;
    protected $_runningJobs = [];
    protected $_host;
    /**
     * @var \DelayedJobs\Amqp\AmqpManager
     */
    protected $_amqpManager;
    protected $_tag;
    protected $_startTime;
    protected $_jobCount = 0;
    protected $_lastJob;

    /**
     * @inheritDoc
     */
    public function startup()
    {
        $this->loadModel('DelayedJobs.Hosts');
        $this->_hostName = php_uname('n');
        $this->_workerName = Configure::read('dj.service.name');
        $this->_startTime = time();

        if (isset($this->args[0])) {
            $this->_workerName = $this->args[0];
        }

        $this->_host = $this->Hosts->find()
            ->where([
                'host_name' => $this->_hostName,
                'pid' => getmypid()
            ])
            ->first();

        $this->_workerId = $this->_hostName . '.' . $this->_workerName;

        parent::startup();
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

    public function main()
    {
        $this->_amqpManager = new AmqpManager();
        $this->_tag = $this->_amqpManager->listen([$this, 'runWorker']);

        $failure_count = 0;

        while ($failure_count <= self::MAXFAIL) {
            try {
                $this->_mainLoop();
                break;
            } catch (\Exception $e) {
                Log::emergency('Delayed job error: ' . $e->getMessage());
                $failure_count++;
            }
        }

        if ($this->_host) {
            $this->Hosts->delete($this->_host);
        }
    }

    protected function _mainLoop()
    {
        $start_time = time();

        $waiting_string = 'Waiting for work: %s';
        $chars = [
            '-',
            '\\',
            '|',
            '/'
        ];
        $char_key = 0;
        $this->out(sprintf($waiting_string, $chars[$char_key++]), 0);

        while (true) {
            //Every couple of seconds we update our host entry to catch changes to worker count, or self shutdown
            if (time() - $start_time >= self::UPDATETIMER) {
                $this->_host = $this->Hosts->find()
                    ->where([
                        'host_name' => $this->_hostName,
                        'pid' => getmypid()
                    ])
                    ->first();

                $start_time = time();
            }

            if ($this->_host && $this->_host->status === HostsTable::STATUS_SHUTDOWN && empty($this->_runningJobs)) {
                $this->out('Time to die :(', 1, Shell::VERBOSE);
                break;
            }

            if ($this->_host && $this->_host->status === HostsTable::STATUS_SHUTDOWN) {
                $this->_amqpManager->stopListening($this->_tag);
            }
            if (!$this->_amqpManager->wait()) {
                if ($this->_io->level() >= Shell::NORMAL) {
                    $this->_io->overwrite(sprintf($waiting_string, $chars[$char_key++]), 0);
                    if ($char_key >= count($chars)) {
                        $char_key = 0;
                    }
                }
            } else {
                $char_key = 0;
                $this->out(sprintf($waiting_string, $chars[$char_key++]), 0);
            }
        }
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
    }

    protected function _executeJob(DelayedJob $job, AMQPMessage $message)
    {
        if ($this->_host && ($this->_host->status === HostsTable::STATUS_SHUTDOWN ||
            $this->_host->status === HostsTable::STATUS_TO_KILL)) {
            $this->_amqpManager->nack($message);
            return false;
        }

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

        $this->DelayedJobs->lock($job, $this->_workerId);
        $this->Worker->executeJob($job);
        $this->_amqpManager->ack($message);
        $this->_lastJob = $job->id;
        $this->_jobCount++;
        $this->out('');
        return true;
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options
            ->addOption(
                'workers',
                [
                    'help' => 'Number of jobs to run concurrently',
                ]
            )
            ->addArgument('workerName', [
                'help' => 'Custom worker name to use',
            ]);

        return $options;
    }

}
