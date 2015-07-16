<?php
namespace DelayedJobs\Model\Entity;

use Cake\ORM\Entity;
use Cake\Core\Exception\Exception;
use DelayedJobs\Model\Table\DelayedJobsTable;

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

        return $job_worker->{$method}($this->payload, $this);
    }

    /**
     * When an object is cloned, PHP 5 will perform a shallow copy of all of the object's properties.
     * Any properties that are references to other variables, will remain references.
     * Once the cloning is complete, if a __clone() method is defined,
     * then the newly created object's __clone() method will be called, to allow any necessary properties that need to
     * be changed. NOT CALLABLE DIRECTLY.
     *
     * @return mixed
     * @link http://php.net/manual/en/language.oop5.cloning.php
     */
    function __clone()
    {
        $this->isNew(true);
        unset($this->id);
        unset($this->created);
        unset($this->modified);
        $this->status = DelayedJobsTable::STATUS_NEW;
        $this->retries = 0;
        $this->last_message = null;
        $this->failed_at = null;
        $this->locked_by = null;
    }

}
