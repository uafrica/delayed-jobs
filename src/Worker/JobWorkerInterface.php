<?php

namespace DelayedJobs\Worker;

use Cake\Console\Shell;
use DelayedJobs\DelayedJobs\Job;

/**
 * Interface for delayed job workers
 */
interface JobWorkerInterface
{
    /**
     * @param \DelayedJobs\DelayedJobs\Job $job The job that is being run.
     * @param \Cake\Console\Shell|null $shell An instance of the shell that the job is run in
     * @return bool
     */
    public function __invoke(Job $job, Shell $shell = null);
}
