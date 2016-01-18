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
    const STATUS_MAP = [
        'waiting' => 'Waiting',
        DelayedJobsTable::STATUS_NEW => 'New',
        DelayedJobsTable::STATUS_BUSY => 'Busy',
        DelayedJobsTable::STATUS_BURRIED => 'Buried',
        DelayedJobsTable::STATUS_SUCCESS => 'Success',
        DelayedJobsTable::STATUS_KICK => 'Kicked',
        DelayedJobsTable::STATUS_FAILED => 'Failed',
        DelayedJobsTable::STATUS_UNKNOWN => 'Unknown',
    ];

    public $modelClass = 'DelayedJobs.Workers';

    public $peak_created_rate = 0;
    public $peak_completed_rate = 0;

    protected function _basicStats()
    {
        $statuses = $this->DelayedJobs->statusStats();
        $created_rate = $this->DelayedJobs->jobRates('created');
        $completed_rate = $this->DelayedJobs->jobRates('end_time', DelayedJobsTable::STATUS_SUCCESS);
        $this->peak_created_rate = $created_rate[0] > $this->peak_created_rate ? $created_rate[0] :
            $this->peak_created_rate;
        $this->peak_completed_rate = $completed_rate[0] > $this->peak_completed_rate ? $completed_rate[0] :
            $this->peak_completed_rate;

        $worker_count = $this->Workers->find()
            ->count();

        $this->out(__('Running workers: <info>{0}</info>', $worker_count));
        $this->out(__('Created / s: <info>{0}</info> :: Peak <info>{1}</info>', implode(' ', $created_rate),
            $this->peak_created_rate));
        $this->out(__('Completed /s : <info>{0}</info> :: Peak <info>{1}</info>', implode(' ', $completed_rate),
            $this->peak_completed_rate));
        $this->hr();

        $this->out('Total current job count');
        $this->out('');
        foreach (self::STATUS_MAP as $status => $name) {
            $this->out(__('{0}: <info>{1}</info>', $name, (isset($statuses[$status]) ? $statuses[$status] : 0)));
        }
    }

    protected function _rabbitStats()
    {
        $rabbit_status = AmqpManager::queueStatus();
        $this->hr();
        $this->out('Rabbit stats');
        $this->nl();
        $this->out(__('Ready: <info>{0}</info>', $rabbit_status['messages_ready']));
        $this->out(__('Unacked: <info>{0}</info>', $rabbit_status['messages_unacknowledged']));
    }

    protected function _historicJobs()
    {
        $last_completed = $this->DelayedJobs->find()
            ->select(['id', 'last_message', 'end_time', 'class', 'method'])
            ->where([
                'status' => DelayedJobsTable::STATUS_SUCCESS
            ])
            ->order([
                'end_time' => 'DESC'
            ])
            ->first();
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
        $time_diff = $this->DelayedJobs->find()
            ->func()
            ->timeDiff([
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

        $this->hr();
        $this->out('Historic jobs');
        $this->nl();
        if (!empty($last_completed)) {
            $this->out(__('<info>{0}</info> <comment>{1}::{2}()</comment> completed at <info>{3}</info>',
                $last_completed->id, $last_completed->class, $last_completed->method,
                $last_completed->end_time->i18nFormat()));
        }
        if (!empty($last_failed)) {
            $this->out(__('<info>{0}</info> <comment>{1}::{2}()</comment> failed because <info>{3}</info> at <info>{4}</info>',
                $last_failed->id, $last_failed->class, $last_failed->method, $last_failed->last_message,
                $last_failed->failed_at->i18nFormat()));
        }
        if (!empty($last_buried)) {
            $this->out(__('<info>{0}</info> <comment>{1}::{2}()</comment> was buried because <info>{3}</info> at <info>{4}</info>',
                $last_buried->id, $last_buried->class, $last_buried->method, $last_buried->last_message,
                $last_buried->failed_at->i18nFormat()));
        }
        if (!empty($longest_running)) {
            $this->out(__('<info>{0}</info> <comment>{1}::{2}()</comment> is the longest running job. It took <info>{3}</info> seconds and finished at <info>{4}</info>',
                $longest_running->id, $longest_running->class, $longest_running->method, $longest_running->diff,
                $last_completed->end_time->i18nFormat()));
        }
    }

    protected function _activeJobs()
    {
        $running_jobs = $this->DelayedJobs->find()
            ->select([
                'id',
                'group',
                'host_name',
                'class',
                'method'
            ])
            ->where([
                'status' => DelayedJobsTable::STATUS_BUSY
            ])
            ->order([
                'start_time' => 'ASC'
            ])
            ->all();
        $this->hr();
        $this->out('Running jobs');
        $running_job_text = [];
        foreach ($running_jobs as $running_job) {
            $this->out(__(" - <info>{0}</info> @ <info>{2}</info>", $running_job->id, $running_job->group, $running_job->host_name));
            $this->out(__("\t<info>{0}::{1}</info>\tRun time: <info>{2}</info>", $running_job->class, $running_job->method, $running_job->start_time->diffInSeconds()));
        }
    }

    public function main()
    {
        $this->loadModel('DelayedJobs.DelayedJobs');

        $this->_io->styles('bold', ['bold' => true]);

        while (true) {
            $this->clear();
            $this->out(__('Delayed Jobs monitor - <info>{0}</info>', date('H:i:s')));
            $this->hr();

            $this->_basicStats();
            $this->_rabbitStats();
            $this->_historicJobs();

            if ($this->param('hide-jobs') === false) {
                $this->_activeJobs();
            }

            if ($this->param('snapshot')) {
                break;
            }
            sleep(1);
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
