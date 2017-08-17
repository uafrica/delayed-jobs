<?php

namespace DelayedJobs\Shell;

use App\Shell\AppShell;
use Cake\Console\ConsoleOptionParser;
use Cake\I18n\Time;
use DelayedJobs\Broker\PhpAmqpLibBroker;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\JobManager;

/**
 * Class MonitorShell
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class MonitorShell extends AppShell
{
    const STATUS_MAP = [
        'waiting' => 'Waiting',
        Job::STATUS_NEW => 'New',
        Job::STATUS_BUSY => 'Busy',
        Job::STATUS_BURIED => 'Buried',
        Job::STATUS_SUCCESS => 'Success',
        Job::STATUS_KICK => 'Kicked',
        Job::STATUS_FAILED => 'Failed',
        Job::STATUS_UNKNOWN => 'Unknown',
    ];

    public $modelClass = 'DelayedJobs.Workers';

    public $loop_counter;

    /**
     * @return array
     */
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
                'not' => ['status' => Job::STATUS_NEW]
            ])
            ->group(['status'])
            ->toArray();
        $statuses['waiting'] = $this->DelayedJobs->find()
            ->where([
                'status' => Job::STATUS_NEW,
                'run_at >' => new Time()
            ])
            ->count();
        $statuses[Job::STATUS_NEW] = $this->DelayedJobs->find()
            ->where([
                'status' => Job::STATUS_NEW,
                'run_at <=' => new Time()
            ])
            ->count();

        return $statuses;
    }

    /**
     * @param $field
     * @param null $status
     * @return array
     */
    protected function _jobRates($field, $status = null)
    {
        $available_rates = [
            '30 seconds',
            '5 minutes',
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
            $return[] = $this->_jobsPerSecond($conditions, $field, '-' . $available_rate);
        }

        return $return;
    }

    /**
     * @param $host_id
     * @return \Cake\ORM\Query
     */
    protected function _getRunningByHost($host_id)
    {
        $conditions = [
            'DelayedJobs.host_name' => $host_id,
            'DelayedJobs.status' => Job::STATUS_BUSY,
        ];
        $jobs = $this->DelayedJobs->find()
            ->select([
                'id',
                'pid',
                'host_name',
                'status',
                'priority',
            ])
            ->where($conditions)
            ->order([
                'DelayedJobs.id' => 'ASC'
            ]);

        return $jobs;
    }

    protected function _getRunning()
    {
        $conditions = [
            'DelayedJobs.status' => Job::STATUS_BUSY,
        ];
        $jobs = $this->DelayedJobs->find()
            ->select([
                'id',
                'pid',
                'status',
                'sequence',
                'priority',
            ])
            ->where($conditions)
            ->order([
                'DelayedJobs.id' => 'ASC'
            ]);

        return $jobs;
    }

    protected function _jobsPerSecond($conditions = [], $field = 'created', $time_range = '-1 hour')
    {
        $start_time = new Time($time_range);
        $current_time = new Time();
        $second_count = $current_time->diffInSeconds($start_time);
        $conditions[$this->DelayedJobs->aliasField($field) . ' > '] = $start_time;
        $count = $this->DelayedJobs->find()
            ->where($conditions)
            ->count();

        return $count / $second_count;
    }

    protected function _basicStats()
    {
        static $peak_created_rate = 0.0;
        static $peak_completed_rate = 0.0;

        $statuses = $this->_statusStats();
        $created_rate = $this->_jobRates('created');
        $completed_rate = $this->_jobRates('end_time', Job::STATUS_SUCCESS);
        $peak_created_rate = $created_rate[0] > $peak_created_rate ? $created_rate[0] : $peak_created_rate;
        $peak_completed_rate = $completed_rate[0] > $peak_completed_rate ? $completed_rate[0] : $peak_completed_rate;

        $this->out("Created / s:\t" .
            sprintf(
                '<info>%6.2f</info> <info>%6.2f</info> <info>%6.2f</info> :: PEAK <info>%6.2f</info>',
                $created_rate[0],
                $created_rate[1],
                $created_rate[2],
                $peak_created_rate
            ));
        $this->out("Completed / s:\t" .
            sprintf(
                '<info>%6.2f</info> <info>%6.2f</info> <info>%6.2f</info> :: PEAK <info>%6.2f</info>',
                $completed_rate[0],
                $completed_rate[1],
                $completed_rate[2],
                $peak_completed_rate
            ));
        $this->out('');

        $data = [
            0 => array_values(self::STATUS_MAP),
            1 => []
        ];

        foreach (self::STATUS_MAP as $status => $name) {
            $data[1][] = str_pad($statuses[$status] ?? 0, 8, ' ', STR_PAD_LEFT);
        }

        $this->helper('Table')->output($data);
    }

    protected function _basicStatsWithCharts()
    {
        static $created_points = [];
        static $completed_points = [];
        static $status_points = [];
        static $peak_created_rate = 0.0;
        static $peak_completed_rate = 0.0;

        $max_length = 50;

        $statuses = $this->_statusStats();
        $created_rate = $this->_jobRates('created');
        $completed_rate = $this->_jobRates('end_time', Job::STATUS_SUCCESS);
        $peak_created_rate = $created_rate[0] > $peak_created_rate ? $created_rate[0] : $peak_created_rate;
        $peak_completed_rate = $completed_rate[0] > $peak_completed_rate ? $completed_rate[0] : $peak_completed_rate;

        if (empty($created_points) || $this->loop_counter % 4 === 0) {
            $created_points[] = $created_rate[0];
            $completed_points[] = $completed_rate[0];

            foreach (self::STATUS_MAP as $status => $name) {
                if (empty($status_points[$status])) {
                    $status_points[$status] = [];
                }
                $status_points[$status][] = $statuses[$status] ?? 0;
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

        $this->out(sprintf('Running workers: <info>%3d</info>', $worker_count));

        $this->helper('DelayedJobs.Sparkline')
            ->output([
                'data' => $created_points,
                'title' => 'Created / s',
                'length' => $max_length
            ]);
        $this->out("\t\t" .
            sprintf(
                '<info>%6.2f</info> <info>%6.2f</info> <info>%6.2f</info> :: PEAK <info>%6.2f</info>',
                $created_rate[0],
                $created_rate[1],
                $created_rate[2],
                $peak_created_rate
            ));

        $this->helper('DelayedJobs.Sparkline')
            ->output([
                'data' => $completed_points,
                'title' => 'Completed / s',
                'length' => $max_length
            ]);

        $this->out("\t\t" .
            sprintf(
                '<info>%6.2f</info> <info>%6.2f</info> <info>%6.2f</info> :: PEAK <info>%6.2f</info>',
                $completed_rate[0],
                $completed_rate[1],
                $completed_rate[2],
                $peak_completed_rate
            ));

        foreach (self::STATUS_MAP as $status => $name) {
            $this->helper('DelayedJobs.Sparkline')
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
        $rabbit_status = JobManager::instance()
            ->getMessageBroker()
            ->queueStatus();
        if (empty($rabbit_status)) {
            return;
        }

        $this->out('Rabbit stats');
        $this->nl();
        $this->helper('Table')
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

        $rabbit_status = JobManager::instance()
            ->getMessageBroker()
            ->queueStatus();
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

        $this->helper('DelayedJobs.Sparkline')
            ->output([
                'data' => $ready_points,
                'title' => 'MQ Ready',
                'length' => $max_length,
                'formatter' => '%7d'
            ]);

        $this->helper('DelayedJobs.Sparkline')
            ->output([
                'data' => $unacked_points,
                'title' => 'MQ Unacked',
                'length' => $max_length,
                'formatter' => '%7d'
            ]);
    }

    protected function _historicJobs()
    {
        $last_completed = $this->DelayedJobs->find()
            ->select(['id', 'last_message', 'end_time', 'worker',  'end_time', 'duration'])
            ->where([
                'status' => Job::STATUS_SUCCESS
            ])
            ->order([
                'end_time' => 'DESC'
            ])
            ->first();
        $last_failed = $this->DelayedJobs->find()
            ->select(['id', 'last_message', 'failed_at', 'worker'])
            ->where([
                'status' => Job::STATUS_FAILED
            ])
            ->order([
                'failed_at' => 'DESC'
            ])
            ->first();
        $last_buried = $this->DelayedJobs->find()
            ->select(['id', 'last_message', 'failed_at', 'worker'])
            ->where([
                'status' => Job::STATUS_BURIED
            ])
            ->order([
                'failed_at' => 'DESC'
            ])
            ->first();

        $output = [];
        if (!empty($last_completed)) {
            $output[] = __(
                'Last completed: <info>{0}</info> (<comment>{1}</comment>) @ <info>{2}</info> :: <info>{3}</info> seconds',
                $last_completed->id,
                $last_completed->worker,
                $last_completed->end_time ? $last_completed->end_time->i18nFormat() : '',
                round($last_completed->duration / 1000, 2)
            );
        }
        if (!empty($last_failed)) {
            $output[] = __(
                'Last failed: <info>{0}</info> (<comment>{1}</comment>) :: <info>{2}</info> @ <info>{3}</info>',
                $last_failed->id,
                $last_failed->worker,
                $last_failed->last_message,
                $last_failed->failed_at ? $last_failed->failed_at->i18nFormat() : ''
            );
        }
        if (!empty($last_buried)) {
            $output[] = __(
                'Last burried: <info>{0}</info> (<comment>{1}</comment>) :: <info>{2}</info> @ <info>{3}</info>>',
                $last_buried->id,
                $last_buried->worker,
                $last_buried->last_message,
                $last_buried->failed_at ? $last_buried->failed_at->i18nFormat() : ''
            );
        }
        if (empty($output)) {
            return;
        }
        $this->hr();
        $this->out('Historic jobs');
        $this->nl();

        $max_length = max(array_map(function ($item) {
            return strlen($item);
        }, $output));
        array_pad($output, 5, '');
        foreach ($output as $item) {
            $this->out(str_pad($item, $max_length, ' '));
        }
    }

    protected function _activeJobs()
    {
        $running_jobs = $this->DelayedJobs->find()
            ->select([
                'id',
                'group',
                'host_name',
                'worker',
                'start_time'
            ])
            ->where([
                'status' => Job::STATUS_BUSY
            ])
            ->order([
                'start_time' => 'ASC'
            ])
            ->all();
        $this->hr();
        $this->out('Running jobs');
        $data = [
            ['Id', 'Host',  'Run time']
        ];
        foreach ($running_jobs as $running_job) {
            $row = [
                $running_job->id,
                $running_job->host_name,
                $running_job->worker,
                $running_job->start_time->diffInSeconds()
            ];
            $data[] = $row;
        }
        $this->helper('Table')->output($data);
    }

    public function main()
    {
        $this->loadModel('DelayedJobs.DelayedJobs');

        $this->start_time = time();
        $this->clear();
        while (true) {
            if ($this->loop_counter % 100 !== 0 && $this->param('basic-stats')) {
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

    /**
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser(): ConsoleOptionParser
    {
        $options = parent::getOptionParser();

        $options
            ->setDescription('Allows monitoring of the delayed job service')
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
