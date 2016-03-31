<?php

namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\Time;
use Cake\ORM\TableRegistry;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\Manager;
use DelayedJobs\DelayedJob\EnqueueTrait;
use DelayedJobs\Lock;
use DelayedJobs\Model\Entity\Worker;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Model\Table\WorkersTable;
use DelayedJobs\Process;

/**
 * Class WatchdogShell
 *
 * @property \DelayedJobs\Model\Table\WorkersTable $Workers
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class WatchdogShell extends Shell
{
    use EnqueueTrait;

    const BASEPATH = ROOT . '/bin/cake DelayedJobs.worker ';
    public $Lock;
    public $modelClass = 'DelayedJobs.Workers';
    protected $_workers;

    /**
     * Creates (cpu-count - 1) worker processes (Minimum of 1 worker)
     *
     * @return int
     */
    protected function _autoWorker()
    {
        $worker_count = (int)exec('nproc') - 1;

        return $worker_count >= 1 ? $worker_count : 1;
    }

    protected function _welcome()
    {
        if (!Configure::check('dj.service.name')) {
            throw new Exception('Could not load config, check your load settings in bootstrap.php');
        }
        $hostname = php_uname('n');

        $this->clear();
        $this->out('App Name: <info>' . Configure::read('dj.service.name') . '</info>');
        $this->out('Hostname: <info>' . $hostname . '</info>');
        $this->hr();
    }

    /**
     * @return void
     */
    public function main()
    {
        if (file_exists(TMP . '/lockWatchdog')) {
            $this->out('Lock file exists, quiting', 1, Shell::VERBOSE);
            $this->_stop();
        }

        $this->out('Starting Watchdog');

        if ($this->param('workers') > 0) {
            $this->startWorkers();
        } else {
            $this->stopWorkers();
        }

        $this->recurring();
        $this->clean();

        $this->out('<success>!! All done !!</success>');
    }

    protected function _checkHeartbeat(Worker $worker)
    {
        $max_time = Configure::read('dj.max.execution.time');
        $last_beat = $worker->pulse->diffInSeconds();
        return $last_beat <= $max_time;
    }

    public function startWorkers($worker_count = null)
    {
        $worker_count = $worker_count ?: $this->param('workers');
        $max_workers = Configure::read('dj.max.workers');

        if ($worker_count > $max_workers) {
            $worker_count = $max_workers;
            $this->out('<error>Too many workers (max_workers:' . $max_workers . ')</error>');
        }

        $hostname = php_uname('n');
        $workers = $this->Workers->find('forHost', ['host' => $hostname]);

        $this->out(sprintf(' - Require <info>%d</info> Workers to be running. <info>%d</info> currently running.', $worker_count, $workers->count()));

        $this->out(' - Checking status of running workers.');

        foreach ($workers as $worker) {
            $this->_checkWorkerInstance($worker);
        }

        $workers = $workers->cleanCopy();

        if ($workers->count() > $worker_count) {
            $this->out(' - Too many workers, shutting some down.');
            $workers_to_shutdown = $workers->skip($worker_count);
            foreach ($workers_to_shutdown as $worker) {
                $this->_stopWorker($worker);
            }
        } elseif ($workers->count() < $worker_count) {
            $this->out(' - Not enough workers, starting some up.');
            for ($i = $workers->count(); $i < $worker_count; $i++) {
                $this->_startWorker();
            }
        } else {
            $this->out(' - Just right.');
        }
    }

    public function stopWorkers()
    {
        $hostname = php_uname('n');
        $workers = $this->Workers->find()
            ->where([
                'host_name' => $hostname
            ]);

        if ($workers->count() === 0) {
            $this->out('No workers to stop');
            return;
        }

        foreach ($workers as $worker) {
            $this->_stopWorker($worker);
        }

        if ($this->param('wait')) {
            $this->_waitForStop($hostname);
        }
    }

    protected function _startWorker()
    {
        try {
            $this->_createWorkerInstance();
        } catch (Exception $exc) {
            $this->out('<fail>Failed: ' . $exc->getMessage() . '</fail>');
        }
    }

    protected function _stopWorker(Worker $worker)
    {
        //## Host is in the database, tell the host to gracefully shutdown
        $this->out(__(' - Told {0}.{1} to shutdown', $worker->host_name, $worker->worker_name));
        $worker->status = WorkersTable::STATUS_SHUTDOWN;
        $this->Workers->save($worker);
    }

    protected function _killWorkers()
    {
        $hostname = php_uname('n');
        $workers = $this->Workers->find()
            ->where([
                'host_name' => $hostname
            ]);
        foreach ($workers as $worker) {
            $this->_kill($worker->pid, $worker->worker_name);
            $this->Workers->delete($worker);
        }
    }

    public function recurring()
    {
        $this->out('Firing recurring event.');
        $event = new Event('DelayedJobs.recurring', $this);
        $event->result = [];
        EventManager::instance()->dispatch($event);

        $this->out(__('{0} jobs to queue', count($event->result)), 1, Shell::VERBOSE);
        foreach ($event->result as $job) {
            if (!$job instanceof Job) {
                $job = new Job($job + [
                        'group' => 'Recurring',
                        'priority' => 100,
                        'maxRetries' => 5,
                        'runAt' => new Time('+30 seconds')
                    ]);
            }

            if (Manager::instance()->isSimilarJob($job)) {
                $this->out(__('  <error>Already queued:</error> {0}', $job->getWorker()), 1, Shell::VERBOSE);
                continue;
            }

            $this->enqueue($job);

            $this->out(__('  <success>Queued:</success> {0}::{1}', $job['class'], $job['method']), 1, Shell::VERBOSE);
        }
    }

    public function clean()
    {
        $this->out('Cleaning jobs.');
        $this->loadModel('DelayedJobs.DelayedJobs');
        $cleaned = $this->DelayedJobs->clean();
        $this->out(sprintf('<success>Cleaned:</success> %d jobs', $cleaned));
    }

    /**
     * @param int $pid PID to kill
     * @param string $worker_name Worker name
     * @return void
     */
    protected function _kill($pid, $worker_name)
    {
        $this->out(sprintf('<info>To kill:</info> %s (pid: %s)', $worker_name, $pid), 1, Shell::VERBOSE);

        $process = new Process();
        $process->setPid($pid);
        $process->stop();

        if ($process->status()) {
            $this->out(sprintf('<error>Could not stop:</error> %s (pid: %s)', $worker_name, $pid), 1, Shell::VERBOSE);
        } else {
            $this->out(sprintf('<error>Killed:</error> %s (pid: %s)', $worker_name, $pid), 1, Shell::VERBOSE);
        }
    }

    /**
     * @return void
     */
    protected function _createWorkerInstance()
    {
        $this->out('   - Starting new worker instance', 0);

        $base_path = self::BASEPATH;

        //## Host not found in database, start it
        $process = new Process($base_path . ' -q --qos ' . $this->param('qos'));
        sleep(2);

        if (!$process->status()) {
            $this->out(' :: <error>Could not start worker</error>');
        } else {
            $this->out(sprintf(' :: <success>Started worker</success> (pid: %s)', $process->getPid()));
        }
    }

    protected function _checkWorkerInstance(Worker $worker)
    {
        $this->out(sprintf('   - Checking worker <info>%s</info> (%s).', $worker->worker_name, $worker->pid));

        $process = new Process();
        $process->setPid($worker->pid);
        $details = $process->details();
        $process_running = strpos($details, $worker->worker_name) !== false;

        if ($process_running) {
            if ($worker->status == WorkersTable::STATUS_IDLE) {
                //## Process is actually running, update status
                $this->Workers->setStatus($worker, WorkersTable::STATUS_RUNNING);
                $this->out('    - Running, but marked as idle. Changing status to running.');
            } elseif ($worker->status == WorkersTable::STATUS_TO_KILL) {
                $this->out('    - Running, but marked for kill. Killing now.');
                $this->_kill($worker->pid, $worker->worker_name);
                $this->Workers->delete($worker);

                return;
            } elseif ($worker->status == WorkersTable::STATUS_SHUTDOWN) {
                $this->out('    - Running, but scheduled to shutdown soon.');
            } elseif ($worker->status != WorkersTable::STATUS_RUNNING) {
                $this->Workers->setStatus($worker, WorkersTable::STATUS_RUNNING);
                $this->out('    - Unknown status, but running. Changing status to running.');
            }

            $alive = $this->_checkHeartbeat($worker);

            if (!$alive) {
                $this->out('    - <error>Flatlined</error>. Killing immediately');
                $this->_kill($worker->pid, $worker->worker_name);
                $this->Workers->delete($worker);
            }

            $this->out('    - <success>Alive and well.</success>');
        } else {
            //## Process is not running, delete record
            $this->Workers->delete($worker);
            $this->out('    - Not running. Removing db record.');
        }
    }

    /**
     * @return void
     */
    protected function _waitForStop($host_name)
    {
        $this->out(' - Waiting for all workers to stop');
        $workers = $this->Workers->find()
            ->where([
                'host_name' => $host_name
            ]);

        foreach ($workers as $worker) {
            $process = new Process();
            $process->setPid($worker->pid);
            if (!$process->status()) {
                $this->Workers->delete($worker);
            }
        }

        $start_time = time();
        $workers = $workers->cleanCopy();
        $worker_count = $workers->count();
        $this->out(sprintf('  - Running workers: %s', $worker_count), 0);
        while ($worker_count > 0 && (time() - $start_time) <= 600) {
            sleep(1);
            $workers = $workers->cleanCopy();
            $worker_count = $workers->count();
            $this->_io->overwrite(sprintf('  - Running workers: %s', $worker_count), 0);
        }
        $this->out('');

        if ($workers->count() > 0 && time() - $start_time > 600) {
            $this->out(' - Timeout waiting for hosts, killing manually');
            $this->_killWorkers();
        }
    }

    /**
     * Reloads all running hosts
     *
     * @return void
     */
    public function reload()
    {
        $host_name = php_uname('n');

        $workers = $this->Workers->find()
            ->where([
                'host_name' => $host_name
            ]);
        if ($workers->count() == 0) {
            $this->out('<error>No workers running</error>');
            $this->_stop(1);
        }

        $worker_count = $workers->count();
        $this->out(' - Killing running workers.');
        $this->stopWorkers();

        $this->_waitForStop($host_name);

        $this->out(' - Restarting workers.');
        $this->startWorkers($worker_count);
    }

    public function requeue()
    {
        $job = TableRegistry::get('DelayedJobs.DelayedJobs')
            ->get($this->args[0]);

        if ($job->status === DelayedJobsTable::STATUS_NEW || $job->status === DelayedJobsTable::STATUS_FAILED) {
            $job->queue();
            $this->out(__('<success>{0} has been queued</success>', $job->id));
        } else {
            $this->out(__('<error>{0} could not be queued</error>', $job->id));
        }
    }

    public function revive()
    {
        $stats = AmqpManager::queueStatus();
        if ($stats['messages'] > 0) {
            $this->out(__('<error>There are {0} messages currently queued</error>', $stats['messages']));
            $this->out('We cannot reliablily determine which messages to requeue unless the RabbitMQ queue is empty.');
            $this->_stop(1);
        }

        $this->loadModel('DelayedJobs.DelayedJobs');
        $sequences = $this->DelayedJobs->find()
            ->distinct(['sequence'])
            ->select([
                'id',
                'status',
                'priority',
                'sequence',
                'run_at'
            ])
            ->where([
                'status in' => [DelayedJobsTable::STATUS_NEW, DelayedJobsTable::STATUS_FAILED],
                'sequence is not' => null
            ])
            ->order([
                'priority' => 'asc',
                'id' => 'asc'
            ])
            ->all();

        $no_sequences = $this->DelayedJobs->find()
            ->select([
                'id',
                'status',
                'priority',
                'sequence',
                'run_at'
            ])
            ->where([
                'status in' => [DelayedJobsTable::STATUS_NEW, DelayedJobsTable::STATUS_FAILED],
                'sequence is' => null
            ])
            ->order([
                'priority' => 'asc',
                'id' => 'asc'
            ])
            ->all();

        $all_jobs = $sequences->append($no_sequences);
        foreach ($all_jobs as $job) {
            /**
             * @var \DelayedJobs\Model\Entity\DelayedJob $job
             */
            if ($this->_io->level() < Shell::VERBOSE) {
                $this->out('.', 0, Shell::QUIET);
            }

            $this->out(__(' - Queing job <info>{0}</info>', $job->id), 1, Shell::VERBOSE);
            $job->queue();
        }
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options
            ->addSubcommand('start-workers', [
                'help' => 'Starts workers',
                'parser' => [
                    'options' => [
                        'workers' => [
                            'help' => 'Number of workers to run',
                            'default' => $this->_autoWorker()
                        ]
                    ]
                ]
            ])
            ->addSubcommand('stop-workers', [
                'help' => 'Stops workers',
                'parser' => [
                    'options' => [
                        'wait' => [
                            'help' => 'Wait for workers to stop.',
                            'default' => false,
                            'boolean' => true
                        ]
                    ]
                ]
            ])
            ->addSubcommand('clean', [
                'help' => 'Cleans out jobs that are completed and older than 4 weeks'
            ])
            ->addSubcommand('recurring', [
                'help' => 'Fires the recurring event and creates the initial recurring job instance'
            ])
            ->addSubcommand('reload', [
                'help' => 'Restarts all running worker hosts'
            ])
            ->addSubcommand('revive', [
                'help' => 'Requeues all new or failed jobs that should be in RabbitMQ'
            ])
            ->addSubcommand('requeue', [
                'help ' => 'Receues a job',
                'parser' => [
                    'arguments' => [
                        'id' => [
                            'help' => 'Job id',
                            'required' => true
                        ]
                    ]
                ]
            ])
            ->addOption('qos', [
                'help' => 'Sets the QOS value for workers',
                'default' => 1
            ])
            ->addOption('workers', [
                'help' => 'Number of workers to run',
                'default' => $this->_autoWorker()
            ]);

        return $options;
    }

}
