<?php

namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\I18n\Time;
use Cake\Network\Http\Client;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\Model\Table\DelayedJobsTable;

/**
 * Class MonitorShell
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class MonitorShell extends Shell
{
    public $modelClass = 'DelayedJobs.Hosts';

    protected function _rates($field, $status = null)
    {
        $available_rates = [
            '30 seconds',
            '5 minutes',
            '15 minutes',
            '1 hour'
        ];

        $conditions = [];
        if ($status) {
            $conditions = [
                'status' => $status
            ];
        }

        $return = [];
        foreach ($available_rates as $available_rate) {
            $return[] = $this->DelayedJobs->jobsPerSecond($conditions, $field, '-' . $available_rate);
        }
        return $return;
    }

    protected function _statusStats()
    {
        $statuses = $this->DelayedJobs->find('list', [
            'keyField' => 'status',
            'valueField' => 'counter'
        ])
            ->select([
                'status',
                'counter' => $this->DelayedJobs->find()
                    ->func()
                    ->count('id')
            ])
            ->where([
                'not' => ['status' => DelayedJobsTable::STATUS_NEW]
            ])
            ->group(['status'])
            ->toArray();
        $statuses['waiting'] = $this->DelayedJobs->find()
            ->where([
                'status' => DelayedJobsTable::STATUS_NEW,
                'run_at >' => new Time()
            ])
            ->count();
        $statuses[DelayedJobsTable::STATUS_NEW] = $this->DelayedJobs->find()
            ->where([
                'status' => DelayedJobsTable::STATUS_NEW,
                'run_at <=' => new Time()
            ])
            ->count();
        return $statuses;
    }

    public function main()
    {
        $status_map = [
            'waiting' => 'Waiting',
            DelayedJobsTable::STATUS_NEW => 'New',
            DelayedJobsTable::STATUS_BUSY => 'Busy',
            DelayedJobsTable::STATUS_BURRIED => 'Buried',
            DelayedJobsTable::STATUS_SUCCESS => 'Success',
            DelayedJobsTable::STATUS_KICK => 'Kicked',
            DelayedJobsTable::STATUS_FAILED => 'Failed',
            DelayedJobsTable::STATUS_UNKNOWN => 'Unknown',
        ];
        $this->loadModel('DelayedJobs.DelayedJobs');
        $hostname = php_uname('n');

        $time = time();
        $rabbit_time = microtime(true);
        $start = true;

        while (true) {
            $statuses = $this->_statusStats();
            $created_rate = $this->_rates('created');
            $completed_rate = $this->_rates('end_time', DelayedJobsTable::STATUS_SUCCESS);
            $host_count = $this->Hosts->find()
                ->count();
            $worker_count = $this->Hosts->find()
                ->select([
                    'worker_count' => $this->Hosts->find()->func()->sum('worker_count')
                ])
                ->hydrate(false)
                ->first();

            if ($start || microtime(true) - $rabbit_time > 0.5) {
                $rabbit_status = AmqpManager::queueStatus();
                $rabbit_time = microtime(true);
            }

            if ($this->param('hide-jobs') === false && ($start || time() - $time > 5)) {
                $start = false;
                $time = time();
                $running_jobs = $this->DelayedJobs->find()
                    ->select([
                        'id', 'group', 'locked_by', 'class', 'method'
                    ])
                    ->where([
                        'status' => DelayedJobsTable::STATUS_BUSY
                    ])
                    ->all();
                $last_failed = $this->DelayedJobs->find()
                    ->select(['id', 'last_message', 'failed_at', 'class', 'method'])
                    ->where([
                        'status' => DelayedJobsTable::STATUS_FAILED
                    ])
                    ->order([
                        'failed_at' => 'DESC'
                    ])
                    ->first();
                $last_buried = $this->DelayedJobs->find()
                    ->select(['id', 'last_message', 'failed_at', 'class', 'method'])
                    ->where([
                        'status' => DelayedJobsTable::STATUS_BURRIED
                    ])
                    ->order([
                        'failed_at' => 'DESC'
                    ])
                    ->first();
                $time_diff = $this->DelayedJobs->find()->func()->timeDiff([
                    'end_time' => 'literal',
                    'start_time' => 'literal'
                ]);
                $longest_running = $this->DelayedJobs->find()
                    ->select(['id', 'group', 'class', 'method', 'diff' => $time_diff])
                    ->where([
                        'status' => DelayedJobsTable::STATUS_SUCCESS
                    ])
                    ->orderDesc($time_diff)
                    ->first();
            }

            $this->clear();
            $this->out(__('Delayed Jobs monitor <info>{0}</info>', date('H:i:s')));
            $this->hr();
            $this->out(__('Running hosts: <info>{0}</info>', $host_count));
            $this->out(__('Workers: <info>{0}</info>', $worker_count['worker_count'] ?: 0));
            $this->out(__('Created / s: <info>{0}</info>', implode(' ', $created_rate)));
            $this->out(__('Completed /s : <info>{0}</info>', implode(' ', $completed_rate)));
            $this->hr();

            $this->out('Total current job count');
            $this->out('');
            foreach ($status_map as $status => $name) {
                $this->out(__('{0}: <info>{1}</info>', $name, (isset($statuses[$status]) ? $statuses[$status] : 0)));
            }

            if ($rabbit_status) {
                $this->hr();
                $this->out('Rabbit stats');
                $this->nl();
                $this->out(__('Ready: <info>{0}</info>', $rabbit_status['messages_ready']));
                $this->out(__('Unacked: <info>{0}</info>', $rabbit_status['messages_unacknowledged']));
            }

            if ($this->param('hide-jobs') === false && count($running_jobs) > 0) {
                $this->hr();
                $this->out(__('Running job snapshot <info>{0} seconds ago</info>:', time() - $time));
                $running_job_text = [];
                foreach ($running_jobs as $running_job) {
                    $this->out(__(" - {0} ({1}) with {2}", $running_job->id, $running_job->group, $running_job->locked_by));
                    $this->out(__("\t{0}::{1}", $running_job->class, $running_job->method));
                }
            }
            $this->hr();
            if ($last_failed) {
                $this->out(__('<info>{0}</info> <comment>{1}::{2}()</comment> failed because <info>{3}</info> at <info>{4}</info>', $last_failed->id, $last_failed->class, $last_failed->method,
                    $last_failed->last_message, $last_failed->failed_at->i18nFormat()));
            }
            if ($last_buried) {
                $this->out(__('<info>{0}</info> <comment>{1}::{2}()</comment> was buried because <info>{3}</info> at <info>{4}</info>',
                    $last_buried->id, $last_buried->class, $last_buried->method, $last_buried->last_message, $last_buried->failed_at->i18nFormat()));
            }
            if ($longest_running) {
                $this->out(__('<info>{0}</info> <comment>{1}::{2}()</comment> is the longest running job at <info>{3}</info>', $longest_running->id,
                    $longest_running->class, $longest_running->method, $longest_running->diff));
            }
            if ($this->param('snapshot')) {
                break;
            }
            usleep(100000);
        }
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options
            ->description('Allows monitoring of the delayed job service')
            ->addOption('snapshot', [
                'help' => 'Generate a single snapshot of the delayed job service',
                'boolean' => true,
                'short' => 's'
            ])
            ->addOption('hide-jobs', [
                'help' => 'Hide active jobs',
                'boolean' => true,
                'short' => 'j'
            ]);

        return $options;
    }
}