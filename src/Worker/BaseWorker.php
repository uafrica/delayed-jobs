<?php
namespace DelayedJobs\Worker;

use Cake\Datasource\ModelAwareTrait;

/**
 * Class BaseWorker
 */
abstract class BaseWorker implements JobWorkerInterface
{
    use ModelAwareTrait;

    /**
     * Construct the listener
     *
     * @param array $options Allow child listeners to have options
     */
    public function __construct(array $options = [])
    {
        $this->modelFactory('Table', ['Cake\ORM\TableRegistry', 'get']);
    }
}
