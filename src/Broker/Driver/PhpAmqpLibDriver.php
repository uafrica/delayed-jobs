<?php

namespace DelayedJobs\Broker\Driver;

use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Cake\Core\InstanceConfigTrait;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\Network\Http\Client;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\JobManager;
use DelayedJobs\DelayedJob\ManagerInterface;
use DelayedJobs\DelayedJob\MessageBrokerInterface;
use DelayedJobs\Traits\DebugLoggerTrait;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Exception\AMQPIOWaitException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPAbstractCollection;
use PhpAmqpLib\Wire\AMQPTable;

class PhpAmqpLibDriver
{
    use DebugLoggerTrait;
    use InstanceConfigTrait;

    const TIMEOUT = 5; //In seconds

    /**
     * @var \PhpAmqpLib\Connection\AbstractConnection
     */
    protected $_connection = null;

    /**
     * @var \PhpAmqpLib\Channel\AMQPChannel
     */
    protected $_channel = null;

    protected $_defaultConfig = [];

    /**
     * @var \DelayedJobs\DelayedJob\ManagerInterface
     */
    protected $_manager;

    /**
     * @param \PhpAmqpLib\Connection\AbstractConnection|null $connection
     */
    public function __construct(array $config = [], ManagerInterface $manager, AbstractConnection $connection = null)
    {
        $this->config($config);

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
        if ($this->getConnection && $this->getConnection->isConnected()) {
            $this->getConnection->close();
        }
    }

    public function getConnection()
    {
        if ($this->_connection) {
            return $this->_connection;
        }

        $config = $this->config();
        $this->_connection = new AMQPLazyConnection($config['host'], $config['port'], $config['user'], $config['pass'], $config['path']);

        return $this->_connection;
    }

    public function getChannel()
    {
        if ($this->_channel) {
            return $this->_channel;
        }

        $this->_channel = $this->getConnection()->channel();

        $this->declareExchange();
        $this->declareQueue($this->_manager->config('maximum.priority'));
        $this->bind();

        return $this->_channel;
    }

    public function declareExchange()
    {
        $prefix = $this->config('prefix');
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
        $prefix = $this->config('prefix');
        $channel = $this->getChannel();

        $channel->queue_declare($prefix . 'queue', false, true, false, false, false, [
            'x-max-priority' => ['s', $maximumPriority]
        ]);
    }

    public function bind()
    {
        $prefix = $this->config('prefix');
        $routingKey = $this->config('routingKey');
        $channel = $this->getChannel();

        $channel->queue_bind($prefix . 'queue', $prefix . 'delayed-exchange', $routingKey);
        $channel->queue_bind($prefix . 'queue', $prefix . 'direct-exchange', $routingKey);
    }

    public function publishJob($jobData)
    {
        $prefix = $this->config('prefix');
        $routingKey = $this->config('routingKey');
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

        return $message;
    }

    public function consume(callable $callback, callable $heartbeat)
    {
        $prefix = $this->config('prefix');
        $channel = $this->getChannel();
        $channel->basic_qos(null, $this->config('qos'), null);

        $tag = $channel->basic_consume($prefix . 'queue', '', false, false, false, false, function (AMQPMessage $message) use ($callback) {
            $body = json_decode($message->body, true);

            $job = new Job();
            $job
                ->setId($body['id'])
                ->setBrokerMessage($message);

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
}
