<?php
declare(strict_types=1);

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
    public const SHUTDOWN_SUICIDE = 'suicide';
    public const SHUTDOWN_STATUS = 'asked nicely by status';
    public const SHUTDOWN_LOOP_EXIT = 'loop exited';
    public const SHUTDOWN_NO_WORKER = 'no worker';
    public const SHUTDOWN_WRONG_PID = 'wrong pid';
    public const SHUTDOWN_ERROR = 'an error occured';
    public const SHUTDOWN_MANAGER = ' asked nicely by the manager';
}
