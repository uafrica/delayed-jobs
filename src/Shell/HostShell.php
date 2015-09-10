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

        $start_time = time();

        declare(ticks = 1) {
            pcntl_signal(\SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(\SIGINT, [$this, 'handleSignal']);
            pcntl_signal(\SIGUSR1, [$this, 'handleSignal']);
            pcntl_signal(\SIGHUP, [$this, 'handleSignal']);
        }

        $this->_amqpManager = new AmqpManager();
        $this->_tag = $this->_amqpManager->listen([$this, 'runWorker'], 1);
        $this->out(__('<info>Started up:</info> {0}', $this->_workerId), 1, Shell::VERBOSE);

        while (true) {
            $this->_amqpManager->wait();
        }
    }

    public function handleSignal($signal)
    {
        switch ($signal) {
            case \SIGTERM:
            case \SIGUSR1:
            case \SIGINT:
                // some stuff before stop consumer e.g. delete lock etc
                $this->_amqpManager->stopListening($this->_tag);
                $this->_amqpManager->disconnect();

                exit(0);
                break;
            case \SIGHUP:
                // some stuff to restart consumer
                break;
            default:
                // do nothing
        }
    }

    public function runWorker(AMQPMessage $message)
    {
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
            $this->dj_log(__('Invalid job message: {0}', $message->body));
            $this->_amqpManager->nack($message, false);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->dj_log(__('Something bad happened. Hopefully it doesn\'t happen again. {0}', $e->getMessage()));
            $this->_amqpManager->nack($message);
        }
    }

    protected function _checkRunning()
    {
        foreach ($this->_runningJobs as $job_id => &$running_job) {
            $job = $running_job['job'];

            $this->out(__('Job status: {0} :: ', $job_id), 0, Shell::VERBOSE);

            $status = new Process();
            $status->setPid($running_job['pid']);
            $process_running = $running_job['pid'] && $status->status();

            if ($process_running) {
                //## Check if job has not reached it max exec time
                $busy_time = microtime(true) - $running_job['start_time'];

                if (isset($running_job['max_execution_time']) && $busy_time > $running_job['max_execution_time']) {
                    $this->out(__('<error>Job timeout</error>'), 1, Shell::VERBOSE);
                    $status->stop();

                    $this->_amqpManager->nack($this->_runningJobs[$job_id]['message'], false);
                    $this->DelayedJobs->failed($job, 'Job ran too long, killed');
                    unset($this->_runningJobs[$job_id]);
                    $this->dj_log(__('{0} said {1} timed out', $this->_workerId, $job->id));
                } else {
                    $this->out(__('<comment>Still running</comment> :: <info>{0} seconds</info>', round($busy_time, 2)), 1, Shell::VERBOSE);
                }

                continue;
            }

            /*
             * If the process is no longer running, there is a change that it completed successfully
             * We fetch the job from the DB in that case to make sure
             */
            try {
                $job = $this->DelayedJobs->get($job_id, [
                    'fields' => [
                        'id',
                        'pid',
                        'locked_by',
                        'status'
                    ]
                ]);
                $this->_runningJobs[$job_id]['job'] = $job;
            } catch (RecordNotFoundException $e) {
                $this->out(__('<error>Job {0} does not exist in the DB!</error>', $job_id), 1, Shell::VERBOSE);
                $this->_amqpManager->nack($this->_runningJobs[$job_id]['message'], false);
                unset($this->_runningJobs[$job_id]);
                continue;
            }

            if (!$job->pid) {
                $time = microtime(true) - (isset($running_job['start_time']) ? $running_job['start_time'] : microtime(true));
                $this->_amqpManager->ack($this->_runningJobs[$job_id]['message']);
                unset($this->_runningJobs[$job_id]);
                $message = $job->status === DelayedJobsTable::STATUS_SUCCESS ? '<success>Job done</success>' : '<error>Job failed, will be retried</error>';
                $this->dj_log(__('{0} completed {1}', $this->_workerId, $job->id));
                $this->out(__('{0} :: <info>{1} seconds</info>', $message, round($time, 2)), 1, Shell::VERBOSE);
                continue;
            }

            if ($job->pid && $job->status === DelayedJobsTable::STATUS_BUSY) {
                //## Make sure that this job is not marked as running
                $this->_amqpManager->nack($this->_runningJobs[$job_id]['message'], false);
                $this->DelayedJobs->failed(
                    $job,
                    'Job not running, but db said it is, could be a runtime error'
                );
                unset($this->_runningJobs[$job_id]);
                $this->dj_log(__('{0} said {1} had a runtime error', $this->_workerId, $job->id));
                $this->out(__('<error>Job not running, but should be</error>'), 1, Shell::VERBOSE);
            }
        }
    }

    protected function _updateRunning()
    {
        $db_jobs = $this->DelayedJobs->getRunning();
        foreach ($db_jobs as $running_job) {
            if (empty($this->_runningJobs[$running_job->id])) {
                $this->_runningJobs[$running_job->id] = [
                    'id' => $running_job->id,
                    'pid' => $running_job->pid,
                    'sequence' => $running_job->sequence
                ];
            }
            $this->_runningJobs[$running_job->id]['job'] = $running_job;
        }
    }

    protected function _executeJob(DelayedJob $job, AMQPMessage $message)
    {
        if ($this->_host && ($this->_host->status === HostsTable::STATUS_SHUTDOWN ||
            $this->_host->status === HostsTable::STATUS_TO_KILL)) {
            $this->_amqpManager->nack($message);
            return false;
        }

        $this->out(__('<success>Starting job:</success> {0} :: ', $job->id), 0, Shell::VERBOSE);

        if ($this->DelayedJobs->nextSequence($job)) {
            $this->out(__('Sequence <comment>{0}</comment> is already busy', $job->sequence), 1, Shell::VERBOSE);
            $this->dj_log(__('Sequence {0} is already busy', $job->sequence));
            $this->_amqpManager->nack($message, false);
            return false;
        }

        if ($job->status === DelayedJobsTable::STATUS_SUCCESS || $job->status === DelayedJobsTable::STATUS_BURRIED) {
            $this->out(__('Already processed'), 1, Shell::VERBOSE);
            $this->_amqpManager->ack($message);
            return true;
        }

        $options = (array)$job->options;

        if (!isset($options['max_execution_time'])) {
            $options['max_execution_time'] = 25 * 60;
        }

        $this->dj_log(__('{0} starting job {1}', $this->_workerId, $job->id));
        $job->start_time = new Time();

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

            $this->out(__(' :: Took <comment>{0,number}</comment> seconds', $job->start_time->diffInSeconds($job->end_time)), 1, Shell::VERBOSE);
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

            $this->out('<fail>Job ' . $job_id . ' Failed (' . $exc->getMessage() . ')</fail>', 1, Shell::VERBOSE);
        }
        $this->_runningJob = null;
        $this->_amqpManager->ack($message);
        cli_set_process_title(__('{0} - Waiting for job - Finished {1}', $this->_workerId, $job->id));

        pcntl_signal_dispatch();
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
