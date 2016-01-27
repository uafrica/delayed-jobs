<?php

namespace DelayedJobs\DelayedJobs;

use Cake\Core\App;
use DelayedJobs\DelayedJobs\Exception\JobDataException;

/**
 * Class Job
 */
class Job
{

    protected $_class;
    protected $_method;
    protected $_group;
    protected $_priority = 100;
    protected $_payload = [];
    protected $_options = [];
    protected $_sequence;
    protected $_runAt;

    public function getData()
    {
        return [
            'class' => $this->getClass(),
            'method' => $this->getMethod(),
            'group' => $this->getGroup(),
            'priority' => $this->getPriority(),
            'payload' => $this->getPayload(),
            'options' => $this->getOptions(),
            'sequence' => $this->getSequence(),
            'run_at' => $this->getRunAt(),
        ];
    }

    /**
     * @param array $data
     * @return \DelayedJobs\DelayedJobs\Job
     * @throws \DelayedJobs\DelayedJobs\Exception\JobDataException
     */
    public function setData(array $data)
    {
        if (isset($data['class'])) {
            $this->setClass($data['class']);
        }
        if (isset($data['method'])) {
            $this->setMethod($data['method']);
        }
        if (isset($data['group'])) {
            $this->setGroup($data['group']);
        }
        if (isset($data['priority'])) {
            $this->setPriority($data['priority']);
        }
        if (isset($data['payload'])) {
            $this->setPayload($data['payload']);
        }
        if (isset($data['options'])) {
            $this->setOptions($data['options']);
        }
        if (isset($data['sequence'])) {
            $this->setSequence($data['sequence']);
        }
        if (isset($data['run_at'])) {
            $this->setRunAt($data['run_at']);
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
     * @return Job
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
    public function getMethod()
    {
        return $this->_method;
    }

    /**
     * @param string $method
     * @return Job
     */
    public function setMethod($method)
    {
        $this->_method = $method;

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
     * @return Job
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
     * @return Job
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
     * @param array $payload
     * @return Job
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
     * @return Job
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
     * @return Job
     */
    public function setSequence($sequence = null)
    {
        $this->_sequence = $sequence;

        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getRunAt()
    {
        if ($this->_run_at === null) {
            $this->_run_at = new \DateTime();
        }

        return $this->_run_at;
    }

    /**
     * @param \DateTime $run_at
     * @return Job
     */
    public function setRunAt(\DateTime $run_at = null)
    {
        $this->_run_at = $run_at;

        return $this;
    }


}
