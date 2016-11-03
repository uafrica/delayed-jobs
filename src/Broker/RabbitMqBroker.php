<?php

namespace DelayedJobs\Broker;

use Cake\Core\InstanceConfigTrait;
use Cake\I18n\Time;
use DelayedJobs\Broker\Driver\PhpAmqpLibDriver;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\ManagerInterface;

/**
 * Class RabbitMqBroker
 */
class RabbitMqBroker implements BrokerInterface
{
    use InstanceConfigTrait;

    protected $_defaultConfig = [
        'driver' => PhpAmqpLibDriver::class,
        'prefix' => '',
        'routingKey' => '',
        'qos' => 1
    ];

    protected $_driver;

    /**
     * @var \DelayedJobs\DelayedJob\ManagerInterface
     */
    protected $_manager;

    public function __construct($config = [], ManagerInterface $manager)
    {
        $this->config($config);

        $this->_manager = $manager;
    }

    public function getDriver()
    {
        if ($this->_driver) {
            return $this->_driver;
        }

        $config = $this->config();
        $this->_driver = new $config['driver']($config, $this->_manager);

        return $this->_driver;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to publish
     * @return mixed
     */
    public function publishJob(Job $job)
    {
        $driver = $this->getDriver();

        $delay = $job->getRunAt()->isFuture() ? Time::now()->diffInSeconds($job->getRunAt(), false) * 1000 : 0;

        //Invert the priority because Rabbit does things differently
        $jobPriority = $this->_manager->config('maximumPriority') - $job->getPriority();
        $jobData = [
            'priority' => $jobPriority,
            'delay' => $delay,
            'payload' => ['id' => $job->getId()]
        ];
        if ($jobData['priority'] < 0) {
            $jobData['priority'] = 0;
        }

        $driver->publishJob($jobData);
    }

    public function consume(callable $callback, callable $heartbeat)
    {
       $this->getDriver()->consume($callback, $heartbeat);
    }

    public function stopConsuming()
    {
        $this->getDriver()->stopConsuming();
    }

    public function ack(Job $job)
    {
        $this->getDriver()->ack($job);
    }

    public function nack(Job $job, $requeue = false)
    {
        $this->getDriver()
            ->nack($job, $requeue);
    }

}
