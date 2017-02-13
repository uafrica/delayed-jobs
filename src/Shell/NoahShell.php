<?php

namespace DelayedJobs\Shell;

use App\Shell\AppShell;
use DelayedJobs\DelayedJob\EnqueueTrait;
use DelayedJobs\Traits\QueueJobTrait;

class NoahShell extends AppShell
{
    use EnqueueTrait;

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
            $this->enqueue(
                'DelayedJobs.Ark',
                [
                    'first' => true,
                    'max_fork' => $max_fork,
                    'work' => $work,
                    'pid' => getmypid()
                ],
                [
                    'group' => 'FloodTest',
                    'priority' => random_int(1, 10) * 10 + 100,
                    'sequence' => 'initial_' . random_int(0, 100)
                ]
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
