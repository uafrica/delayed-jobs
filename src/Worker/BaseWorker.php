<?php
namespace DelayedJobs\Worker;

use Cake\Datasource\ModelAwareTrait;
use DelayedJobs\DelayedJob\DelayedJobInterface;
use DelayedJobs\DelayedJob\DelayedJobTrait;

/**
 * Class BaseWorker
 */
abstract class BaseWorker implements JobWorkerInterface, DelayedJobInterface
{
    use DelayedJobTrait;
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
