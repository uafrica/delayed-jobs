<?php
declare(strict_types=1);

namespace DelayedJobs\Worker;

use Cake\Core\Configure;
use Cake\Database\Exception;
use Cake\Database\Schema\TableSchema;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use DelayedJobs\DelayedJob\Job;

/**
 * Class ArchiveWorker
 */
class ArchiveWorker extends Worker
{
    /**
     * @param \Cake\ORM\Table $archiveTable Archive table instance
     * @return void
     */
    protected function _ensureTable(Table $archiveTable)
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
            $archiveTableSchema = new TableSchema($archiveTable->getTable(), $columns);
            $archiveTableSchema->addConstraint('primary', $djSchema->getConstraint('primary'));
            $createSql = $archiveTableSchema->createSql($archiveTable->getConnection());
            foreach ($createSql as $createSqlQuery) {
                $archiveTable->getConnection()
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
            'table' => Configure::read('DelayedJobs.archive.tableName'),
        ]);

        $this->_ensureTable($archiveTable);

        $connection = $archiveTable->getConnection();
        $quote = $connection->getDriver()
            ->isAutoQuotingEnabled();
        $connection->getDriver()
            ->enableAutoQuoting(true);

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
                'created <=' => $time,
            ]);
            Log::debug('Archive cleaned.');
        }

        $connection->getDriver()
            ->enableAutoQuoting($quote);

        return new Time(Configure::read('DelayedJobs.archive.recurring'));
    }
}
