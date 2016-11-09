<?php

namespace DelayedJobs\Worker;

use Cake\Core\Configure;
use Cake\Database\Exception;
use Cake\Database\Query;
use Cake\Database\Schema\Table;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Time;
use Cake\ORM\TableRegistry;
use DelayedJobs\DelayedJob\Job;

/**
 * Class ArchiveWorker
 */
class ArchiveWorker extends Worker
{
    protected function _ensureTable($archiveTable)
    {
        try {
            $archiveTable->schema();
        } catch (Exception $e) {
            $djSchema = TableRegistry::get('DelayedJobs.DelayedJobs')->schema();
            $djColumns = $djSchema->columns();
            $columns = [];
            foreach ($djColumns as $djColumn) {
                $columns[$djColumn] = $djSchema->column($djColumn);
            }
            $columns['payload']['type'] = 'binary';
            $columns['options']['type'] = 'binary';
            $archiveTableSchema = new Table($archiveTable->table(), $columns);
            $archiveTableSchema->addConstraint('primary', $djSchema->constraint('primary'));
            $createSql = $archiveTableSchema->createSql($archiveTable->connection());
            foreach ($createSql as $createSqlQuery) {
                $archiveTable->connection()
                    ->query($createSqlQuery);
            }
        }
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job that is being run.
     * @return null|bool|\Cake\I18n\Time|string
     */
    public function __invoke(Job $job)
    {
        if (Configure::read('DelayedJobs.archive.enabled') !== true) {
            return;
        }

        $delayedJobsTable = TableRegistry::get('DelayedJobs.DelayedJobs');
        $archiveTable = TableRegistry::get('Archive', [
            'table' => Configure::read('DelayedJobs.archive.tableName')
        ]);

        $this->_ensureTable($archiveTable);

        $connection = $archiveTable->connection();
        $quote = $connection->driver()
            ->autoQuoting();
        $connection->driver()
            ->autoQuoting(true);

        $selectQuery = $delayedJobsTable->query()
            ->where(['status IN' => [Job::STATUS_BURRIED, Job::STATUS_SUCCESS]]);

        $insertQuery = $archiveTable->query();
        $insertQuery
            ->insert($delayedJobsTable->schema()->columns())
            ->values($selectQuery)
            ->execute();

        $delayedJobsTable->deleteAll(['status IN' => [Job::STATUS_BURRIED, Job::STATUS_SUCCESS]]);

        $connection->driver()
            ->autoQuoting($quote);

        return new Time(Configure::read('DelayedJobs.archive.recurring'));
    }

}
