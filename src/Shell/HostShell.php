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
use ProcessMQ\Queue;

/**
 * Class HostShell
 *
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 * @property \ProcessMQ\Shell\Task\RabbitMQWorkerTask $RabbitMQWorker
 */
class HostShell extends Shell
{
    use DebugTrait;

    const UPDATETIMER = 10; //In seconds
    public $Lock;
    public $modelClass = 'DelayedJobs.DelayedJobs';
    protected $_workerId;
    protected $_workerName;
    protected $_workerCount = 1;
    public $_runningJob;
    protected $_host;
    /**
     * @var \DelayedJobs\Amqp\AmqpManager
     */
    protected $_amqpManager;
    protected $_tag;

    public $tasks = ['ProcessMQ.RabbitMQWorker'];

    protected function _welcome()
    {
        $hostname = php_uname('n');

        $this->clear();
        $this->out('Hostname: <info>' . $hostname . '</info>', 1, Shell::VERBOSE);
        $this->hr();
    }

    public function main()
    {
        $this->out(__('Booting... My PID is <info>{0}</info>', getmypid()), 1, Shell::VERBOSE);

        //Wait 5 seconds for watchdog to finish
        sleep(2);

        $this->loadModel('DelayedJobs.Hosts');
        $host_name = php_uname('n');
        $this->_workerName = Configure::read('dj.service.name');

        if (isset($this->args[0])) {
            $this->_workerName = $this->args[0];
        }

        $this->_host = $this->Hosts
            ->find()
            ->where([
                'host_name' => $host_name,
                'pid' => getmypid()
            ])
            ->first();

        $this->_workerId = $host_name . '.' . $this->_workerName;

        $this->out(__('<info>Started up:</info> {0}', $this->_workerId), 1, Shell::VERBOSE);
        $this->RabbitMQWorker->consume('direct', [$this, 'runWorker']);

        while (true) {
        }
    }

    public function runWorker($body, \AMQPEnvelope $envelope, \AMQPQueue $queue)
    {
        $job_id = $body['id'];
        try {
            $job = $this->DelayedJobs->getJob($job_id, true);
            return $this->_executeJob($job, $envelope, $queue);
        } catch (RecordNotFoundException $e) {
            if (!isset($body['is-requeue'])) {
                $this->out(__('<error>Job {0} does not exist in the DB - could be a transaction delay - we try once more!</error>',
                    $job_id), 1, Shell::VERBOSE);

                //We do not want to cache the empty result
                $cache_key = 'dj::' . Configure::read('dj.service.name') . '::' . $job_id;
                Cache::delete($cache_key . '::all', Configure::read('dj.service.cache'));
                Cache::delete($cache_key . '::limit', Configure::read('dj.service.cache'));
                //$this->_amqpManager->requeueMessage($body);

                return true;
            }
            $this->out(__('<error>Job {0} does not exist in the DB!</error>',
                $job_id), 1, Shell::VERBOSE);

            $queue->nack($envelope->getDeliveryTag());
        } catch (InvalidPrimaryKeyException $e) {
            $this->dj_log(__('Invalid job message: {0}', $body));
            $queue->nack($envelope->getDeliveryTag());
        } catch (\Exception $e) {
            if (isset($this->bad)) {
                $this->dj_log(__('It happened again :( {0}', $e->getMessage()));
                throw $e;
            }
            Log::error($e->getMessage());
            $this->dj_log(__('Something bad happened. Hopefully it doesn\'t happen again. {0}', $e->getMessage()));
            $this->bad = true;
            return false;
        }

        return true;
    }

    protected function _executeJob(DelayedJob $job, \AMQPEnvelope $envelope, \AMQPQueue $queue)
    {
        if ($this->_host && ($this->_host->status === HostsTable::STATUS_SHUTDOWN ||
            $this->_host->status === HostsTable::STATUS_TO_KILL)) {
            return false;
        }

        $this->out(__('<success>Starting job:</success> {0} :: ', $job->id), 0, Shell::VERBOSE);

        if ($this->DelayedJobs->nextSequence($job)) {
            $this->out(__('Sequence <comment>{0}</comment> is already busy', $job->sequence), 1, Shell::VERBOSE);
            $this->dj_log(__('Sequence {0} is already busy', $job->sequence));
            return false;
        }

        if ($job->status === DelayedJobsTable::STATUS_SUCCESS || $job->status === DelayedJobsTable::STATUS_BURRIED) {
            $this->out(__('Already processed'), 1, Shell::VERBOSE);
            return true;
        }

        $options = (array)$job->options;

        if (!isset($options['max_execution_time'])) {
            $options['max_execution_time'] = 25 * 60;
        }

        $this->dj_log(__('{0} starting job {1}', $this->_workerId, $job->id));
        $job->start_time = new Time();
        $start_time = microtime(true);

        $this->out(__('<info>{0}::{1}</info>', $job->class, $job->method), 0, Shell::VERBOSE);

        $this->_runningJob = $job;
        try {
            cli_set_process_title(__('{0} - Executing Job #{1}', $this->_workerId, $job->id));
            $this->out(' :: <info>Executing</info>', 0, Shell::VERBOSE);
            $this->dj_log(__('Executing: {0}', $job->id));
            $response = $job->execute();
            $this->out(' :: <success>Done</success>', 0, Shell::VERBOSE);
            $this->dj_log(__('Done with: {0}', $job->id));

            $this->DelayedJobs->completed($job, is_string($response) ? $response : null);

            //Recuring job
            if ($response instanceof \DateTime) {
                $recuring_job = clone $job;
                $recuring_job->run_at = $response;
                $this->DelayedJobs->save($recuring_job);
            }
        } catch (\Exception $exc) {
            //## Job Failed
            $this->DelayedJobs->failed($job, $exc->getMessage());
            $this->dj_log(__('Failed {0} because {1}', $job->id, $exc->getMessage()));

            $this->out(' :: <error>Failed</error> :: <info>' . $exc->getMessage() . '</info>', 0, Shell::VERBOSE);
        }
        $this->out(__(' :: Took <comment>{0,number}</comment> seconds', microtime(true) - $start_time), 1,
            Shell::VERBOSE);

        $this->_runningJob = null;
        cli_set_process_title(__('{0} - Waiting for job - Finished {1}', $this->_workerId, $job->id));

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
