<?php

namespace DelayedJobs\Worker;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use DelayedJobs\DelayedJob\Job;

/**
 * Class ArchiveWorker
 */
class ArchiveWorker extends Worker
{
    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job that is being run.
     * @return null|bool|\Cake\I18n\Time|string
     */
    public function __invoke(Job $job)
    {
        if (Configure::read('DelayedJobs.archive.enabled') === false) {
            return;
        }

        $archiveTable = TableRegistry::get('Archive', [
            'table' => Configure::read('DelayedJobs.archive.tableName')
        ]);
    }

}
