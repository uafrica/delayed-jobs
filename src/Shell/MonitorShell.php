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

    public $loop_counter;

    protected function _basicStats()
    {
        static $peak_created_rate = 0.0;
        static $peak_completed_rate = 0.0;

        $statuses = $this->DelayedJobs->statusStats();
        $created_rate = $this->DelayedJobs->jobRates('created');
        $completed_rate = $this->DelayedJobs->jobRates('end_time', DelayedJobsTable::STATUS_SUCCESS);
        $peak_created_rate = $created_rate[0] > $peak_created_rate ? $created_rate[0] : $peak_created_rate;
        $peak_completed_rate = $completed_rate[0] > $peak_completed_rate ? $completed_rate[0] : $peak_completed_rate;

        $this->out("Created / s:\t" .
            sprintf('<info>%6.2f</info> <info>%6.2f</info> <info>%6.2f</info> :: PEAK <info>%6.2f</info>', $created_rate[0], $created_rate[1],
                $created_rate[2], $peak_created_rate));
        $this->out("Completed / s:\t" .
            sprintf('<info>%6.2f</info> <info>%6.2f</info> <info>%6.2f</info> :: PEAK <info>%6.2f</info>', $completed_rate[0],
                $completed_rate[1], $completed_rate[2], $peak_completed_rate));
        $this->out('');

        $data = [
            0 => array_values(self::STATUS_MAP),
            1 => []
        ];

        foreach (self::STATUS_MAP as $status => $name) {
            $data[1][] = str_pad(isset($statuses[$status]) ? $statuses[$status] : 0, 8, ' ', STR_PAD_LEFT);
        }

        $this->helper('table')->output($data);
    }

    protected function _basicStatsWithCharts()
    {
        static $created_points = [];
        static $completed_points = [];
        static $status_points = [];
        static $peak_created_rate = 0.0;
        static $peak_completed_rate = 0.0;

        $max_length = 50;

        $statuses = $this->DelayedJobs->statusStats();
        $created_rate = $this->DelayedJobs->jobRates('created');
        $completed_rate = $this->DelayedJobs->jobRates('end_time', DelayedJobsTable::STATUS_SUCCESS);
        $peak_created_rate = $created_rate[0] > $peak_created_rate ? $created_rate[0] : $peak_created_rate;
        $peak_completed_rate = $completed_rate[0] > $peak_completed_rate ? $completed_rate[0] : $peak_completed_rate;

        if (empty($created_points) || $this->loop_counter % 4 === 0) {
            $created_points[] = $created_rate[0];
            $completed_points[] = $completed_rate[0];

            foreach (self::STATUS_MAP as $status => $name) {
                if (empty($status_points[$status])) {
                    $status_points[$status] = [];
                }
                $status_points[$status][] = isset($statuses[$status]) ? $statuses[$status] : 0;
            }
        }

        if (count($created_points) > $max_length) {
            array_splice($created_points, -$max_length);
            array_splice($completed_points, -$max_length);
            foreach (self::STATUS_MAP as $status => $name) {
                if (empty($status_points[$status])) {
                    continue;
                }
                array_splice($status_points[$status], -$max_length);
            }
        }

        $worker_count = $this->Workers->find()
            ->count();

        $this->out(__('Running workers: <info>{0}</info>', $worker_count));

        $this->helper('DelayedJobs.sparkline')
            ->output([
                'data' => $created_points,
                'title' => 'Created / s',
                'length' => $max_length
            ]);
        $this->out("\t\t" .
            sprintf('<info>%6.2f</info> <info>%6.2f</info> <info>%6.2f</info> :: PEAK <info>%6.2f</info>',
                $created_rate[0], $created_rate[1], $created_rate[2], $peak_created_rate));

        $this->helper('DelayedJobs.sparkline')
            ->output([
                'data' => $completed_points,
                'title' => 'Completed / s',
                'length' => $max_length
            ]);

        $this->out("\t\t" .
            sprintf('<info>%6.2f</info> <info>%6.2f</info> <info>%6.2f</info> :: PEAK <info>%6.2f</info>',
                $completed_rate[0], $completed_rate[1], $completed_rate[2], $peak_completed_rate));

        foreach (self::STATUS_MAP as $status => $name) {
            $this->helper('DelayedJobs.sparkline')
                ->output([
                    'data' => $status_points[$status],
                    'title' => $name . "\t",
                    'length' => $max_length,
                    'formatter' => '%7d'
                ]);
        }
    }

    protected function _rabbitStats()
    {
        $rabbit_status = AmqpManager::queueStatus();
        if (empty($rabbit_status)) {
            return;
        }

        $this->out('Rabbit stats');
        $this->nl();
        $this->helper('table')
            ->output([
                ['Ready', 'Unacked'],
                [$rabbit_status['messages_ready'], $rabbit_status['messages_unacknowledged']]
            ]);
    }

    protected function _rabbitStatsWithCharts()
    {
        static $ready_points = [];
        static $unacked_points = [];

        $max_length = 50;

        $rabbit_status = AmqpManager::queueStatus();
        if (empty($rabbit_status)) {
            return;
        }

        if (empty($ready_points) || $this->loop_counter % 4 === 0) {
            $ready_points[] = $rabbit_status['messages_ready'];
            $unacked_points[] = $rabbit_status['messages_unacknowledged'];
        }

        if (count($ready_points) > $max_length) {
            array_splice($ready_points, -$max_length);
            array_splice($unacked_points, -$max_length);
        }

        $this->helper('DelayedJobs.sparkline')
            ->output([
                'data' => $ready_points,
                'title' => "MQ Ready",
                'length' => $max_length
            ]);

        $this->helper('DelayedJobs.sparkline')
            ->output([
                'data' => $unacked_points,
                'title' => 'MQ Unacked',
                'length' => $max_length
            ]);
    }

    protected function _historicJobs()
    {
        $last_completed = $this->DelayedJobs->find()
            ->select(['id', 'last_message', 'end_time', 'class', 'method', 'start_time', 'end_time'])
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
            $this->out(__('Last completed: <info>{0}</info> (<comment>{1}::{2}</comment>) @ <info>{3}</info> :: <info>{4}</info> seconds',
                $last_completed->id, $last_completed->class, $last_completed->method,
                $last_completed->end_time->i18nFormat(), $last_completed->end_time->diffInSeconds($last_completed->start_time)));
        }
        if (!empty($last_failed)) {
            $this->out(__('Last failed: <info>{0}</info> (<comment>{1}::{2}</comment>) :: <info>{3}</info> @ <info>{4}</info>',
                $last_failed->id, $last_failed->class, $last_failed->method, $last_failed->last_message,
                $last_failed->failed_at->i18nFormat()));
        }
        if (!empty($last_buried)) {
            $this->out(__('Last burried: <info>{0}</info> (<comment>{1}::{2}</comment>) :: <info>{3}</info> @ <info>{4}</info>>',
                $last_buried->id, $last_buried->class, $last_buried->method, $last_buried->last_message,
                $last_buried->failed_at->i18nFormat()));
        }
        if (!empty($longest_running)) {
            $this->out(__('Longest run: <info>{0}</info> (<comment>{1}::{2}</comment>) @ <info>{4}</info> :: <info>{3}</info>',
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
                'method',
                'start_time'
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
        $data = [
            ['Id', 'Host', 'Method', 'Run time']
        ];
        foreach ($running_jobs as $running_job) {
            $row = [
                $running_job->id,
                $running_job->host_name,
                $running_job->class . '::' . $running_job->method,
                $running_job->start_time->diffInSeconds()
            ];
            $data[] = $row;
        }
        $this->helper('table')->output($data);
    }

    public function main()
    {
        $this->loadModel('DelayedJobs.DelayedJobs');

        $this->start_time = time();
        $this->clear();
        while (true) {
            if ($this->param('basic-stats')) {
                $this->out("\e[0;0H");
            } else {
                $this->clear();
            }
            $this->out(__('Delayed Jobs monitor - <info>{0}</info>', date('H:i:s')));
            $this->hr();

            if ($this->param('basic-stats')) {
                $this->_basicStatsWithCharts();
                $this->_rabbitStatsWithCharts();
                $this->_historicJobs();
            } else {
                $this->_basicStats();
                $this->_rabbitStats();
                $this->_historicJobs();

                if ($this->param('hide-jobs') === false) {
                    $this->_activeJobs();
                }
            }

            if ($this->param('snapshot')) {
                break;
            }
            usleep(250000);

            $this->loop_counter++;
            if ($this->loop_counter > 1000) {
                $this->loop_counter = 0;
            }
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
            ->addOption('basic-stats', [
                'help' => 'Show basic information with sparklines',
                'boolean' => true,
                'default' => false,
                'short' => 'b'
            ])
            ->addOption('hide-jobs', [
                'help' => 'Hide active jobs',
                'boolean' => true,
                'default' => false,
                'short' => 'j'
            ]);

        return $options;
    }
}
