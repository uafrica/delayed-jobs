<?php
declare(strict_types=1);

namespace DelayedJobs\Broker;

use Cake\Core\InstanceConfigTrait;
use Cake\I18n\Time;
use DelayedJobs\Broker\Driver\PhpAmqpLibDriver;
use DelayedJobs\Broker\Driver\RabbitMqDriverInterface;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\ManagerInterface;

/**
 * Class RabbitMqBroker
 */
class RabbitMqBroker implements BrokerInterface
{
    use InstanceConfigTrait;

    /**
     * @var array
     */
    protected $_defaultConfig = [
        'driver' => PhpAmqpLibDriver::class,
        'prefix' => '',
        'routingKey' => '',
        'qos' => 1,
    ];

    /**
     * @var \DelayedJobs\Broker\Driver\RabbitMqDriverInterface|null
     */
    protected $_driver;

    /**
     * @var \DelayedJobs\DelayedJob\ManagerInterface
     */
    protected $_manager;

    /**
     * RabbitMqBroker constructor.
     *
     * @param array $config array of config
     * @param \DelayedJobs\DelayedJob\ManagerInterface $manager Job manager interface
     */
    public function __construct(array $config, ManagerInterface $manager)
    {
        $this->setConfig($config);

        $this->_manager = $manager;
    }

    /**
     * @return \DelayedJobs\Broker\Driver\RabbitMqDriverInterface
     */
    public function getDriver(): RabbitMqDriverInterface
    {
        if ($this->_driver) {
            return $this->_driver;
        }

        $config = $this->getConfig();
        $this->_driver = new $config['driver']($config, $this->_manager);

        return $this->_driver;
    }

    /**
     * {@inheritDoc}
     */
    public function publishJob(Job $job): void
    {
        $delay = $job->getRunAt()->isFuture() ? Time::now()->diffInSeconds($job->getRunAt(), false) * 1000 : 0;

        $jobPriority = $this->_manager->getMaximumPriority() - $job->getPriority();
        if ($jobPriority < 0) {
            $jobPriority = 0;
        } elseif ($jobPriority > 255) {
            $jobPriority = 255;
        }

        //Invert the priority because Rabbit does things differently
        $jobData = [
            'priority' => $jobPriority,
            'delay' => $delay,
            'payload' => ['id' => $job->getId()],
        ];

        $this->getDriver()->publishJob($jobData);
        $job->setPushedToBroker(true);
    }

    /**
     * {@inheritDoc}
     */
    public function consume(callable $callback, callable $heartbeat): void
    {
        $this->getDriver()->consume($callback, $heartbeat);
    }

    /**
     * {@inheritDoc}
     */
    public function stopConsuming(): void
    {
        $this->getDriver()->stopConsuming();
    }

    /**
     * {@inheritDoc}
     */
    public function acknowledge(Job $job): void
    {
        $this->getDriver()->acknowledge($job);
    }

    /**
     * {@inheritDoc}
     */
    public function negativeAcknowledge(Job $job, bool $requeue = false): void
    {
        $this->getDriver()
            ->negativeAcknowledge($job, $requeue);
    }

    /**
     * {@inheritDoc}
     */
    public function publishBasic(
        string $body,
        string $exchange = '',
        string $routing_key = '',
        int $priority = 0,
        array $headers = []
    ): void {
        $this->getDriver()->publishBasic($body, $exchange, $routing_key, $priority, $headers);
    }
}
