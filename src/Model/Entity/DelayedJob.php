<?php
namespace DelayedJobs\Model\Entity;

use Cake\ORM\Entity;
use Cake\Core\Exception\Exception;

class DelayedJob extends Entity
{

    protected function _getStream($stream, $property = null)
    {
        if (is_resource($stream)) {
            $stream = stream_get_contents($stream);
            if ($property) {
                $this->{$property} = $stream;
            }
        }
        return $stream;
    }

    /**
     * @param $options Options.
     * @return string
     */
    protected function _getOptions($options)
    {
        return $this->_getStream($options, 'options');
    }

    protected function _getPayload($payload)
    {
        return $this->_getStream($payload, 'payload');
    }

    public function execute()
    {
        $class_name = $this->class;
        if (!class_exists($class_name)) {
            throw new Exception("Worker class does not exists (" . $class_name . ")");
        }

        $job_worker = new $class_name();

        $method = $this->method;
        if (!method_exists($job_worker, $method)) {
            throw new Exception(
                "Method does not exists ({$class_name}::{$method})"
            );
        }

        $payload = unserialize($this->payload);

        return $job_worker->{$method}($payload);
    }
}