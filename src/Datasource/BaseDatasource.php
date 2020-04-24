<?php
declare(strict_types=1);

namespace DelayedJobs\Datasource;

use Cake\Core\InstanceConfigTrait;

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
     * @param array $config Config array
     */
    public function __construct($config = [])
    {
        $this->setConfig($config);
    }
}
