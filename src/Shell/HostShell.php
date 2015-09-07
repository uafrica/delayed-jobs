<?php
namespace DelayedJobs\Shell;

use Cake\Cache\Cache;
use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Log\Log;
use Cake\Utility\Hash;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\Lock;
use DelayedJobs\Model\Entity\DelayedJob;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Model\Table\HostsTable;
use DelayedJobs\Process;
use PhpAmqpLib\Message\AMQPMessage;

class HostShell extends Shell
{
    const UPDATETIMER = 10; //In seconds
    public $Lock;
    public $modelClass = 'DelayedJobs.DelayedJobs';
    protected $_workerId;
    protected $_workerName;
    protected $_workerCount = 1;
    protected $_runningJobs = [];
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
        sleep(5);

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

        /*
         * Get Next Job
         * Get Exclusive Lock on Job
         * Fire Worker
         * Worker fires job
         * Worker monitors the exection time
         */

        //## Need to make sure that any running jobs for this host is in the array job_pids
        $this->out(__('<info>Started up:</info> {0}', $this->_workerId), 1, Shell::VERBOSE);
        $start_time = time();
        $this->_workerCount = $this->param('workers') ?: ($this->_host ? $this->_host->worker_count : 1);
        $this->_amqpManager = new AmqpManager();
        $this->_tag = $this->_amqpManager->listen([$this, 'runWorker'], $this->_workerCount);
        while (true) {
            //Every couple of seconds we update our host entry to catch changes to worker count, or self shutdown
            if (time() - $start_time >= self::UPDATETIMER) {
                $this->nl();
                $this->out('<info>Updating myself...</info>', 0, Shell::VERBOSE);
                $this->_host = $this->Hosts->find()
                    ->where([
                        'host_name' => $host_name,
                        'pid' => getmypid()
                    ])
                    ->first();
                $new_count = $this->param('workers') ?: ($this->_host ? $this->_host->worker_count : 1);

                if ($new_count != $this->_workerCount) {
                    $this->out(' !!Worker count changed!! ', 0, Shell::VERBOSE);
                    $this->_amqpManager->stopListening($this->_tag);
                    $this->_tag = $this->_amqpManager->listen([$this, 'runWorker'], $this->_workerCount);
                    Log::debug(__('{0} updated worker count', $this->_workerId));
                }

                $this->_workerCount = $new_count;

                $start_time = time();
                $this->out('<success>Done</success>', 1, Shell::VERBOSE);
            }

            if ($this->_host && $this->_host->status === HostsTable::STATUS_SHUTDOWN && empty($this->_runningJobs)) {
                $this->out('Time to die :(', 1, Shell::VERBOSE);
                break;
            }

            if ($this->_host && $this->_host->status === HostsTable::STATUS_SHUTDOWN) {
                $this->_amqpManager->stopListening($this->_tag);
            }
            if (!$this->_amqpManager->wait()) {
                $this->out('.', 0, Shell::VERBOSE);
            }
            $this->_checkRunning();
        }

        if ($this->_host) {
            $this->Hosts->delete($this->_host);
        }
    }

    public function runWorker(AMQPMessage $message)
    {
        $body = json_decode($message->body, true);
        $job_id = $body['id'];
        $this->nl();
        try {
            $job = $this->DelayedJobs->getJob($job_id);
            $result = $this->_executeJob($job, $message);

            if ($result === false) {
                $this->_amqpManager->nack($message);
            }
        } catch (RecordNotFoundException $e) {
            if (!isset($body['is-transaction'])) {
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
            Log::debug(__('Invalid PK for {0}', $message->body));
            $this->_amqpManager->nack($message, false);
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
                    Log::debug(__('{0} said {1} timed out', $this->_workerId, $job->id));
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
                Log::debug(__('{0} completed {1}', $this->_workerId, $job->id));
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
                Log::debug(__('{0} said {1} had a runtime error', $this->_workerId, $job->id));
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
            return false;
        }

        $this->out(__('<success>Starting job:</success> {0} :: ', $job->id), 0, Shell::VERBOSE);

        if ($this->DelayedJobs->nextSequence($job)) {
            $this->out(__('Sequence exists <comment>{0}</comment>', $job->sequence), 1, Shell::VERBOSE);
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

        $this->DelayedJobs->lock($job, $this->_workerId);
        $this->_runningJobs[$job->id] = [
            'sequence' => $job->sequence,
            'id' => $job->id,
            'start_time' => microtime(true),
            'max_execution_time' => $options['max_execution_time'],
            'job' => $job,
            'message' => $message
        ];

        $path = ROOT . '/bin/cake DelayedJobs.worker -q ' . $job->id;
        $p = new Process($path);
        $pid = $p->getPid();
        $this->_runningJobs[$job->id]['pid'] = $pid;
        Log::debug(__('{0} started job {1}', $this->_workerId, $job->id));

        $this->out(__('PID <info>{0}</info> :: <info>{1}::{2}</info>', $pid, $job->class, $job->method), 1, Shell::VERBOSE);

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
