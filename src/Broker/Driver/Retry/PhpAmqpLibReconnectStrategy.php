<?php
declare(strict_types=1);

namespace DelayedJobs\Broker\Driver\Retry;

use Cake\Core\Retry\RetryStrategyInterface;
use DelayedJobs\Broker\Driver\PhpAmqpLibDriver;
use Exception;
use PhpAmqpLib\Exception\AMQPIOException;

/**
 * Class PhpAmqpLibReconnectStrategy
 */
class PhpAmqpLibReconnectStrategy implements RetryStrategyInterface
{
    public const WAIT_BEFORE_RECONNECT_US = 1000000;

    /**
     * The connection to check for validity
     *
     * @var \DelayedJobs\Broker\Driver\PhpAmqpLibDriver
     */
    protected $driver;

    /**
     * Creates the ReconnectStrategy object by storing a reference to the
     * passed connection. This reference will be used to automatically
     * reconnect to the server in case of failure.
     *
     * @param \Cake\Database\Connection $driver The connection to check
     */
    public function __construct(PhpAmqpLibDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Returns true if the action can be retried, false otherwise.
     *
     * @param \Exception $exception The exception that caused the action to fail
     * @param int $retryCount The number of times the action has been already called
     * @return bool Whether or not it is OK to retry the action
     */
    public function shouldRetry(Exception $exception, $retryCount): bool
    {
        if (!$exception instanceof AMQPIOException) {
            return false;
        }

        return $this->reconnect();
    }

    /**
     * @return bool
     */
    protected function reconnect(): bool
    {
        try {
            $connection = $this->driver->getConnection();
            $connection->reconnect();
            usleep(self::WAIT_BEFORE_RECONNECT_US);

            return $connection->isConnected();
        } catch (Exception $e) {
            return false;
        }
    }
}
