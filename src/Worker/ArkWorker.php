<?php

namespace DelayedJobs\Worker;

use Cake\Collection\Collection;
use Cake\Network\Http\Client;
use DelayedJobs\Traits\QueueJobTrait;

class ArkWorker
{
    use QueueJobTrait;

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

    public function flood($payload, $job)
    {
        $pi = $this->_bcpi(rand(1, $payload['work']));
        //Fetch an api 50% of the time
        if (rand(0, 1) == 0) {
            $client = new Client();
            $response = $client->get('https://packagist.org/packages/list.json?type=cakephp-plugin');
            $list = new Collection($response->json['packageNames']);
            $list = $list->take(rand(5, 20));
            foreach ($list as $item) {
                $response = $client->get("https://packagist.org/packages/{$item}.json");
                $response->json;
            }

            $pi .= ' - api';
        }

        $number_forks = rand($payload['first'] ? 1 : 0, $payload['max_fork']);
        $payload['first'] = false;
        $sequence = rand(0, 9) > 0 ? null : 'ark_' . $job->id . '_' . time();
        for ($i = 0; $i < $number_forks; $i++) {
            $this->_queueJob(
                'FloodTest',
                'DelayedJobs\Worker\ArkWorker',
                'flood',
                $payload,
                rand(0, 9) + $job->priority,
                $sequence
            );
        }

        return $pi;
    }
}