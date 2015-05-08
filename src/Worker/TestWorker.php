<?php

namespace DelayedJobs\Worker;

use Cake\I18n\Time;

/**
 * Class TestWorker
 */
class TestWorker extends BaseWorker
{

    /**
     * @param array $payload Payload
     * @return string
     * @throws \Exception
     */
    public function test($payload)
    {
        sleep(rand(1, 10));
        $time = (new Time())->i18nFormat();
        if ($payload['type'] === 'success') {
            return 'Successfull test at ' . $time;
        } else {
            throw new \Exception('Failing test at ' . $time . ' because ' . $payload['type']);
        }
    }
}
