<?php
namespace DelayedJobs\Model\Entity;

use Cake\ORM\Entity;

class DelayedJob extends Entity
{

    protected function _getStream($stream, $property = null) {
        if (is_resource($stream)) {
            $stream = stream_get_contents($stream);
            if ($property) {
                $this->{$property} = $stream;
            }
        }
        return $stream;
    }

    protected function _getOptions($options) {
        return $this->_getStream($options, 'options');
    }

    protected function _getPayload($payload)
    {
        return $this->_getStream($payload, 'payload');
    }
}