<?php
namespace DelayedJobs\Worker;

use Cake\Datasource\ModelAwareTrait;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use DelayedJobs\DelayedJob\DelayedJobInterface;
use DelayedJobs\DelayedJob\EnqueueTrait;

/**
 * Class BaseWorker
 */
abstract class Worker implements JobWorkerInterface, EventDispatcherInterface
{
    use EnqueueTrait;
    use EventDispatcherTrait;
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
