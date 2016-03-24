<?php

namespace DelayedJobs\Worker;

use Cake\Console\Shell;
use DelayedJobs\DelayedJob\DelayedJob;

/**
 * Interface for delayed job workers
 */
interface JobWorkerInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\DelayedJob $job The job that is being run.
     * @param \Cake\Console\Shell|null $shell An instance of the shell that the job is run in
     * @return null|bool|\Cake\I18n\Time|string
     */
    public function __invoke(DelayedJob $job, Shell $shell = null);
}
