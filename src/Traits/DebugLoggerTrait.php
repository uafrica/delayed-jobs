<?php

namespace DelayedJobs\Traits;

use Cake\Core\Configure;
use Cake\Log\Log;

trait DebugLoggerTrait
{

    public function djLog($message)
    {
        if (Configure::read('debug')) {
            Log::debug($message);
        }
    }
}
