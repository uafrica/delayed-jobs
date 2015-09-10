<?php

namespace DelayedJobs\Worker;

use Cake\I18n\Time;

/**
 * Class TestWorker
 */
class TestWorker extends BaseWorker
{
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

    /**
     * @param array $payload Payload
     * @return string
     * @throws \Exception
     */
    public function test($payload)
    {
        for ($i=0;$i<50;$i++) {
            $pi = $this->_bcpi(250);
        }
        $time = (new Time())->i18nFormat();
        if ($payload['type'] === 'success') {
            return 'Successfull test at ' . $time . ' Pi is: ' . $pi;
        } else {
            throw new \Exception('Failing test at ' . $time . ' because ' . $payload['type']);
        }
    }
}
