<?php

namespace DelayedJobs\Model\Entity;

use Cake\ORM\Entity;

/**
 * Class Worker
 *
 * @property int $id
 * @property string $host_name
 * @property string $worker_name
 * @property int $pid
 * @property \Cake\I18n\Time $created
 * @property \Cake\I18n\Time $modified
 * @property int $status
 * @property \Cake\I18n\Time $pulse
 * @property int $job_count
 */
class Worker extends Entity
{
}
