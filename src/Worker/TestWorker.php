<?php

namespace DelayedJobs\Worker;

use Cake\Console\Shell;
use Cake\I18n\Time;
use DelayedJobs\DelayedJob\Job;

/**
 * Class TestWorker
 */
class TestWorker implements JobWorkerInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job that is being run.
     * @param \Cake\Console\Shell|null $shell An instance of the shell that the job is run in
     * @return bool
     */
    public function __invoke(Job $job, Shell $shell = null)
    {
        sleep(2);
        $time = (new Time())->i18nFormat();
        if ($job->getPayload()['type'] === 'success') {
            return 'Successful test at ' . $time;
        } else {
            throw new \Exception('Failing test at ' . $time . ' because ' . $job->getPayload()['type']);
        }
    }
}
