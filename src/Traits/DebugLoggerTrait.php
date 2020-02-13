<?php
declare(strict_types=1);

namespace DelayedJobs\Traits;

use Cake\Core\Configure;
use Cake\Log\Log;

/**
 * Trait DebugLoggerTrait
 *
 * @package DelayedJobs\Traits
 */
trait DebugLoggerTrait
{
    /**
     * @param $message
     * @return void
     */
    public function djLog($message)
    {
        if (Configure::read('debug')) {
            Log::debug($message);
        }
    }
}
