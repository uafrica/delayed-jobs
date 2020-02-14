<?php
declare(strict_types=1);

namespace DelayedJobs\Worker;

use Cake\Console\Shell;
use Cake\I18n\Time;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\Result\Failed;
use DelayedJobs\Result\Success;

/**
 * Class TestWorker
 */
class TestWorker implements JobWorkerInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job that is being run.
     * @return \DelayedJobs\Result\ResultInterface
     */
    public function __invoke(Job $job)
    {
        sleep(2);
        $time = (new Time())->i18nFormat();
        if ($job->getPayload('type') === 'success') {
            return Success::create('Successful test at ' . $time);
        }

        return Failed::create('Failing test at ' . $time . ' because ' . $job->getPayload()['type']);
    }
}
