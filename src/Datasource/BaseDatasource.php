<?php

namespace DelayedJobs\Datasource;

use Cake\Core\InstanceConfigTrait;
use DelayedJobs\DelayedJob\Job;

/**
 * Abstract class BaseDatasource
 */
abstract class BaseDatasource implements DatasourceInterface
{
    use InstanceConfigTrait;

    protected $_defaultConfig = [];

    public function __construct($config = [])
    {
        $this->config($config);
    }
}
