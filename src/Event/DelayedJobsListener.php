<?php

namespace DelayedJobs\Event;

use Cake\Core\Exception\Exception;
use Cake\Datasource\ModelAwareTrait;
use Cake\Event\Event;
use Cake\Event\EventListenerInterface;
use Cake\I18n\Time;
use DelayedJobs\Model\Table\DelayedJobsTable;

/**
 * Class DelayedJobsListener
 *
 * @package DelayedJobs\Event
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class DelayedJobsListener implements EventListenerInterface
{

    use ModelAwareTrait;

    /**
     * Constructor for class
     */
    public function __construct()
    {
        $this->modelFactory('Table', ['Cake\ORM\TableRegistry', 'get']);
    }

    /**
     * Events that this listener listens too
     *
     * @return array
     */
    public function implementedEvents()
    {
        return [
            'DelayedJob.queue' => 'queueJob'
        ];
    }

    /**
     * @param \Cake\Event\Event $event Event object.
     * @return \Cake\Datasource\EntityInterface|\Cake\ORM\Entity
     */
    public function queueJob(Event $event)
    {
        $this->loadModel('DelayedJobs.DelayedJobs');

        $default = [
            'group' => null,
            'payload' => [],
            'options' => [],
            'priority' => 100,
            'sequence' => null,
            'run_at' => new Time('+5 seconds')
        ];
        $data = $event->subject();

        if (!is_array($data)) {
            $data = $event->data();
        }
        $data = $data + $default;

        if (!isset($data["class"])) {
            throw new Exception("No Class Specified");
        }

        if (!isset($data["method"])) {
            throw new Exception("No Method Specified");
        }

        $entity = $this->DelayedJobs->newEntity($data);
        $entity->status = DelayedJobsTable::STATUS_NEW;

        $result = $this->DelayedJobs->save($entity);

        if ($result) {
            return $entity;
        } else {
            $event->stopPropagation();
            throw new Exception("Could not create job");
        }
    }
}