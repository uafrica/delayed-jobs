<?php
declare(strict_types=1);

namespace DelayedJobs\Panel;

use Cake\Core\Configure;
use DebugKit\DebugPanel;
use DelayedJobs\DelayedJob\DebugKitJobManager;
use DelayedJobs\DelayedJob\JobManager;

/**
 * Class JobPanel
 */
class JobsPanel extends DebugPanel
{
    public $plugin = 'DelayedJobs';

    /**
     * The list of jobs produced during the request
     *
     * @var \ArrayObject
     */
    protected $jobLog;

    /**
     * Initialize hook - configures the job manager.
     *
     * @return void
     */
    public function initialize()
    {
        $log = $this->jobLog = new \ArrayObject();
        $jobManager = new DebugKitJobManager(Configure::read('DelayedJobs') + [
            'debugKitLog' => $log,
        ]);
        JobManager::setInstance($jobManager);
    }

    /**
     * Get the data this panel wants to store.
     *
     * @return array
     */
    public function data()
    {
        return [
            'jobs' => isset($this->jobLog) ? $this->jobLog->getArrayCopy() : [],
        ];
    }

    /**
     * Get summary data from the queries run.
     *
     * @return string
     */
    public function summary()
    {
        if (empty($this->jobLog)) {
            return '';
        }

        return (string)count($this->jobLog);
    }
}
