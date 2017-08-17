<?php

namespace DelayedJobs\Shell\Helper;

use Cake\Console\Helper;

/**
 * Class SparklineHelper
 */
class SparklineHelper extends Helper
{
    /**
     *
     */
    const TICKS = [
        '▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'
    ];

    /**
     * @param array $args
     * @return void
     */
    public function output($args = [])
    {
        $default = [
            'data' => [],
            'length' => 10,
            'title' => '',
            'formatter' => '%6.2f'
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
        $output .= sprintf(" Current: <info>{$args['formatter']}</info> :: Min: <info>{$args['formatter']}</info> :: Max: <info>{$args['formatter']}</info>", $current, $min, $max);

        if (!empty($args['instant'])) {
            $current = $args['instant'];
        }

        $this->_io->out($output);
    }

    /**
     * @param $data_points
     * @param $data_count
     * @return array
     */
    protected function _mapData($data_points, $data_count)
    {
        if (count($data_points) > $data_count) {
            $data_points = array_slice($data_points, -$data_count);
        }
        array_walk($data_points, function (&$data_point) {
            $data_point = round($data_point, 2) * 100;
        });

        $max = max($data_points);
        $min = min($data_points);
        $per_tick = (($max - $min) << 8) / (count(self::TICKS) - 1);
        $per_tick = $per_tick < 1 ? 1 : $per_tick;
        $ticks = [];
        foreach ($data_points as $data_point) {
            $tick_index = (($data_point - $min) << 8) / $per_tick;
            $ticks[] = self::TICKS[$tick_index];
        }

        if (count($ticks) < $data_count) {
            $filler = array_fill(0, $data_count - count($ticks), self::TICKS[0]);
            $ticks = array_merge($filler, $ticks);
        }

        return $ticks;
    }
}
