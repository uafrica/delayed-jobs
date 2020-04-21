<?php
declare(strict_types=1);

namespace DelayedJobs\Broker\Driver;

use Cake\Core\InstanceConfigTrait;
use Cake\Core\Retry\CommandRetry;
use DelayedJobs\Broker\Driver\Retry\PhpAmqpLibReconnectStrategy;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\DelayedJob\ManagerInterface;
use DelayedJobs\Traits\DebugLoggerTrait;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPIOWaitException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Class PhpAmqpLibDriver
 */
class PhpAmqpLibDriver implements RabbitMqDriverInterface
{
    use DebugLoggerTrait;
    use InstanceConfigTrait;

    /**
     * Connection timeout in seconds
     */
    public const CONNECTION_TIMEOUT = 10;
    /**
     * Read/write timeout (in seconds)
     *
     * Must be at least double heartbeat
     */
    public const READ_WRITE_TIMEOUT = 620;
    /**
     * Heartbeat in seconds
     */
    public const HEARTBEAT = 300;

    /**
     * @var \PhpAmqpLib\Connection\AbstractConnection|null
     */
    protected $_connection;

    /**
     * @var \PhpAmqpLib\Channel\AMQPChannel|null
     */
    protected $_channel;

    /**
     * @var array
     */
    protected $_defaultConfig = [];

    /**
     * @var \DelayedJobs\DelayedJob\ManagerInterface
     */
    protected $_manager;
    /**
     * @var string
     */
    protected $_tag;

    /**
     * @var callable
     */
    protected $_consumeCallback;
    /**
     * @var callable
     */
    protected $_hearbeatCallback;

    /**
     * @param array $config Array of config
     * @param \DelayedJobs\DelayedJob\ManagerInterface $manager Manager
     * @param \PhpAmqpLib\Connection\AbstractConnection|null $connection Connection injection
     */
    public function __construct(array $config, ManagerInterface $manager, ?AbstractConnection $connection = null)
    {
        $this->setConfig($config);

        if ($connection) {
            $this->_connection = $connection;
        }

        $this->_manager = $manager;
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function __destruct()
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
    public function getConnection(): AbstractConnection
    {
        if ($this->_connection && $this->_connection->isConnected()) {
            return $this->_connection;
        }

        $config = $this->getConfig();
        $this->_connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['pass'],
            $config['path'],
            false,
            'AMQPLAIN',
            null,
            'en_US',
            self::CONNECTION_TIMEOUT,
            self::READ_WRITE_TIMEOUT,
            null,
            true,
            self::HEARTBEAT
        );

        return $this->_connection;
    }

    /**
     * @return \PhpAmqpLib\Channel\AMQPChannel
     */
    protected function getChannel(): AMQPChannel
    {
        if ($this->_channel) {
            return $this->_channel;
        }

        try {
            $this->_channel = $this->getConnection()->channel();
        } catch (Throwable $e) {
            //If something went wrong, catch it, disconnect and try again.
            unset($this->_connection);
            $this->_channel = $this->getConnection()->channel();
        }

        return $this->_channel;
    }

    /**
     * @return void
     */
    protected function declareExchange(): void
    {
        $prefix = $this->getConfig('prefix');
        $channel = $this->getChannel();

        $channel->exchange_declare(
            $prefix . 'direct-exchange',
            'direct',
            false,
            true,
            false,
            false,
            false
        );
        $channel->exchange_declare(
            $prefix . 'delayed-exchange',
            'x-delayed-message',
            false,
            true,
            false,
            false,
            false,
            [
                'x-delayed-type' => [
                    'S',
                    'direct',
                ],
            ]
        );
    }

    /**
     * @param int $maximumPriority Maximum priority allowed
     * @return void
     */
    public function declareQueue(int $maximumPriority): void
    {
        $prefix = $this->getConfig('prefix');
        $channel = $this->getChannel();

        $channel->queue_declare($prefix . 'queue', false, true, false, false, false, [
            'x-max-priority' => ['s', $maximumPriority],
        ]);
    }

    /**
     * @return void
     */
    protected function bind(): void
    {
        $prefix = $this->getConfig('prefix');
        $routingKey = $this->getConfig('routingKey');
        $channel = $this->getChannel();

        $channel->queue_bind($prefix . 'queue', $prefix . 'delayed-exchange', $routingKey);
        $channel->queue_bind($prefix . 'queue', $prefix . 'direct-exchange', $routingKey);
    }

    /**
     * @return \Cake\Core\Retry\CommandRetry
     */
    protected function getIoRetry(): CommandRetry
    {
        return new CommandRetry(new PhpAmqpLibReconnectStrategy($this), 2);
    }

    /**
     * @inheritDoc
     */
    public function publishJob(array $jobData): void
    {
        $prefix = $this->getConfig('prefix');
        $routingKey = $this->getConfig('routingKey');

        $messageProperties = [
            'delivery_mode' => 2,
            'priority' => $jobData['priority'],
        ];

        if ($jobData['delay'] > 0) {
            $headers = new AMQPTable();
            $headers->set('x-delay', $jobData['delay']);
            $messageProperties['application_headers'] = $headers;
        }

        $message = new AMQPMessage((string)json_encode($jobData['payload']), $messageProperties);

        $exchange = $prefix . ($jobData['delay'] > 0 ? 'delayed-exchange' : 'direct-exchange');

        $this->getIoRetry()->run(function () use ($message, $exchange, $routingKey) {
            $this->getChannel()->basic_publish($message, $exchange, $routingKey);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws \ErrorException
     */
    public function consume(callable $callback, callable $heartbeat): void
    {
        $prefix = $this->getConfig('prefix');
        $channel = $this->getChannel();
        $channel->basic_qos(0, (int)$this->getConfig('qos'), false);

        $this->declareExchange();
        $this->declareQueue($this->_manager->getMaximumPriority());
        $this->bind();

        $tag = $channel->basic_consume(
            $prefix . 'queue',
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($callback) {
                $body = json_decode($message->getBody(), true);

                $job = new Job();
                $job->setBrokerMessage($message);

                if (isset($message->get_properties()['correlation_id'])) {
                    $job->setId((int)$message->get_properties()['correlation_id'])
                        ->setBrokerMessageBody(
                            $body
                        ); //If we're using a correlation id, then the message body is something special, and should be recorded as such.
                } elseif (isset($body['id'])) {
                    $job->setId((int)$body['id']);
                }

                return $callback($job, $message->delivery_info['redelivered']);
            }
        );
        $this->_tag = $tag;

        $time = microtime(true);
        while (count($channel->callbacks)) { //@phpcs:ignore
            try {
                $channel->wait(null, false, static::CONNECTION_TIMEOUT);
            } catch (AMQPTimeoutException | AMQPIOWaitException $e) {
                $heartbeat();
            }

            if (microtime(true) - $time >= static::CONNECTION_TIMEOUT) {
                $heartbeat();
                $time = microtime(true);
            }
        }
    }

    /**
     * {@inheritDoc}}
     */
    public function stopConsuming(): void
    {
        $channel = $this->getChannel();
        $channel->basic_cancel($this->_tag);
    }

    /**
     * @param int $timeout Timeout to wait for
     * @return bool
     * @throws \ErrorException
     */
    protected function wait($timeout = 1): bool
    {
        $channel = $this->getChannel();
        try {
            $channel->wait(null, true, $timeout);

            return true;
        } catch (AMQPTimeoutException $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function acknowledge(Job $job): void
    {
        $message = $job->getBrokerMessage();

        if ($message === null || !$message instanceof AMQPMessage) {
            return;
        }

        $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
    }

    /**
     * @inheritDoc
     */
    public function negativeAcknowledge(Job $job, bool $requeue = false): void
    {
        $message = $job->getBrokerMessage();

        if ($message === null || !$message instanceof AMQPMessage) {
            return;
        }

        $message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, $requeue);
    }

    /**
     * @inheritDoc
     */
    public function publishBasic(
        string $body,
        $exchange = '',
        $routing_key = '',
        int $priority = 0,
        array $headers = []
    ): void {
        $channel = $this->getChannel();
        $messageHeaders = new AMQPTable($headers);
        $message = new AMQPMessage($body, [
            'priority' => $priority,
            'delivery_mode' => 2,
            'application_headers' => $messageHeaders,
        ]);
        $channel->basic_publish($message, $exchange, $routing_key);
    }
}
