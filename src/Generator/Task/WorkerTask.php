<?php

namespace DelayedJobs\Generator\Task;

use Cake\Core\App;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\WorkerFinder;
use IdeHelper\Generator\Task\TaskInterface;

/**
 * Class WorkerTask
 */
class WorkerTask implements TaskInterface
{
    const CLASS_JOB = Job::class;

    /**
     * @return array
     */
    public function collect()
    {
        $map = [];
        $workers = $this->collectWorkers();
        $map = [];
        foreach ($workers as $worker) {
            $map[$worker] = '\\' . static::CLASS_JOB . '::class';
        }

        $result['\\' . static::CLASS_JOB . '::enqueue(0)'] = $map;

        return $result;
    }

    /**
     * @return string[]
     */
    protected function collectWorkers()
    {
        $result = [];
        $workerFinder = new WorkerFinder();
        $workers = $workerFinder->allAppAndPluginWorkers();
        sort($workers);

        return $workers;
    }
}
