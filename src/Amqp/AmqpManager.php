<?php

namespace DelayedJobs\Amqp;

use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\Network\Http\Client;
use DelayedJobs\DelayedJob\DelayedJob;
use DelayedJobs\Traits\DebugTrait;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPAbstractCollection;
use PhpAmqpLib\Wire\AMQPTable;

class AmqpManager
{
    use DebugTrait;

    /**
     * The globally available instance
     *
     * @var \DelayedJobs\Amqp\AmqpManager
     */
    protected static $_generalManager = null;

    /**
     * Internal flag to distinguish a common manager from the singleton
     *
     * @var bool
     */
    protected $_isGlobal = false;

    /**
     * @var \PhpAmqpLib\Connection\AbstractConnection
     */
    protected $_connection = null;

    /**
     * @var \PhpAmqpLib\Channel\AMQPChannel
     */
    protected $_channel = null;

    protected $_serviceName;

    /**
     * Returns the globally available instance of a the AmqpManager
     *
     * @param \DelayedJobs\Amqp\AmqpManager $manager AMQP manager instance.
     * @return \DelayedJobs\Amqp\AmqpManager the global AMQP manager
     */
    public static function instance($manager = null)
    {
        if ($manager instanceof AmqpManager) {
            static::$_generalManager = $manager;
        }
        if (empty(static::$_generalManager)) {
            static::$_generalManager = new AmqpManager();
        }

        static::$_generalManager->_isGlobal = true;

        return static::$_generalManager;
    }

    /**
     * @param \PhpAmqpLib\Connection\AbstractConnection|null $connection
     */
    public function __construct(AbstractConnection $connection = null)
    {
        $config = Configure::read('dj.service.rabbit.server');
        if ($connection === null && !empty($config['host'])) {
            $connection = new AMQPLazyConnection($config['host'], $config['port'], $config['user'], $config['pass'], $config['path']);
        }

        $this->_connection = $connection;
        $this->_serviceName = Configure::read('dj.service.name');
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

    protected function _getChannel()
    {
        if ($this->_channel) {
            return $this->_channel;
        }

        $this->_channel = $this->_connection->channel();

        $this->_ensureQueue($this->_channel);
        $this->_channel->confirm_select();

        $this->_channel->set_ack_handler(function (AMQPMessage $message) {
            $this->dj_log("Message acked with content " . $message->body);
        });
        $this->_channel->set_nack_handler(function (AMQPMessage $message) {
            $this->dj_log("Message nacked with content " . $message->body);
        });
        return $this->_channel;
    }

    protected function _ensureQueue(AMQPChannel $channel)
    {
        $channel->exchange_declare($this->_serviceName . '-direct-exchange', 'direct', false, true, false, false, false);
        $channel->exchange_declare($this->_serviceName . '-delayed-exchange', 'x-delayed-message', false, true, false, false, false, [
            'x-delayed-type' => [
                'S',
                'direct'
            ]
        ]);
        $channel->queue_declare($this->_serviceName . '-queue', false, true, false, false, false, [
            'x-max-priority' => [
                's',
                Configure::read('dj.service.rabbit.max_priority')
            ]
        ]);

        $channel->queue_bind($this->_serviceName . '-queue', $this->_serviceName . '-delayed-exchange', $this->_serviceName);
        $channel->queue_bind($this->_serviceName . '-queue', $this->_serviceName . '-direct-exchange', $this->_serviceName);
    }

    public function queueJob(DelayedJob $job)
    {
        $channel = $this->_getChannel();

        $delay = $job->getRunAt()->isFuture() ? (new Time())->diffInSeconds($job->getRunAt(), false) * 1000 : 0;

        $args = [
            'delivery_mode' => 2,
            'priority' => Configure::read('dj.service.rabbit.max_priority') - $job->getPriority(),
        ];
        if ($args['priority'] < 0) {
            $args['priority'] = 0;
        }
        if ($delay > 0) {
            $headers = new AMQPTable();
            $headers->set('x-delay', $delay);
            $args['application_headers'] = $headers;
        }

        $message = new AMQPMessage(json_encode(['id' => $job->getId()]), $args);

        $exchange = $this->_serviceName . ($delay > 0 ? '-delayed-exchange' : '-direct-exchange');
        $channel->basic_publish($message, $exchange, $this->_serviceName);
        $this->dj_log(__('Job {0} has been queued to {1} with routing key {2}, a delay of {3} and a priority of {4}', $job->id, $exchange, $this->_serviceName, $delay, $args['priority']));

        $channel->wait_for_pending_acks();

        return $message;
    }

    public function requeueMessage(AMQPMessage $message, $delay = 5000)
    {
        $channel = $this->_getChannel();

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
        $channel = $this->_getChannel();
        $channel->basic_qos(null, $qos, null);
        return $channel->basic_consume($this->_serviceName . '-queue', '', false, false, false, false, $callback);
    }

    public function stopListening($tag)
    {
        $channel = $this->_getChannel();
        $channel->basic_cancel($tag);
    }

    public function wait($timeout = 1)
    {
        $channel = $this->_getChannel();
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
