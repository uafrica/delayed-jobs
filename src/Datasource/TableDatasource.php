<?php
declare(strict_types=1);

namespace DelayedJobs\Datasource;

use Cake\ORM\Locator\LocatorAwareTrait;
use DelayedJobs\DelayedJob\DatastoreInterface;
use DelayedJobs\DelayedJob\Exception\JobNotFoundException;
use DelayedJobs\DelayedJob\Job;

/**
 * Class DatabaseSource
 */
class TableDatasource extends BaseDatasource
{
    use LocatorAwareTrait;

    /**
     * @var array
     */
    protected $_defaultConfig = [
        'tableName' => 'DelayedJobs.DelayedJobs',
    ];

    /**
     * @return \DelayedJobs\DelayedJob\DatastoreInterface
     */
    protected function _table(): DatastoreInterface
    {
        $table = $this->getTableLocator()
            ->get($this->getConfig('tableName'));

        if (!$table instanceof DatastoreInterface) {
            throw new \RuntimeException(
                $this->getConfig('tableName') . ' is not an instance of ' . DatastoreInterface::class
            );
        }

        return $table;
    }

    /**
     * {@inheritDoc}
     */
    public function persistJob(Job $job)
    {
        return $this->_table()
            ->persistJob($job);
    }

    /**
     * {@inheritDoc}
     */
    public function persistJobs(array $jobs): array
    {
        return $this->_table()
            ->persistJobs($jobs);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchJob(int $jobId): Job
    {
        $job = $this->_table()
            ->fetchJob($jobId);

        if (!$job) {
            throw new JobNotFoundException(sprintf('Job with id "%s" does not exist in the datastore.', $jobId));
        }

        return $job;
    }

    /**
     * {@inheritDoc}
     */
    public function currentlySequenced(Job $job): bool
    {
        return $this->_table()
            ->currentlySequenced($job);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchNextSequence(Job $job): ?Job
    {
        return $this->_table()
            ->fetchNextSequence($job);
    }

    /**
     * {@inheritDoc}
     */
    public function isSimilarJob(Job $job): bool
    {
        return $this->_table()
            ->isSimilarJob($job);
    }

    /**
     * {@inheritDoc}
     */
    public function loadJob(Job $job): Job
    {
        $jobEntity = $this->_table()->fetchJobEntity($job->getId());

        if ($jobEntity === null) {
            throw new JobNotFoundException(sprintf(
                'Job with id "%s" does not exist in the datastore.',
                $job->getId()
            ));
        }

        return $job->setDataFromEntity($jobEntity);
    }
}
