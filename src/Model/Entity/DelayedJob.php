<?php
declare(strict_types=1);

namespace DelayedJobs\Model\Entity;

use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\ORM\Entity;

/**
 * Class DelayedJob
 *
 * @property int $status
 * @property \Cake\I18n\Time $run_at
 *
 * @internal
 */
class DelayedJob extends Entity implements EventDispatcherInterface
{
    use EventDispatcherTrait;

    /**
     * @param resource|string $stream Stream
     * @param string|null $property Property to set
     * @return null|array
     */
    protected function _getStream($stream, $property = null): ?array
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
     * @param resource|string $options Options.
     * @return null|array
     */
    protected function _getOptions($options): ?array
    {
        return $this->_getStream($options, 'options');
    }

    /**
     * @param resource|string $payload Payload
     * @return null|array
     */
    protected function _getPayload($payload): ?array
    {
        return $this->_getStream($payload, 'payload');
    }
}
