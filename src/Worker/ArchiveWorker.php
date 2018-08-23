<?php

namespace DelayedJobs\Worker;

use Cake\Core\Configure;
use Cake\Database\Exception;
use Cake\Database\Query;
use Cake\Database\Schema\Table;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use DelayedJobs\DelayedJob\Job;

/**
 * Class ArchiveWorker
 */
class ArchiveWorker extends Worker
{
    /**
     * @param $archiveTable
     * @return void
     */
    protected function _ensureTable($archiveTable)
    {
        try {
            $archiveTable->getSchema();
        } catch (Exception $e) {
            $djSchema = TableRegistry::getTableLocator()->get('DelayedJobs.DelayedJobs')->getSchema();
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
            return 'Not enabled for archiving';
        }

        $delayedJobsTable = TableRegistry::getTableLocator()->get('DelayedJobs.DelayedJobs');
        $archiveTable = TableRegistry::getTableLocator()->get('Archive', [
            'table' => Configure::read('DelayedJobs.archive.tableName')
        ]);

        $this->_ensureTable($archiveTable);

        $connection = $archiveTable->getConnection();
        $quote = $connection->driver()
            ->autoQuoting();
        $connection->driver()
            ->autoQuoting(true);

        $selectQuery = $delayedJobsTable->query()
            ->where(['status IN' => [Job::STATUS_BURIED, Job::STATUS_SUCCESS]]);

        Log::debug($selectQuery->count() . ' jobs to be archived.');
        $insertQuery = $archiveTable->query();
        $insertQuery
            ->insert($delayedJobsTable->getSchema()->columns())
            ->modifier('IGNORE')
            ->values($selectQuery)
            ->execute();

        Log::debug('Jobs archived. Starting delete.');
        $delayedJobsTable->deleteAll(['status IN' => [Job::STATUS_BURIED, Job::STATUS_SUCCESS]]);
        Log::debug('Jobs deleted.');

        if (Configure::read('DelayedJobs.archive.timeLimit')) {
            $time = new Time('-' . Configure::read('DelayedJobs.archive.timeLimit'));
            Log::debug('Cleaning archive. All jobs older than ' . $time);
            $archiveTable->deleteAll([
                'created <=' => $time
            ]);
            Log::debug('Archive cleaned.');
        }

        $connection->driver()
            ->autoQuoting($quote);

        return new Time(Configure::read('DelayedJobs.archive.recurring'));
    }
}
