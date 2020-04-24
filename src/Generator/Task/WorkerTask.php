<?php
declare(strict_types=1);

namespace DelayedJobs\Generator\Task;

use DelayedJobs\DelayedJob\Job;
use DelayedJobs\WorkerFinder;
use IdeHelper\Generator\Directive\Override;
use IdeHelper\Generator\Task\TaskInterface;

/**
 * Class WorkerTask
 */
class WorkerTask implements TaskInterface
{
    public const CLASS_JOB = Job::class;

    /**
     * @return array
     */
    public function collect(): array
    {
        $map = [];
        $workers = $this->collectWorkers();
        $map = [];
        foreach ($workers as $worker) {
            $map[$worker] = '\\' . static::CLASS_JOB . '::class';
        }

        $directive = new Override('\\' . static::CLASS_JOB . '::enqueue(0)', $map);
        $result[$directive->key()] = $directive;

        return $result;
    }

    /**
     * @return string[]
     */
    protected function collectWorkers(): array
    {
        $result = [];
        $workerFinder = new WorkerFinder();
        $workers = $workerFinder->allAppAndPluginWorkers();
        sort($workers);

        return $workers;
    }
}
