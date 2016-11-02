<?php

namespace DelayedJobs\DelayedJob;

use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;

/**
 * Class JobExecutor
 */
class JobExecutor implements EventDispatcherInterface
{
    use EventDispatcherTrait;


}
