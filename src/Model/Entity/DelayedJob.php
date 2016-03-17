<?php
namespace DelayedJobs\Model\Entity;

use Cake\Console\Shell;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Log\Log;
use Cake\ORM\Entity;
use Cake\Core\Exception\Exception;
use DelayedJobs\Amqp\AmqpManager;
use DelayedJobs\Model\Table\DelayedJobsTable;

/**
 * Class DelayedJob
 *
 * @property \Cake\I18n\Time $run_at
 */
class DelayedJob extends Entity implements EventDispatcherInterface
{
    use EventDispatcherTrait;

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

    public function queue()
    {
        if (Configure::read('dj.service.rabbit.disable') === true) {
            return;
        }

        try {
            $event = $this->dispatchEvent('DelayedJobs.beforeJobQueue', [$this]);
            if ($event->isStopped()) {
                return $event->result;
            }

            $manager = AmqpManager::instance();
            $message = $manager->queueJob($this);

            $this->dispatchEvent('DelayedJobs.afterJobQueue', [$this, $message]);

            return true;
        } catch (\Exception $e) {
            Log::emergency(__('RabbitMQ server is down. Response was: {0} with exception {1}. Job #{2} has not been queued.',
                $e->getMessage(), get_class($e), $this->id));

            return false;
        }
    }
}
