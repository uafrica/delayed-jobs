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

    /**
     * @var array
     */
    protected $_defaultConfig = [];

    /**
     * BaseDatasource constructor.
     *
     * @param array $config
     */
    public function __construct($config = [])
    {
        $this->setConfig($config);
    }
}
