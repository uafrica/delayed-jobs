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
use DelayedJobs\DelayedJob\MessageBrokerInterface;
use DelayedJobs\Traits\DebugTrait;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPAbstractCollection;
use PhpAmqpLib\Wire\AMQPTable;

class PhpAmqpLibDriver
{
    use DebugTrait;
    use InstanceConfigTrait;

    /**
     * @var \PhpAmqpLib\Connection\AbstractConnection
     */
    protected $_connection = null;

    /**
     * @var \PhpAmqpLib\Channel\AMQPChannel
     */
    protected $_channel = null;

    protected $_serviceName;

    protected $_defaultConfig;

    /**
     * @param \PhpAmqpLib\Connection\AbstractConnection|null $connection
     */
    public function __construct(array $config = [], AbstractConnection $connection = null)
    {
        $this->config($config);

        if ($connection) {
            $this->_connection = $connection;
        }
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

        $this->_channel = $this->getConnection->channel();

        return $this->_channel;
    }

    public function declareExchange($prefix)
    {
        $channel = $this->getChannel();

        $channel->exchange_declare($prefix . 'direct-exchange', 'direct', false, true, false, false, false);
        $channel->exchange_declare($prefix . 'delayed-exchange', 'x-delayed-message', false, true, false, false, false, [
            'x-delayed-type' => [
                'S',
                'direct'
            ]
        ]);
    }

    public function declareQueue($prefix, $maximumPriority)
    {
        $channel = $this->getChannel();

        $channel->queue_declare($prefix . '-queue', false, true, false, false, false, [
            'x-max-priority' => ['s', $maximumPriority]
        ]);
    }

    public function bind($prefix, $routingKey)
    {
        $channel = $this->getChannel();

        $channel->queue_bind($prefix . 'queue', $routingKey . 'delayed-exchange', $this->config('routingKey'));
        $channel->queue_bind($prefix . 'queue', $routingKey . 'direct-exchange', $this->config('routingKey'));
    }

    public function publishJob($jobData, $prefix, $routingKey)
    {
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

    public function requeueMessage(AMQPMessage $message, $delay = 5000)
    {
        $channel = $this->getChannel();

        if ($delay > 0) {
            $headers = new AMQPTable();
            $headers->set('x-delay', $delay);
            $message->set('application_headers', $headers);
        }

        $body = json_decode($message->body, true);
        $body['is-requeue'] = true;
        $message->setBody(json_encode($body));

        $exchange = $this->_serviceName . ($delay > 0 ? '-delayed-exchange' : '-direct-exchange');
        $channel->basic_publish($message, $exchange, $this->_serviceName);
        $this->dj_log(__('Job {0} has been requeued to {1}, a delay of {2}', $message->body, $exchange, $delay));

        $channel->wait_for_pending_acks();
    }

    public function listen($callback, $qos = 1)
    {
        $channel = $this->getChannel();
        $channel->basic_qos(null, $qos, null);
        return $channel->basic_consume($this->_serviceName . '-queue', '', false, false, false, false, $callback);
    }

    public function stopListening($tag)
    {
        $channel = $this->getChannel();
        $channel->basic_cancel($tag);
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

    public function ack(AMQPMessage $message)
    {
        $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
    }

    public function nack(AMQPMessage $message, $requeue = true)
    {
        $message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, $requeue);
    }

    public static function queueStatus()
    {
        $config = Configure::read('dj.service.rabbit.server');

        $client = new Client([
            'host' => $config['host'],
            'port' => 15672,
            'auth' => [
                'username' => $config['user'],
                'password' => $config['pass']
            ]
        ]);
        try {
            $queue_data = $client->get(sprintf('/api/queues/%s/%s', urlencode($config['path']),
                Configure::read('dj.service.name') . '-queue'), [], [
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
     * @return bool
     */
    public function testConnection()
    {
        try {
            $this->_connection->getSocket();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
