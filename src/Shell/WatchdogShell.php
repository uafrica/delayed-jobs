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
use DelayedJobs\Lock;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Model\Table\HostsTable;
use DelayedJobs\Process;

/**
 * Class WatchdogShell
 * @property \DelayedJobs\Model\Table\HostsTable $Hosts
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class WatchdogShell extends Shell
{

    const BASEPATH = ROOT . '/bin/cake DelayedJobs.host ';
    public $Lock;
    public $modelClass = 'DelayedJobs.Hosts';
    protected $_workers;

    /**
     * Creates (cpu-count - 1) worker processes (Minimum of 1 worker)
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
        $this->Lock = new Lock();
        if (!$this->Lock->lock('DelayedJobs.WatchdogShell.main')) {
            $this->_stop(1);
        }

        $this->out('Starting Watchdog');

        if ($this->param('hosts') > 0) {
            $this->startHosts();
        } else {
            $this->stopHosts();
        }

        $this->recuring();
        $this->clean();

        $this->out('<success>!! All done !!</success>');
        $this->Lock->unlock('DelayedJobs.WorkerShell.main');
    }

    public function startHosts($host_count = null, $worker_count = null)
    {
        $host_count = $host_count ?: $this->param('hosts');
        $worker_count = $worker_count ?: $this->param('workers');
        $max_hosts = Configure::read('dj.max.hosts');

        if ($host_count > $max_hosts) {
            $host_count = $max_hosts;
            $this->out('<error>Too many hosts (max_hosts:' . $max_hosts . ')</error>');
        }
        if ($worker_count > Configure::read('dj.max.workers')) {
            $worker_count = Configure::read('dj.max.workers');
        }

        $this->out('Starting Watchdog: <info>' . $host_count . ' Hosts</info>');

        $service_name = Configure::read('dj.service.name');
        for ($i = 1;$i <= $host_count;$i++) {
            $this->_startHost($service_name . '-host-' . $i, $worker_count);
        }

        $hostname = php_uname('n');
        for ($i = $host_count + 1; $i <= $max_hosts; $i++) {
            $worker_name = Configure::read('dj.service.name') . '-host-' . $i;
            $host = $this->Hosts->findByHost($hostname, $worker_name);
            if ($host) {
                //## Host is in the database, need to remove it
                $this->_stopHost($host);
            } else {
                //## No Host record found, just kill it with fire if it exists
                $check_pid = (new Process())->getPidByName($worker_name);
                if ($check_pid) {
                    $this->_kill($check_pid, $worker_name);
                }
            }
        }
    }

    public function stopHosts()
    {
        $hostname = php_uname('n');
        $hosts = $this->Hosts->find()
            ->where([
                'host_name' => $hostname
            ]);
        foreach ($hosts as $host) {
            $this->_stopHost($host);
        }
    }

    protected function _startHost($worker_name, $worker_count)
    {
        try {
            $host_name = php_uname('n');

            $host = $this->Hosts->findByHost($host_name, $worker_name);

            if (!$host) {
                $this->_createHostInstance($host_name, $worker_name, $worker_count);
            } else {
                $this->_checkHostInstance($host, $worker_count);
            }
        } catch (Exception $exc) {
            $this->out('<fail>Failed: ' . $exc->getMessage() . '</fail>');
        }
    }

    protected function _stopHost($host)
    {
        //## Host is in the database, tell the host to gracefully shutdown
        $this->out(__('Told {0}.{1} to shutdown', $host->host_name, $host->worker_name));
        $host->status = HostsTable::STATUS_SHUTDOWN;
        $host->worker_count = 0;
        $this->Hosts->save($host);
    }

    public function recuring()
    {
        $this->out('Firing recuring event.');
        $event = new Event('DelayedJobs.recuring');
        $event->result = [];
        EventManager::instance()->dispatch($event);

        $this->loadModel('DelayedJobs.DelayedJobs');
        $this->out(__('{0} jobs to queue', count($event->result)), 1, Shell::VERBOSE);
        foreach ($event->result as $job) {
            if ($this->DelayedJobs->jobExists($job)) {
                $this->out(__('  <error>Already queued:</error> {0}::{1}', $job['class'], $job['method']), 1, Shell::VERBOSE);
                continue;
            }

            $dj_data = $job + [
                'priority' => 100,
                'options' => ['max_retries' => 5],
                'run_at' => new Time('+30 seconds')
            ];

            $job_event = new Event('DelayedJob.queue', $dj_data);
            EventManager::instance()->dispatch($job_event);
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
        $this->out(
            sprintf(
                '<info>To kill:</info> %s (pid: %s)',
                $worker_name,
                $pid
            )
        );

        $process = new Process();
        $process->setPid($pid);
        $process->stop();

        if ($process->status()) {
            $this->out(
                sprintf(
                    '<error>Could not stop:</error> %s (pid: %s)',
                    $worker_name,
                    $pid
                )
            );
        } else {
            $this->out(
                sprintf(
                    '<error>Killed:</error> %s (pid: %s)',
                    $worker_name,
                    $pid
                ),
                2
            );
        }
    }

    /**
     * @param $host_name
     * @param $worker_name
     * @return mixed
     */
    protected function _createHostInstance($host_name, $worker_name, $worker_count)
    {
        $this->out(__('Starting: <info>{0}.{1}</info> with {2} workers', $host_name, $worker_name, $worker_count));

        $base_path = self::BASEPATH;

        //## Host not found in database, start it
        $process = new Process($base_path . ' ' . $worker_name);
        $pid = $process->getPid();
        $host = $this->Hosts->started($host_name, $worker_name, $pid, $worker_count);

        sleep(2);

        if (!$process->status()) {
            $this->Hosts->delete($host);
            $this->out(
                '<error>Host: ' . $worker_name . ' Could not be started, Trying to find process to kill it?</error>'
            );

            $check_pid = $process->getPidByName('DelayedJobs.host ' . $worker_name);

            if ($check_pid) {
                $process->setPid($check_pid);
                $process->stop();

                $this->out(
                    '<success>Host: ' . $worker_name . ' Found a process and killed it</success>'
                );
            } else {
                $this->out(
                    '<error>Host: ' . $worker_name . ' Could not find any processes to kill</error>'
                );
            }
        } elseif (!$host) {
            $process->stop();
            $this->out(
                sprintf(
                    '<error>Could not start:</error> %s', $worker_name
                ),
                2
            );
        } else {
            $this->out(
                sprintf(
                    '<success>Started:</success> %s (pid: %s)', $worker_name,
                    $host->pid
                ),
                2
            );
        }

        return $host;
    }

    protected function _checkHostInstance($host, $worker_count)
    {
        $process = new Process();
        $process->setPid($host->pid);
        $details = $process->details();

        if (strpos($details, 'host ' . $host->worker_name) !== false) {
            $process_running = true;
        } else {
            $process_running = false;
        }

        if ($host->status == HostsTable::STATUS_IDLE) {
            //## Host is idle, need to start it

            if ($process_running) {
                //## Process is actually running, update status
                $this->Hosts->setStatus($host, HostsTable::STATUS_RUNNING);
                $this->out(
                    '<info>Host: ' . $host->worker_name . ' Idle, Changing status (pid:' . $host->pid . ')</info>',
                    2
                );
            } else {
                //## Process is not running, delete record
                $this->Hosts->delete($host);
                $this->out(
                    '<error>Host: ' . $host->worker_name . ' Not running but reported IDLE state, Removing database
                     record (pid:' . $host->pid . ')</error>',
                    2
                );
            }
        } elseif ($host->status == HostsTable::STATUS_RUNNING) {
            //## Host is running, please confirm
            if ($process_running) {
                //## Process is actually running, update status

                $host->worker_count = $worker_count;
                $this->Hosts->setStatus($host, HostsTable::STATUS_RUNNING);
                $this->out(
                    sprintf(
                        '<success>Running:</success> %s (pid: %s)',
                        $host->worker_name,
                        $host->pid
                    )
                );
            } else {
                //## Process is not running, delete record and try to start it
                $this->Hosts->delete($host);
                $this->out(
                    sprintf(
                        '<error>Not running, restarting:</error> %s (pid: %s)',
                        $host->worker_name,
                        $host->pid
                    )
                );

                $this->_startHost($host->worker_name, $worker_count);
            }
        } elseif ($host->status == HostsTable::STATUS_TO_KILL) {
            //## Kill it with fire
            if ($process_running) {
                $this->_kill($host->pid, $host->worker_name);
            }
            $this->Hosts->delete($host);
        } elseif ($host->status == HostsTable::STATUS_SHUTDOWN) {
            $this->out('<info>Worker: ' .
                $host->worker_name .
                ' has been told to shutdown. It will do so in due course. (pid:' .
                $host->pid .
                ')</info>');
        } else {
            //## Something went wrong, horribly wrong
            if ($process_running) {

                $host->worker_count = $worker_count;
                //## Process is actually running, update status
                $this->Hosts->setStatus($host, HostsTable::STATUS_RUNNING);
                $this->out(
                    '<info>Worker: ' . $host->worker_name . ' Unknown Status, but running, changing status (pid:' . $host->pid . ')</info>'
                );
            } else {
                //## Process is not running, delete record
                $this->Hosts->remove($host);
                $this->out(
                    '<error>Worker: ' . $host->worker_name . ' Unknown status and not running, removing host (pid:' . $host->pid . ')</error>'
                );
            }
        }
    }

    /**
     * Reloads all running hosts
     * @return void
     */
    public function reload()
    {
        $host_name = php_uname('n');
        $worker_name = Configure::read('dj.service.name');

        $hosts = $this->Hosts->find()
            ->where([
                'host_name' => $host_name
            ]);
        if ($hosts->count() == 0) {
            $this->out('<error>No hosts running</error>');
            $this->_stop(1);
        }

        $worker_count = $hosts->first()->worker_count;
        $host_count = $hosts->count();
        $this->out(' - Killing running hosts.');
        $this->stopHosts();

        $this->out(' - Waiting for all hosts to stop');
        $hosts = $this->Hosts->find()
            ->where([
                'host_name' => $host_name
            ]);
        while ($hosts->count() > 0) {
            sleep(1);
            $this->out('.', 0);
        }

        $this->out(' - Restarting hosts.');
        $this->startHosts($host_count, $worker_count);
    }

    public function monitor()
    {
        $this->out('Moved into own shell - use bin/cake DelayedJobs.monitor to run');
    }

    public function requeue()
    {
        $job = TableRegistry::get('DelayedJobs.DelayedJobs')->get($this->args[0]);

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
        $basic_query = $this->DelayedJobs
            ->find()
            ->select([
                'id',
                'status',
                'priority',
                'sequence',
                'run_at'
            ])
            ->where([
                'status in' => [DelayedJobsTable::STATUS_NEW, DelayedJobsTable::STATUS_FAILED],
                'run_at <' => new Time()
            ]);

        $sequences = $basic_query
            ->cleanCopy()
            ->distinct(['sequence'])
            ->andWhere([
                'sequence is not' => null
            ])
            ->order([
                'priority' => 'asc',
                'id' => 'asc'
            ])
            ->all();

        $no_sequences = $basic_query
            ->cleanCopy()
            ->andWhere(['sequence is' => null])
            ->order([
                'priority' => 'asc',
                'id' => 'asc'
            ])
            ->all();
        $all_jobs = $sequences->append($no_sequences);
        foreach ($all_jobs as $job) {
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
            ->addSubcommand('monitor', [
                'help' => 'Moved into own shell - use bin/cake DelayedJobs.monitor to run'
            ])
            ->addSubcommand('startHosts', [
                'help' => 'Starts hosts'
            ])
            ->addSubcommand('stopHosts', [
                'help' => 'Stops hosts'
            ])
            ->addSubcommand('clean', [
                'help' => 'Cleans out jobs that are completed and older than 4 weeks'
            ])
            ->addSubcommand('recuring', [
                'help' => 'Fires the recuring event and creates the initial recuring job instance'
            ])
            ->addSubcommand('reload', [
                'help' => 'Restarts all running worker hosts'
            ])
            ->addSubcommand('revive', [
                'help' => 'Requeues all new or failed jobs that should be in RabbitMQ'
            ])
            ->addSubcommand('requeue', [
                'help '=> 'Receues a job',
                'parser' => [
                    'arguments' => [
                        'id' => [
                            'help' => 'Job id',
                            'required' => true
                        ]
                    ]
                ]
            ])
            ->addOption('workers', [
                    'help' => 'Number of workers each host may run',
                    'default' => 1
                ])
            ->addOption('hosts', [
                    'help' => 'Number of hosts to run',
                    'default' => $this->_autoWorker()
                ]);;

        return $options;
    }

}