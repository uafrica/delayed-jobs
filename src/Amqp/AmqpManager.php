<?php

namespace DelayedJobs\Amqp;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\Network\Http\Client;
use DelayedJobs\Model\Entity\DelayedJob;
use DelayedJobs\Traits\DebugTrait;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPAbstractCollection;
use PhpAmqpLib\Wire\AMQPTable;
use ProcessMQ\Queue;

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
     * @var \ProcessMQ\Connection\RabbitMQConnection
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
     */
    public function __construct()
    {
        $this->_connection = ConnectionManager::get('rabbit');
        $this->_serviceName = Configure::read('dj.service.name');
    }

    public function __destroy()
    {
        if ($this->_connection && $this->_connection->connection()->isConnected()) {
            $this->_connection->connection()->disconnect();
        }
    }

    protected function _getChannel($prefectCount = 1)
    {
        if ($this->_channel) {
            return $this->_channel;
        }

        $this->_connection->channel($this->_serviceName, [
            'prefetchCount' => $prefectCount
        ]);

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

    protected function _ensureQueues()
    {
        $this->_connection
            ->exchange($this->_serviceName . '-direct-exchange', [
                'type' => 'direct',
                'flags' => AMQP_DURABLE
            ])
            ->declareExchange();
        $this->_connection
            ->exchange($this->_serviceName . '-delayed-exchange', [
                'type' => 'x-delayed-message',
                'flags' => AMQP_DURABLE,
                'arguments' => [
                    'x-delayed-type' => 'direct'
                ]
            ])
            ->declareExchange();

        $queue = $this->_connection
            ->queue($this->_serviceName . '-queue', [
                'flags' => AMQP_DURABLE,
                'arguments' => [
                    'x-max-priority' => Configure::read('dj.service.rabbit.max_priority')
                ]
            ]);
        $queue->bind($this->_serviceName . '-direct-exchange', $this->_serviceName);
        $queue->bind($this->_serviceName . '-delayed-exchange', $this->_serviceName);
        //$queue->declareQueue();
    }

    public function queueJob(DelayedJob $job)
    {
        $delay = $job->run_at->isFuture() ? (new Time())->diffInSeconds($job->run_at, false) * 1000 : 0;

        $options = [
            'compress' => false,
            'serializer' => 'json',
            'delivery_mode' => AMQP_DURABLE,
            'attributes' => [
                'priority' => Configure::read('dj.service.rabbit.max_priority') - $job->priority,
            ]
        ];
        if ($delay > 0) {
            $options['attributes']['headers'] = [
                'x-delay' => $delay
            ];
        }

        $data = [
            'id' => $job->id
        ];

        $publisher = ($delay > 0 ? 'delayed' : 'direct');

        Queue::publish($publisher, $data, $options);
        $this->dj_log(__('Job {0} has been queued to {1} with routing key {2}, a delay of {3} and a priority of {4}',
            $job->id, $publisher, $this->_serviceName, $delay, $options['attributes']['priority']));
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

    public function wait()
    {
        $channel = $this->_getChannel();
        while (count($channel->callbacks)) {
            $channel->wait();
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
        $queue_data = $client->get(sprintf('/api/queues/%s/%s', urlencode($config['path']),
            Configure::read('dj.service.name') . '-queue'), [], [
            'type' => 'json'
        ]);
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

    public function disconnect()
    {
        $this->_channel->close();
        $this->_connection->close();
    }
}
