<?php

namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use Cake\I18n\Time;
use DelayedJobs\Model\Table\DelayedJobsTable;

class MonitorShell extends Shell
{
    public $modelClass = 'DelayedJobs.Hosts';

    public function main()
    {
        $status_map = [
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
        $start = true;
        while (true) {
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
            $statuses[DelayedJobsTable::STATUS_NEW] = $this->DelayedJobs->find()
                ->where([
                    'status' => DelayedJobsTable::STATUS_NEW,
                    'run_at <=' => new Time()
                ])
                ->count();
            $created_per_second_hour = $this->DelayedJobs->jobsPerSecond();
            $created_per_second_15 = $this->DelayedJobs->jobsPerSecond([], 'created', '-15 minutes');
            $created_per_second_5 = $this->DelayedJobs->jobsPerSecond([], 'created', '-5 minutes');
            $completed_per_second_hour = $this->DelayedJobs->jobsPerSecond([
                'status' => DelayedJobsTable::STATUS_SUCCESS
            ], 'modified');
            $completed_per_second_15 = $this->DelayedJobs->jobsPerSecond([
                'status' => DelayedJobsTable::STATUS_SUCCESS
            ], 'modified', '-15 minutes');
            $completed_per_second_5 = $this->DelayedJobs->jobsPerSecond([
                'status' => DelayedJobsTable::STATUS_SUCCESS
            ], 'modified', '-5 minutes');
            $host_count = $this->Hosts->find()
                ->count();
            if ($start || time() - $time > 5) {
                $start = false;
                $time = time();
                $running_jobs = $this->DelayedJobs->find()
                    ->select(['id', 'group', 'locked_by', 'class', 'method'])
                    ->where([
                        'status' => DelayedJobsTable::STATUS_BUSY
                    ])
                    ->all();
                $last_failed = $this->DelayedJobs->find()
                    ->select(['id', 'last_message', 'failed_at'])
                    ->where([
                        'status' => DelayedJobsTable::STATUS_FAILED
                    ])
                    ->order([
                        'failed_at' => 'DESC'
                    ])
                    ->first();
                $last_buried = $this->DelayedJobs->find()
                    ->select(['id', 'last_message', 'failed_at'])
                    ->where([
                        'status' => DelayedJobsTable::STATUS_BURRIED
                    ])
                    ->order([
                        'failed_at' => 'DESC'
                    ])
                    ->first();
            }

            $this->clear();
            $this->out(__('Delayed Jobs monitor <info>{0}</info>', date('H:i:s')));
            $this->hr();
            $this->out(__('Running hosts: <info>{0}</info>', $host_count));
            $this->out(__('Created / s: <info>{0}</info> <info>{1}</info> <info>{2}</info>', $created_per_second_5,
                $completed_per_second_15, $completed_per_second_hour));
            $this->out(__('Completed /s : <info>{0}</info> <info>{1}</info> <info>{2}</info>', $completed_per_second_5,
                $completed_per_second_15, $completed_per_second_hour));
            $this->hr();

            $this->out('Total current job count');
            $this->out('');
            foreach ($status_map as $status => $name) {
                $this->out(__('{0}: <info>{1}</info>', $name, (isset($statuses[$status]) ? $statuses[$status] : 0)));
            }

            if (count($running_jobs) > 0) {
                $this->hr();
                $this->out(__('Running job snapshot <info>{0} seconds ago</info>:', time() - $time));
                $running_job_text = [];
                foreach ($running_jobs as $running_job) {
                    $this->out(__(" - {0} ({1}) with {2}", $running_job->id, $running_job->group,
                        $running_job->locked_by));
                    $this->out(__("\t{0}::{1}", $running_job->class, $running_job->method));
                }
            }
            $this->hr();
            if ($last_failed) {
                $this->out(__('<info>{0}</info> failed because <info>{1}</info> at <info>{2}</info>', $last_failed->id,
                    $last_failed->last_message, $last_failed->failed_at->i18nFormat()));
            }
            if ($last_buried) {
                $this->out(__('<info>{0}</info> was buried because <info>{1}</info> at <info>{2}</info>',
                    $last_buried->id, $last_buried->last_message, $last_buried->failed_at->i18nFormat()));
            }

            if ($this->param('snapshot')) {
                break;
            }
            usleep(50000);
        }
    }

    public function getOptionParser()
    {
        $options = parent::getOptionParser();

        $options->description('Allows monitoring of the delayed job service')
            ->addOption('snapshot', [
                'help' => 'Generate a single snapshot of the delayed job service',
                'boolean' => true,
                'short' => 's'
            ]);

        return $options;
    }
}
