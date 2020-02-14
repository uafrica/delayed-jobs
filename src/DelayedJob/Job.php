<?php
declare(strict_types=1);

namespace DelayedJobs\DelayedJob;

use Cake\Chronos\ChronosInterface;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\I18n\FrozenTime;
use Cake\I18n\Time;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use DelayedJobs\DelayedJob\Exception\JobDataException;
use InvalidArgumentException;
use Throwable;

/**
 * Class Job
 */
class Job
{
    public const STATUS_NEW = 1;
    public const STATUS_BUSY = 2;
    public const STATUS_BURIED = 3;
    public const STATUS_SUCCESS = 4;
    public const STATUS_KICK = 5;
    public const STATUS_FAILED = 6;
    public const STATUS_UNKNOWN = 7;
    public const STATUS_TEST_JOB = 8;
    public const STATUS_PAUSED = 9;
    public const MAX_PRIORITY = 255;

    /**
     * @var string
     */
    protected $_worker;
    /**
     * @var string
     */
    protected $_group;
    /**
     * @var int
     */
    protected $_priority = 100;
    /**
     * @var array
     */
    protected $_payload = [];
    /**
     * @var array
     */
    protected $_options = [];
    /**
     * @var string
     */
    protected $_sequence;
    /**
     * @var \Cake\I18n\Time
     */
    protected $_runAt;
    /**
     * @var int
     */
    protected $_id;
    /**
     * @var int
     */
    protected $_status = self::STATUS_NEW;
    /**
     * @var int
     */
    protected $_maxRetries = 5;
    /**
     * @var int
     */
    protected $_retries = 0;
    /**
     * @var \Cake\I18n\Time
     */
    protected $_timeFailed;
    /**
     * @var string
     */
    protected $_lastMessage;
    /**
     * @var \Cake\I18n\Time
     */
    protected $_startTime;
    /**
     * @var \Cake\I18n\Time
     */
    protected $_endTime;
    /**
     * @var int
     */
    protected $_duration;
    /**
     * @var string
     */
    protected $_hostName;
    /**
     * @var array
     */
    protected $_history = [];
    /**
     * Internal storage for the broker message object.
     *
     * @var object
     */
    protected $_brokerMessage;
    /**
     * @var \Cake\Datasource\EntityInterface|null
     */
    protected $_baseEntity;
    /**
     * Storage for the body/payload of the message from the broker
     *
     * @var mixed
     */
    protected $_brokerMessageBody;
    /**
     * Indicates that this job is being executed manually
     *
     * @var bool
     */
    protected $_manualRun = false;
    /**
     * Indicates that this job instance was pushed to the job broker
     *
     * @var bool
     */
    protected $_pushedToBroker = false;

    /**
     * Job constructor.
     *
     * @param array|\Cake\Datasource\EntityInterface|null $data Data to populate with
     */
    public function __construct($data = null)
    {
        if ($data === null) {
            return;
        }

        if (is_array($data)) {
            $this->setData($data);
        } elseif ($data instanceof EntityInterface) {
            $this->setDataFromEntity($data);
        } else {
            throw new InvalidArgumentException('$data is not an array or instance of ' . EntityInterface::class);
        }
    }

    /**
     * @return void
     */
    public function __clone()
    {
        $this->setData([
            'status' => self::STATUS_NEW,
            'retries' => 0,
            'lastMessage' => null,
            'failedAt' => null,
            'lockedBy' => null,
            'startTime' => null,
            'endTime' => null,
            'duration' => null,
            'id' => null,
            'history' => [],
            'entity' => null,
        ]);
    }

    /**
     * @return bool
     */
    public function isManualRun(): bool
    {
        return $this->_manualRun;
    }

    /**
     * @param bool $manualRun Is this job being manually run?
     * @return self
     */
    public function setManualRun(bool $manualRun): self
    {
        $this->_manualRun = $manualRun;

        return $this;
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return [
            'id' => $this->getId(),
            'worker' => $this->getWorker(),
            'group' => $this->getGroup(),
            'priority' => $this->getPriority(),
            'payload' => $this->getPayload(),
            'options' => $this->getOptions(),
            'sequence' => $this->getSequence(),
            'run_at' => $this->getRunAt(),
            'status' => $this->getStatus(),
            'failed_at' => $this->getTimeFailed(),
            'last_message' => $this->getLastMessage(),
            'start_time' => $this->getStartTime(),
            'end_time' => $this->getEndTime(),
            'duration' => $this->getDuration(),
            'max_retries' => $this->getMaxRetries(),
            'retries' => $this->getRetries(),
            'history' => $this->getHistory(),
            'host_name' => $this->getHostName(),
        ];
    }

    /**
     * @param array $data Array of data
     * @return self
     * @throws \DelayedJobs\DelayedJob\Exception\JobDataException
     */
    public function setData(array $data): self
    {
        foreach ($data as $key => $value) {
            $method = 'set' . Inflector::camelize($key);
            if (method_exists($this, $method)) {
                $this->{$method}($value);
            }
        }

        return $this;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity An entity to get the data from
     * @return self
     */
    public function setDataFromEntity(EntityInterface $entity): self
    {
        $this->setData($entity->toArray());
        $this->setEntity($entity);

        return $this;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity that this job was created from
     * @return self
     */
    public function setEntity(?EntityInterface $entity = null): self
    {
        $this->_baseEntity = $entity;

        return $this;
    }

    /**
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function getEntity(): ?EntityInterface
    {
        return $this->_baseEntity;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->_id;
    }

    /**
     * @param int $id ID for this job
     * @return self
     */
    public function setId($id): self
    {
        $this->_id = $id;

        return $this;
    }

    /**
     * @return int
     */
    public function getRetries(): int
    {
        return $this->_retries ?? 0;
    }

    /**
     * @param int $retries Number of retries this job has done
     *
     * @return self
     */
    public function setRetries($retries): self
    {
        $this->_retries = $retries;

        return $this;
    }

    /**
     * @return self
     */
    public function incrementRetries(): self
    {
        $this->_retries++;

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->_maxRetries ?? Configure::read('DelayedJobs.default.maxRetries');
    }

    /**
     * @param int $maxRetries Max retries
     * @return self
     */
    public function setMaxRetries($maxRetries): self
    {
        $this->_maxRetries = min($maxRetries, Configure::read('DelayedJobs.maximum.maxRetries'));

        return $this;
    }

    /**
     * @return string
     */
    public function getWorker(): string
    {
        return $this->_worker;
    }

    /**
     * @param string $worker Class name
     * @return self
     * @throws \DelayedJobs\DelayedJob\Exception\JobDataException
     */
    public function setWorker($worker): self
    {
        $className = App::className($worker, 'Worker', 'Worker');

        if (!$className) {
            throw new JobDataException(sprintf('Worker name %s is not a valid Worker class', $worker));
        }

        $this->_worker = $worker;

        return $this;
    }

    /**
     * @return string
     */
    public function getGroup(): string
    {
        if (!empty($this->_group)) {
            return $this->_group;
        }

        return $this->_worker;
    }

    /**
     * @param string $group What group does this job
     * @return self
     */
    public function setGroup($group): self
    {
        $this->_group = $group;

        return $this;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->_priority ?? 1;
    }

    /**
     * @param int $priority Job priority
     * @return self
     */
    public function setPriority($priority): self
    {
        if ($priority > self::MAX_PRIORITY) {
            $priority = self::MAX_PRIORITY;
        } elseif ($priority < 0) {
            $priority = 0;
        }
        $this->_priority = $priority;

        return $this;
    }

    /**
     * @param string $key Hash get compatible key (or null for entire payload)
     * @param mixed $default The default value to use
     * @return mixed
     */
    public function getPayload($key = null, $default = null)
    {
        if ($key === null) {
            return $this->_payload;
        }

        return Hash::get($this->_payload, $key, $default);
    }

    /**
     * @param array $payload Payload array
     * @param bool $defaults Use as defaults
     * @return self
     */
    public function setPayload(array $payload, bool $defaults = false): self
    {
        if ($defaults === false) {
            $this->_payload = $payload;
        } else {
            $this->_payload += $payload;
        }

        return $this;
    }

    /**
     * @param string $key The key to use
     * @param mixed $value The value
     * @param bool $overwrite Overwrite the value. If false they will be merged
     * @return self
     */
    public function setPayloadKey(string $key, $value, bool $overwrite = true): self
    {
        if (!$overwrite && isset($this->_payload[$key])) {
            $this->_payload[$key] = Hash::merge((array)$this->_payload[$key], (array)$value);

            return $this;
        }

        $this->_payload = Hash::insert($this->_payload, $key, $value);

        return $this;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->_options;
    }

    /**
     * @param array $options Array of options for the job
     * @return self
     */
    public function setOptions($options): self
    {
        $this->_options = $options;

        return $this;
    }

    /**
     * @param string $option The option name
     * @param  mixed $value The value
     * @return self
     */
    public function setOption($option, $value): self
    {
        $this->_options[$option] = $value;

        return $this;
    }

    /**
     * @param string $option Dot separated path
     * @param mixed $default Default value to use
     * @return mixed
     */
    public function getOption(string $option, $default = null)
    {
        return Hash::get($this->_options, $option, $default);
    }

    /**
     * @return string|null
     */
    public function getSequence(): ?string
    {
        return $this->_sequence;
    }

    /**
     * @param string $sequence Sequence for the job
     * @return self
     */
    public function setSequence(?string $sequence = null): self
    {
        $this->_sequence = $sequence;

        return $this;
    }

    /**
     * @return \Cake\I18n\Time
     */
    public function getRunAt(): Time
    {
        if ($this->_runAt === null) {
            $this->_runAt = new Time();
        }

        return $this->_runAt;
    }

    /**
     * @param \Cake\Chronos\ChronosInterface|null $runAt Time for the job to run
     * @return self
     */
    public function setRunAt(?ChronosInterface $runAt = null): self
    {
        $this->_runAt = $runAt;

        return $this;
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->_status ?? self::STATUS_UNKNOWN;
    }

    /**
     * @param int $status Job status
     * @return self
     */
    public function setStatus(int $status): self
    {
        $this->_status = $status;

        return $this;
    }

    /**
     * @return \Cake\I18n\Time|null
     */
    public function getTimeFailed(): ?Time
    {
        return $this->_timeFailed;
    }

    /**
     * @param \Cake\I18n\Time $timeFailed The time that the job failed
     * @return self
     */
    public function setTimeFailed(?Time $timeFailed = null): self
    {
        $this->_timeFailed = $timeFailed;

        return $this;
    }

    /**
     * @return string
     */
    public function getLastMessage(): string
    {
        return $this->_lastMessage ?? '';
    }

    /**
     * @param string|\Throwable $lastMessage Last message
     * @return self
     */
    public function setLastMessage($lastMessage): self
    {
        if ($lastMessage instanceof Throwable) {
            $lastMessage = $lastMessage->getMessage();
        }

        $this->_lastMessage = $lastMessage;

        return $this;
    }

    /**
     * @return \Cake\I18n\Time|null
     */
    public function getStartTime(): ?Time
    {
        return $this->_startTime;
    }

    /**
     * @param \Cake\I18n\Time $startTime Time the job started running
     * @return self
     */
    public function setStartTime(?Time $startTime = null): self
    {
        $this->_startTime = $startTime;

        return $this;
    }

    /**
     * @return \Cake\I18n\Time|null
     */
    public function getEndTime(): ?Time
    {
        return $this->_endTime;
    }

    /**
     * @param \Cake\I18n\Time|null $endTime Time the job stopped running
     * @return self
     */
    public function setEndTime(?Time $endTime = null): self
    {
        $this->_endTime = $endTime;

        return $this;
    }

    /**
     * @return int
     */
    public function getDuration(): int
    {
        return $this->_duration ?? 0;
    }

    /**
     * @param int $duration Job duration in microseconds
     * @return self
     */
    public function setDuration($duration): self
    {
        $this->_duration = $duration;

        return $this;
    }

    /**
     * @return string
     */
    public function getHostName(): string
    {
        return $this->_hostName ?? '';
    }

    /**
     * @param string $hostName The host running the job
     * @return self
     */
    public function setHostName($hostName): self
    {
        $this->_hostName = $hostName;

        return $this;
    }

    /**
     * @return array
     */
    public function getHistory(): array
    {
        return (array)$this->_history;
    }

    /**
     * @param array $history Array of job history
     * @return self
     */
    public function setHistory(array $history): self
    {
        $this->_history = $history;

        return $this;
    }

    /**
     * Adds a history item
     *
     * @param string|\Throwable $message The message
     * @param array $context Extra contextual information
     * @param bool $changeMessage Should the last message be updated
     * @return self
     */
    public function addHistory($message = '', array $context = [], bool $changeMessage = true): self
    {
        if ($message instanceof Throwable) {
            $message = $message->getMessage();
        }

        $this->_history[] = [
            'timestamp' => new FrozenTime(),
            'microtime' => microtime(true),
            'host_name' => $this->getHostName(),
            'message' => $message ?: '',
            'status' => $this->getStatus(),
            'context' => $context,
        ];

        if (is_string($message) && $changeMessage) {
            $this->setLastMessage($message);
        }

        return $this;
    }

    /**
     * @return object
     */
    public function getBrokerMessage()
    {
        return $this->_brokerMessage;
    }

    /**
     * @param object $brokerMessage The broker message
     * @return self
     */
    public function setBrokerMessage($brokerMessage): self
    {
        $this->_brokerMessage = $brokerMessage;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getBrokerMessageBody()
    {
        return $this->_brokerMessageBody;
    }

    /**
     * @param mixed $brokerMessageBody The body of the message (We don't know the type)
     * @return self
     */
    public function setBrokerMessageBody($brokerMessageBody): self
    {
        $this->_brokerMessageBody = $brokerMessageBody;

        return $this;
    }

    /**
     * @return bool
     */
    public function isPushedToBroker(): bool
    {
        return $this->_pushedToBroker;
    }

    /**
     * @param bool $pushedToBroker Has the job been pushed to the broker
     * @return self
     */
    public function setPushedToBroker(bool $pushedToBroker): self
    {
        $this->_pushedToBroker = $pushedToBroker;

        return $this;
    }

    /**
     * Check if max retries for the job has been reached.
     *
     * @return bool
     */
    public function maxRetriesReached(): bool
    {
        $jobRetries = $this->getRetries() + 1;
        $maxRetries = $this->getMaxRetries();

        return $jobRetries >= $maxRetries;
    }
}
