<?php

namespace DelayedJobs\Traits;

use Cake\Core\Configure;
use Cake\Log\Log;

trait DebugTrait
{

    public function dj_log($message)
    {
        if (Configure::read('dj.debug')) {
            Log::debug($message);
        }
    }
}