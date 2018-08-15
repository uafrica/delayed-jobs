<?php

namespace DelayedJobs\Broker;

use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Http\Client;
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

    protected $_defaultConfig = [
        'driver' => PhpAmqpLibDriver::class,
        'prefix' => '',
        'routingKey' => '',
        'qos' => 1
    ];

    /**
     * @var \DelayedJobs\Broker\Driver\RabbitMqDriverInterface
     */
    protected $_driver;

    /**
     * @var \DelayedJobs\DelayedJob\ManagerInterface
     */
    protected $_manager;

    /**
     * RabbitMqBroker constructor.
     *
     * @param array $config
     * @param \DelayedJobs\DelayedJob\ManagerInterface $manager
     */
    public function __construct($config = [], ManagerInterface $manager)
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
     * @param \DelayedJobs\DelayedJob\Job $job Job to publish
     * @return void
     */
    public function publishJob(Job $job)
    {
        $delay = $job->getRunAt()->isFuture() ? Time::now()->diffInSeconds($job->getRunAt(), false) * 1000 : 0;

        $jobPriority = $this->_manager->getConfig('maximum.priority') - $job->getPriority();
        if ($jobPriority < 0) {
            $jobPriority = 0;
        } elseif ($jobPriority > 255) {
            $jobPriority = 255;
        }

        //Invert the priority because Rabbit does things differently
        $jobData = [
            'priority' => $jobPriority,
            'delay' => $delay,
            'payload' => ['id' => $job->getId()]
        ];

        $this->getDriver()->publishJob($jobData);
    }

    /**
     * @param callable $callback
     * @param callable $heartbeat
     * @return void
     */
    public function consume(callable $callback, callable $heartbeat)
    {
        $this->getDriver()->consume($callback, $heartbeat);
    }

    public function stopConsuming()
    {
        $this->getDriver()->stopConsuming();
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @return void
     */
    public function ack(Job $job)
    {
        $this->getDriver()->ack($job);
    }

    /**
     * @param \DelayedJobs\DelayedJob\Job $job
     * @param bool $requeue
     * @return void
     */
    public function nack(Job $job, $requeue = false)
    {
        $this->getDriver()
            ->nack($job, $requeue);
    }

    /**
     * @return array|null
     */
    public function queueStatus()
    {
        $config = $this->getConfig('apiServer');

        $client = new Client([
            'host' => $config['host'],
            'port' => 15672,
            'auth' => [
                'username' => $config['user'],
                'password' => $config['pass']
            ]
        ]);
        try {
            $queue_data = $client->get(sprintf(
                '/api/queues/%s/%s',
                urlencode($config['path']),
                Configure::read('dj.service.name') . '-queue'
            ), [], [
                'type' => 'json'
                ]);
        } catch (Exception $e) {
            return [];
        }
        $data = $queue_data->json;

        if (!isset($data['messages'])) {
            return null;
        }

        return [
            'messages' => $data['messages'],
            'messages_ready' => $data['messages_ready'],
            'messages_unacknowledged' => $data['messages_unacknowledged']
        ];
    }

    /**
     * @param string $body
     * @param string $exchange
     * @param string $routing_key
     * @param array $headers
     * @return void
     */
    public function publishBasic(string $body, $exchange = '', $routing_key = '', int $priority = 0, array $headers = [])
    {
        $this->getDriver()->publishBasic($body, $exchange, $routing_key, $priority, $headers);
    }
}
