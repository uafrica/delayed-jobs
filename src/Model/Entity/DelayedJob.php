<?php
namespace DelayedJobs\Model\Entity;

use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\ORM\Entity;

/**
 * Class DelayedJob
 *
 * @property \Cake\I18n\Time $run_at
 *
 * @internal
 */
class DelayedJob extends Entity implements EventDispatcherInterface
{
    use EventDispatcherTrait;

    /**
     * @param $stream
     * @param null $property
     * @return bool|string
     */
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

    /**
     * @param $payload
     * @return bool|string
     */
    protected function _getPayload($payload)
    {
        return $this->_getStream($payload, 'payload');
    }
}
