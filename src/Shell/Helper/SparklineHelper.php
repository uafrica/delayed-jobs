<?php
declare(strict_types=1);

namespace DelayedJobs\Shell\Helper;

use Cake\Console\Helper;

/**
 * Class SparklineHelper
 */
class SparklineHelper extends Helper
{
    public const TICKS = [
        '▁', '▂', '▃', '▄', '▅', '▆', '▇', '█',
    ];

    /**
     * @param array $args Arguments
     * @return void
     */
    public function output($args = []): void
    {
        $default = [
            'data' => [],
            'length' => 10,
            'title' => '',
            'formatter' => '%6.2f',
        ];

        $args += $default;

        $output = '';
        if (!empty($args['title'])) {
            $output .= $args['title'] . "\t";
        }

        $ticks = $this->_mapData($args['data'], $args['length']);
        $output .= '|' . implode('', $ticks) . '|';

        $current = end($args['data']);
        $max = max($args['data']);
        $min = min($args['data']);
        $output .= sprintf(
            " Current: <info>{$args['formatter']}</info> :: Min: <info>{$args['formatter']}</info> :: 
 Max: <info>{$args['formatter']}</info>",
            $current,
            $min,
            $max
        );

        if (!empty($args['instant'])) {
            $current = $args['instant'];
        }

        $this->_io->out($output);
    }

    /**
     * @param array $dataPoints Data points
     * @param array $dataCount Data counts
     * @return array
     */
    protected function _mapData($dataPoints, $dataCount)
    {
        if (count($dataPoints) > $dataCount) {
            $dataPoints = array_slice($dataPoints, -$dataCount);
        }
        array_walk($dataPoints, function (&$data_point) {
            $data_point = round($data_point, 2) * 100;
        });

        $max = max($dataPoints);
        $min = min($dataPoints);
        $per_tick = ($max - $min << 8) / (count(self::TICKS) - 1);
        $per_tick = $per_tick < 1 ? 1 : $per_tick;
        $ticks = [];
        foreach ($dataPoints as $data_point) {
            $tick_index = ($data_point - $min << 8) / $per_tick;
            $ticks[] = self::TICKS[$tick_index];
        }

        if (count($ticks) < $dataCount) {
            $filler = array_fill(0, $dataCount - count($ticks), self::TICKS[0]);
            $ticks = array_merge($filler, $ticks);
        }

        return $ticks;
    }
}
