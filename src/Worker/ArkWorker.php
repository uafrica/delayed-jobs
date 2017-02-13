<?php

namespace DelayedJobs\Worker;

use Cake\Network\Http\Client;
use DelayedJobs\DelayedJob\EnqueueTrait;
use DelayedJobs\DelayedJob\Job;
use DelayedJobs\Traits\QueueJobTrait;

class ArkWorker
{
    use EnqueueTrait;

    protected function _bcfact($n)
    {
        return ($n == 0 || $n == 1) ? 1 : bcmul($n, $this->_bcfact($n - 1));
    }

    protected function _bcpi($precision)
    {
        $num = 0;
        $k = 0;
        bcscale($precision + 3);
        $limit = ($precision + 3) / 14;
        while ($k < $limit) {
            $num = bcadd($num,
                bcdiv(bcmul(bcadd('13591409', bcmul('545140134', $k)), bcmul(bcpow(-1, $k), $this->_bcfact(6 * $k))),
                    bcmul(bcmul(bcpow('640320', 3 * $k + 1), bcsqrt('640320')),
                        bcmul($this->_bcfact(3 * $k), bcpow($this->_bcfact($k), 3)))));
            ++$k;
        }

        return bcdiv(1, (bcmul(12, ($num))), $precision);
    }

    public function flood(Job $job)
    {
        $command = 'ps -p ' . $job->getPayload('pid');
        exec($command, $op);
        if (!isset($op[1])) {
            return 'Flood has been stopped';
        }

        $pi = $this->_bcpi(random_int(1, $job->getPayload('work')));
        //Fetch an api 50% of the time
        if (random_int(0, 1) === 0) {
            $client = new Client();
            $client->get('http://jsonplaceholder.typicode.com/posts');

            $pi .= ' - api';

            sleep(random_int(0, 30));
        }

        $number_forks = random_int($job->getPayload('first') ? 1 : 0, $job->getPayload('max_fork'));
        $payload['first'] = false;
        $sequence = random_int(0, 9) > 0 ? null : 'ark_' . $job->id . '_' . time();
        for ($i = 0; $i < $number_forks; $i++) {
            $this->enqueue(
                'DelayedJobs.Ark',
                $payload,
                [
                    'group' => 'FloodTest',
                    'priority' => random_int(0, 9) + $job->getPriority(),
                    'sequence' => $sequence
                ]
            );
        }

        return $pi;
    }
}
