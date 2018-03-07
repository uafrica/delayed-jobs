<?php

namespace DelayedJobs\Broker\Driver;

use Cake\Core\InstanceConfigTrait;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\ManagerInterface;
use DelayedJobs\DelayedJob\MessageBrokerInterface;
use DelayedJobs\Traits\DebugLoggerTrait;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazySocketConnection;
use PhpAmqpLib\Exception\AMQPIOWaitException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Class PhpAmqpLibDriver
 */
class PhpAmqpLibDriver implements RabbitMqDriverInterface
{
    use DebugLoggerTrait;
    use InstanceConfigTrait;

    /**
     *
     */
    const TIMEOUT = 5; //In seconds

    /**
     * @var \PhpAmqpLib\Connection\AbstractConnection
     */
    protected $_connection = null;

    /**
     * @var \PhpAmqpLib\Channel\AMQPChannel
     */
    protected $_channel = null;

    /**
     * @var array
     */
    protected $_defaultConfig = [];

    /**
     * @var \DelayedJobs\DelayedJob\ManagerInterface
     */
    protected $_manager;

    /**
     * @param array $config
     * @param \DelayedJobs\DelayedJob\ManagerInterface $manager
     * @param \PhpAmqpLib\Connection\AbstractConnection|null $connection
     */
    public function __construct(array $config = [], ManagerInterface $manager, AbstractConnection $connection = null)
    {
        $this->setConfig($config);

        if ($connection) {
            $this->_connection = $connection;
        }

        $this->_manager = $manager;
    }

    public function __destroy()
    {
        if ($this->_channel) {
            $this->_channel->close();
        }
        if ($this->_connection && $this->_connection->isConnected()) {
            $this->_connection->close();
        }
    }

    /**
     * @return \PhpAmqpLib\Connection\AbstractConnection
     */
    public function getConnection()
    {
        if ($this->_connection) {
            return $this->_connection;
        }

        $config = $this->getConfig();
        $this->_connection = new AMQPLazySocketConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['pass'],
            $config['path'],
            false,
            'AMQPLAIN',
            null,
            'en_US',
            10
        );

        return $this->_connection;
    }

    /**
     * @return \PhpAmqpLib\Channel\AMQPChannel
     */
    public function getChannel()
    {
        if ($this->_channel) {
            return $this->_channel;
        }

        try {
            $this->_channel = $this->getConnection()->channel();
        } catch (\Throwable $e) {
            //If something went wrong, catch it, disconnect and try again.
            unset($this->_connection);
            $this->_channel = $this->getConnection()->channel();
        }

        return $this->_channel;
    }

    public function declareExchange()
    {
        $prefix = $this->getConfig('prefix');
        $channel = $this->getChannel();

        $channel->exchange_declare($prefix . 'direct-exchange', 'direct', false, true, false, false, false);
        $channel->exchange_declare($prefix . 'delayed-exchange', 'x-delayed-message', false, true, false, false, false, [
            'x-delayed-type' => [
                'S',
                'direct'
            ]
        ]);
    }

    public function declareQueue($maximumPriority)
    {
        $prefix = $this->getConfig('prefix');
        $channel = $this->getChannel();

        $channel->queue_declare($prefix . 'queue', false, true, false, false, false, [
            'x-max-priority' => ['s', $maximumPriority]
        ]);
    }

    public function bind()
    {
        $prefix = $this->getConfig('prefix');
        $routingKey = $this->getConfig('routingKey');
        $channel = $this->getChannel();

        $channel->queue_bind($prefix . 'queue', $prefix . 'delayed-exchange', $routingKey);
        $channel->queue_bind($prefix . 'queue', $prefix . 'direct-exchange', $routingKey);
    }

    public function publishJob(array $jobData)
    {
        $prefix = $this->getConfig('prefix');
        $routingKey = $this->getConfig('routingKey');
        $channel = $this->getChannel();

        $messageProperties = [
            'delivery_mode' => 2,
            'priority' => $jobData['priority']
        ];

        if ($jobData['delay'] > 0) {
            $headers = new AMQPTable();
            $headers->set('x-delay', $jobData['delay']);
            $messageProperties['application_headers'] = $headers;
        }

        $message = new AMQPMessage(json_encode($jobData['payload']), $messageProperties);

        $exchange = $prefix . ($jobData['delay'] > 0 ? 'delayed-exchange' : 'direct-exchange');
        $channel->basic_publish($message, $exchange, $routingKey);
    }

    public function consume(callable $callback, callable $heartbeat)
    {
        $prefix = $this->getConfig('prefix');
        $channel = $this->getChannel();
        $channel->basic_qos(null, $this->getConfig('qos'), null);

        $this->declareExchange();
        $this->declareQueue($this->_manager->getConfig('maximum.priority'));
        $this->bind();

        $tag = $channel->basic_consume($prefix . 'queue', '', false, false, false, false, function (AMQPMessage $message) use ($callback) {
            $body = json_decode($message->getBody(), true);

            $job = new Job();
            $job->setBrokerMessage($message);

            if (isset($message->get_properties()['correlation_id'])) {
                $job
                    ->setId($message->get_properties()['correlation_id'])
                    ->setBrokerMessageBody($body); //If we're using a correlation id, then the message body is something special, and should be recorded as such.
            } elseif (isset($body['id'])) {
                $job->setId($body['id']);
            }

            return $callback($job, $message->delivery_info['redelivered']);
        });
        $this->_tag = $tag;

        $time = microtime(true);
        while (count($channel->callbacks)) {
            try {
                $channel->wait(null, false, static::TIMEOUT);
            } catch (AMQPTimeoutException $e) {
                $heartbeat();
            } catch (AMQPIOWaitException $e) {
                $heartbeat();
            }

            if (microtime(true) - $time >= static::TIMEOUT) {
                $heartbeat();
                $time = microtime(true);
            }
        }
    }

    public function stopConsuming()
    {
        $channel = $this->getChannel();
        $channel->basic_cancel($this->_tag);
    }

    public function wait($timeout = 1)
    {
        $channel = $this->getChannel();
        try {
            $channel->wait(null, true, $timeout);

            return true;
        } catch (AMQPTimeoutException $e) {
            return false;
        }
    }

    public function ack(Job $job)
    {
        $message = $job->getBrokerMessage();

        if ($message === null) {
            return;
        }

        $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
    }

    public function nack(Job $job, $requeue = true)
    {
        $message = $job->getBrokerMessage();

        if ($message === null) {
            return;
        }

        $message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, $requeue);
    }

    /**
     * @param string $body
     * @param string $exchange
     * @param string $routing_key
     * @param int $priority
     * @param array $headers
     * @return void
     */
    public function publishBasic(string $body, $exchange = '', $routing_key = '', int $priority = 0, array $headers = [])
    {
        $channel = $this->getChannel();
        $messageHeaders = new AMQPTable($headers);
        $message = new AMQPMessage($body, [
            'priority' => $priority,
            'delivery_mode' => 2,
            'application_headers' => $messageHeaders
        ]);
        $channel->basic_publish($message, $exchange, $routing_key);
    }
}
