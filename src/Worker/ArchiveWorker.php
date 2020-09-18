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
use Lampager\Cake\ORM\Query;

/**
 * Class ArchiveWorker
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 * @property \Cake\ORM\Table $Archive
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
            $djSchema = $this->DelayedJobs->getSchema();
            $djColumns = $djSchema->columns();
            $columns = [];
            foreach ($djColumns as $djColumn) {
                $columns[$djColumn] = $djSchema->getColumn($djColumn);
            }
            $columns['payload']['type'] = 'binary';
            $columns['options']['type'] = 'binary';
            $archiveTableSchema = new TableSchema($archiveTable->getTable(), $columns);
            $archiveTableSchema->addConstraint('primary', (array)$djSchema->getConstraint('primary'));
            $createSql = $archiveTableSchema->createSql($archiveTable->getConnection());
            foreach ($createSql as $createSqlQuery) {
                $archiveTable->getConnection()
                    ->query($createSqlQuery);
            }
        }
    }

    /**
     * @param \Cake\ORM\Query $baseQuery Base query to paginate
     * @param int|null $total Total number of records
     * @return \Generator
     */
    protected function doCursorPagination(\Cake\ORM\Query $baseQuery, ?int $total = null): \Generator
    {
        if (!class_exists('Lampager\Cake\Datasource\Paginator')) {
            yield $baseQuery;

            return;
        }

        $perPage = Configure::read('DelayedJobs.archive.perPage', 1000);
        $baseQuery->limit($perPage);
        $pagedQuery = Query::fromQuery($baseQuery);
        $pagedQuery->unseekable(true);

        $cursor = [];
        $hasNext = true;

        Log::debug('Using pagination.');
        if ($total) {
            Log::debug('Total of ' . ceil($total / $perPage) . ' pages.');
        }

        while ($hasNext) {
            usleep(100000); // Wait 100ms per page to not kill the DBS
            $pagedQuery->cursor($cursor);
            $pagedResult = $pagedQuery->all();

            $hasNext = $pagedResult->hasNext;
            $cursor = $pagedResult->nextCursor;

            yield $pagedQuery;
        }
    }

    /**
     * @return \Generator|void
     */
    protected function getJobsToArchive(): \Generator
    {
        $baseQuery = $this->DelayedJobs->query()
            ->where(['status IN' => [Job::STATUS_BURIED, Job::STATUS_SUCCESS]])
            ->order(['id' => 'ASC']);

        $total = $baseQuery->count();
        if ($total === 0) {
            Log::debug('No jobs to be archived.');

            return;
        }

        Log::debug($total . ' jobs to be archived.');

        yield from $this->doCursorPagination($baseQuery, $total);
    }

    /**
     * @param \Cake\I18n\Time $time Time up to which to delete
     * @return \Generator|void
     */
    protected function getJobsToDelete(Time $time): \Generator
    {
        $lastIdToDelete = $this->Archive->find()
                ->select(['id'])
                ->where(['created <=' => $time])
                ->orderDesc('id')
                ->disableHydration()
                ->first()['id'] ?? null;

        if (!$lastIdToDelete) {
            Log::debug('No jobs to be deleted.');

            return;
        }

        $baseQuery = $this->Archive->query()
            ->select(['id'])
            ->where([
                'id <=' => $lastIdToDelete,
                'created <=' => $time,
            ])
            ->order(['id' => 'ASC']);

        yield from $this->doCursorPagination($baseQuery);
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

        $this->loadModel('DelayedJobs.DelayedJobs');

        // We need to use the TableLocator, and not loadModel here because we need to set the custom table name
        $this->Archive = $this->getTableLocator()->get('Archive', [
            'table' => Configure::read('DelayedJobs.archive.tableName'),
        ]);

        $this->_ensureTable($this->Archive);

        $connection = $this->Archive->getConnection();
        $quote = $connection->getDriver()
            ->isAutoQuotingEnabled();
        $connection->getDriver()
            ->enableAutoQuoting(true);

        $columns = $this->DelayedJobs->getSchema()
            ->columns();
        $pageNumber = 1;
        foreach ($this->getJobsToArchive() as $toArchiveQuery) {
            Log::debug('Archiving page ' . $pageNumber++);
            $clonedQuery = clone $toArchiveQuery;

            $insertQuery = $this->Archive->query();
            $insertQuery->insert($columns)
                ->modifier('IGNORE')
                ->values($toArchiveQuery)
                ->execute();

            Log::debug('Jobs archived. Starting delete.');
            $clonedQuery->select(['id']);
            $this->DelayedJobs->deleteAll(['id IN' => $clonedQuery->all()->extract('id')->toArray()]);
            Log::debug('Jobs deleted.');
        }

        if (Configure::read('DelayedJobs.archive.timeLimit')) {
            $time = new Time('-' . Configure::read('DelayedJobs.archive.timeLimit'));
            Log::debug('Cleaning archive. All jobs older than ' . $time);

            $pageNumber = 1;
            foreach ($this->getJobsToDelete($time) as $toDeleteQuery) {
                Log::debug('Deleting page ' . $pageNumber++);

                $this->Archive->deleteAll(
                    [
                        'id IN' => $toDeleteQuery->all()
                            ->extract('id')
                            ->toArray(),
                    ]
                );
            }

            Log::debug('Archive cleaned.');
        }

        $connection->getDriver()
            ->enableAutoQuoting($quote);

        return new Time(Configure::read('DelayedJobs.archive.recurring'));
    }
}
