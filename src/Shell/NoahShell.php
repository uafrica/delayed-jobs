<?php

namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use DelayedJobs\Model\Table\DelayedJobsTable;
use DelayedJobs\Traits\QueueJobTrait;

class NoahShell extends Shell
{
    use QueueJobTrait;

    public $modelClass = 'DelayedJobs.DelayedJobs';

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

        $initial = (int)$this->in('Number of initial jobs?', null, 1);
        $max_fork = (int)$this->in('Maximum number of forks per job?', null, 2);
        $work = (int)$this->in('Maximum number of pi digits to calculate?', null, 5);

        for ($i = 0;$i < $initial; $i++) {
            $this->_queueJob(
                'FloodTest',
                'DelayedJobs\Worker\ArkWorker',
                'flood',
                [
                    'first' => true,
                    'max_fork' => $max_fork,
                    'work' => $work,
                    'pid' => getmypid()
                ],
                20
            );
        }

        $this->DelayedJobs->connection()
            ->driver()
            ->autoQuoting(true);
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
                    'group' => 'FloodTest',
                ])
                ->group(['status'])
                ->toArray();

            $this->clear();
            $this->out('Current job count:');
            foreach ($status_map as $status => $name) {
                $this->out(__('{0}: <info>{1}</info>', $name, (isset($statuses[$status]) ? $statuses[$status] : 0)));
            }
            $this->out('Quit to stop the flood');
            sleep(1);
        }
    }
}