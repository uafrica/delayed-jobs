<?php

namespace DelayedJobs\Broker;

use Cake\Core\InstanceConfigTrait;
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
        'driver' => PhpAmqpLibDriver::class
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
        $this->_driver = new $config['driver']($config);

        return $this->_driver;
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job Job to publish
     * @return mixed
     */
    public function publishJob(Job $job)
    {
        $driver = $this->getDriver();
        $driver->declareExchange($this->config('prefix'));
        $driver->declareQueue($this->config('prefix'), $this->_manager->config('maximumPriority'));
        $driver->bindQueue($this->config('prefix'), $this->config(['routingKey']));

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

        $driver->publish($jobData, $this->config('prefix'), $this->config('routingKey'));
    }

    public function consume(callable $callback, array $options)
    {
        // TODO: Implement consume() method.
    }

    public function stopConsuming()
    {
        // TODO: Implement stopConsuming() method.
    }

    public function ack(Job $job)
    {
        // TODO: Implement ack() method.
    }

    public function nack(Job $job, $requeue = false)
    {
        // TODO: Implement nack() method.
    }

}
