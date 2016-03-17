<?php

namespace DelayedJobs\DelayedJobs;

use Cake\Core\App;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\I18n\Time;
use Cake\Utility\Inflector;
use DelayedJobs\DelayedJobs\Exception\JobDataException;
use DelayedJobs\Worker\JobWorkerInterface;

/**
 * Class Job
 */
class Job implements EventDispatcherInterface
{
    use EventDispatcherTrait;

    protected $_class;
    protected $_group;
    protected $_priority = 100;
    protected $_payload = [];
    protected $_options = [];
    protected $_sequence;
    protected $_runAt;
    protected $_id;
    protected $_status;

    /**
     * Job constructor.
     */
    public function __construct(array $data = null)
    {
        if ($data) {
            $this->setData($data);
        }
    }

    public function getData()
    {
        return [
            'id' => $this->getId(),
            'class' => $this->getClass(),
            'group' => $this->getGroup(),
            'priority' => $this->getPriority(),
            'payload' => $this->getPayload(),
            'options' => $this->getOptions(),
            'sequence' => $this->getSequence(),
            'run_at' => $this->getRunAt(),
            'status' => $this->getStatus()
        ];
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->_id;
    }

    /**
     * @param int $id
     * @return $this
     */
    public function setId($id)
    {
        $this->_id = $id;

        return $this;
    }

    /**
     * @param array $data
     * @return $this
     * @throws \DelayedJobs\DelayedJobs\Exception\JobDataException
     */
    public function setData(array $data)
    {
        foreach ($data as $key => $value) {
            $methodName = 'set' . Inflector::camelize($key);
            if (method_exists($this, $methodName) {
                $this->{$methodName}($value);
            }
        }

        return $this;
    }

    /**
     * @return mixed
     */
    public function getClass()
    {
        return $this->_class;
    }

    /**
     * @param string $class
     * @return $this
     */
    public function setClass($class)
    {
        $className = App::className($class, 'Worker', 'Worker');

        if (!$className) {
            throw new JobDataException(sprintf('Class name %s is not a valid Worker class', $class));
        }

        $this->_class = $class;

        return $this;
    }

    /**
     * @return string
     */
    public function getGroup()
    {
        return $this->_group;
    }

    /**
     * @param string $group
     * @return $this
     */
    public function setGroup($group)
    {
        $this->_group = $group;

        return $this;
    }

    /**
     * @return int
     */
    public function getPriority()
    {
        return $this->_priority;
    }

    /**
     * @param int $priority
     * @return $this
     */
    public function setPriority($priority)
    {
        $this->_priority = $priority;

        return $this;
    }

    /**
     * @return array
     */
    public function getPayload()
    {
        return $this->_payload;
    }

    /**
     * @param mixed $payload
     * @return $this
     */
    public function setPayload($payload)
    {
        $this->_payload = $payload;

        return $this;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->_options;
    }

    /**
     * @param array $options
     * @return $this
     */
    public function setOptions($options)
    {
        $this->_options = $options;

        return $this;
    }

    /**
     * @return string
     */
    public function getSequence()
    {
        return $this->_sequence;
    }

    /**
     * @param string $sequence
     * @return $this
     */
    public function setSequence($sequence = null)
    {
        $this->_sequence = $sequence;

        return $this;
    }

    /**
     * @return \Cake\I18n\Time
     */
    public function getRunAt()
    {
        if ($this->_runAt === null) {
            $this->_runAt = new Time();
        }

        return $this->_runAt;
    }

    /**
     * @param \Cake\I18n\Time $run_at
     * @return $this
     */
    public function setRunAt(Time $run_at = null)
    {
        $this->_runAt = $run_at;

        return $this;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->_status;
    }

    /**
     * @param int $status
     * @return $this
     */
    public function setStatus($status)
    {
        $this->_status = $status;

        return $this;
    }

    public function execute(Shell $shell = null)
    {
        $className = App::className($this->_class, 'Worker', 'Worker');

        if (!class_exists($className)) {
            throw new Exception("Worker does not exist (" . $className . ")");
        }

        $jobWorker = new $className();

        $method = $this->method;
        if (!$jobWorker instanceof JobWorkerInterface) {
            throw new Exception("Worker class '{$className}' does not follow the required 'JobWorkerInterface");
        }

        $event = $this->dispatchEvent('DelayedJobs.beforeJobExecute', [$this]);
        if ($event->isStopped()) {
            return $event->result;
        }

        $result = $jobWorker($this, $shell);

        $event = $this->dispatchEvent('DelayedJobs.afterJobExecute', [$this, $result]);

        return $event->result ? $event->result : $result;
    }
}
