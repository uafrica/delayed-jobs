<?php
declare(strict_types=1);

namespace DelayedJobs\Result;

use Cake\Chronos\ChronosInterface;
use Cake\Core\App;
use InvalidArgumentException;

/**
 * Class Result
 */
abstract class Result implements ResultInterface
{
    public const TYPE_FAILED = Failed::class;
    public const TYPE_SUCCESS = Success::class;
    public const TYPE_PAUSE = Pause::class;

    /**
     * @var string
     */
    private $_message;
    /**
     * @var \Cake\Chronos\ChronosInterface|null
     */
    private $_nextRun;
    /**
     * @var bool
     */
    private $_retry = true;

    /**
     * Result constructor.
     *
     * @param string $message The message
     */
    public function __construct($message = '')
    {
        $this->_message = $message;
    }

    /**
     * @param string $message The message
     * @param string $class Class name to use (Either a FQCN, or a Cake style class)
     *
     * @return static
     */
    public static function create($message = '', ?string $class = null): ResultInterface
    {
        if ($class) {
            $className = App::className($class, 'Result');
            $result = new $className($message);
        } else {
            $result = new static($message);
        }

        if (!$result instanceof ResultInterface) {
            throw new InvalidArgumentException(
                sprintf('Class "%s" is not a valid %s instance.', $class, ResultInterface::class)
            );
        }

        return $result;
    }

    /**
     * @param string $message The message
     * @return self
     */
    public function setMessage(string $message = ''): self
    {
        $this->_message = $message;

        return $this;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->_message ?: '';
    }

    /**
     * @return \Cake\Chronos\ChronosInterface|null
     */
    public function getNextRun(): ?ChronosInterface
    {
        return $this->_nextRun;
    }

    /**
     * @param \Cake\Chronos\ChronosInterface|null $recur When to re-queue the job for.
     * @return static
     */
    public function setNextRun(?ChronosInterface $recur): self
    {
        $this->_nextRun = $recur;

        return $this;
    }

    /**
     * @return bool
     */
    public function getRetry(): bool
    {
        return $this->_retry ? true : false;
    }

    /**
     * @return self
     */
    public function willRetry(): self
    {
        $this->_retry = true;

        return $this;
    }

    /**
     * @return self
     */
    public function wontRetry(): self
    {
        $this->_retry = false;

        return $this;
    }
}
