<?php
namespace DelayedJobs\Worker;

use Cake\Datasource\ModelAwareTrait;
use DelayedJobs\DelayedJob\DelayedJobInterface;
use DelayedJobs\DelayedJob\DelayedJobTrait;

/**
 * Class BaseWorker
 */
abstract class BaseWorker implements JobWorkerInterface
{
    use DelayedJobTrait;
    use ModelAwareTrait;

    /**
     * @var \Cake\Console\Shell
     */
    protected $_shell;

    /**
     * Construct the listener
     *
     * @param array $options Allow child listeners to have options
     */
    public function __construct(array $options = [])
    {
        $this->modelFactory('Table', ['Cake\ORM\TableRegistry', 'get']);

        if (isset($options['shell'])) {
            $this->_shell = $options['shell'];
            unset($options['shell']);
        }
    }
}
