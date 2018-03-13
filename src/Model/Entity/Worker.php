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
 * @property int $memory_usage
 * @property int $idle_time
 * @property string $shutdown_reason
 * @property \Cake\I18n\Time $shutdown_time
 */
class Worker extends Entity
{
    const SHUTDOWN_SUICIDE = 'suicide';
    const SHUTDOWN_STATUS = 'asked nicely by status';
    const SHUTDOWN_LOOP_EXIT = 'loop exited';
    const SHUTDOWN_NO_WORKER = 'no worker';
    const SHUTDOWN_WRONG_PID = 'wrong pid';
    const SHUTDOWN_ERROR = 'an error occured';
}
