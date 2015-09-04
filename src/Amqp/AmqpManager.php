<?php

namespace DelayedJobs\Amqp;

use Cake\Core\Configure;
use Cake\I18n\Time;
use DelayedJobs\Model\Entity\DelayedJob;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPAbstractCollection;
use PhpAmqpLib\Wire\AMQPTable;

class AmqpManager
{
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
        if ($connection === null) {
            $config = Configure::read('dj.service.rabbit.server');
            $connection = new AMQPLazyConnection($config['host'], $config['port'], $config['user'], $config['pass']);
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
        return $this->_channel;
    }

    protected function _ensureQueue(AMQPChannel $channel)
    {
        $channel->exchange_declare($this->_serviceName . '_direct_exchange', 'direct', false, true, false, false, false);
        $channel->exchange_declare($this->_serviceName . '_delayed_exchange', 'x-delayed-message', false, true, false, false, false, [
            'x-delayed-type' => [
                'S',
                'direct'
            ]
        ]);
        $channel->queue_declare($this->_serviceName . '_queue', false, true, false, false, false, [
            'x-max-priority' => [
                's',
                Configure::read('dj.service.rabbit.max_priority')
            ]
        ]);

        $channel->queue_bind($this->_serviceName . '_queue', $this->_serviceName . '_delayed_exchange', 'route');
        $channel->queue_bind($this->_serviceName . '_queue', $this->_serviceName . '_direct_exchange', 'route');
    }

    public function queueJob(DelayedJob $job)
    {
        $channel = $this->_getChannel();
        $delay = (new Time())->diffInSeconds($job->run_at, false) * 1000;

        $args = [
            'delivery_mode' => 2,
            'priority' => Configure::read('dj.service.rabbit.max_priority') - $job->priority,
        ];

        $message = new AMQPMessage($job->id, $args);

        if ($delay > 0) {
            $headers = new AMQPTable();
            $headers->set('x-delay', $delay);
            $message->set('application_headers', $headers);
        }

        $exchange = $this->_serviceName . ($delay > 0 ? '_delayed_exchange' : '_direct_exchange');
        $channel->basic_publish($message, $exchange, 'route');
    }

    public function listen($callback, $qos = 1)
    {
        $channel = $this->_getChannel();
        $channel->basic_qos(null, $qos, null);
        $channel->basic_consume($this->_serviceName . '_queue', '', false, false, false, false, $callback);
    }

    public function wait($timeout = 1)
    {
        $channel = $this->_getChannel();
        try {
            while (count($channel->callbacks)) {
                $channel->wait(null, false, $timeout);
            }
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
}