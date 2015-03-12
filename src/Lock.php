<?php
namespace DelayedJobs;

/**
 * Class Lock
 */
class Lock
{
    /**
     * @param string $key Key to lock
     * @return bool
     */
    public function lock($key)
    {
        if (!is_dir(TMP . 'lock')) {
            mkdir(TMP . 'lock');
        }
        $lock_file = TMP . 'lock' . DS . $key;
        if (file_exists($lock_file)) {
            $lock = json_decode(file_get_contents($lock_file), true);
            if ($this->running($lock['pid'])) {
                return false;
            }
        }
        file_put_contents($lock_file, json_encode([
            'time' => time(),
            'pid' => getmypid()
        ]));

        return true;
    }

    /**
     * @param string $key Key to unlock
     * @return bool
     */
    public function unlock($key)
    {
        $lock_file = TMP . 'lock' . DS . $key;
        if (file_exists($lock_file)) {
            unlink($lock_file);
        }

        return true;
    }

    /**
     * @param int $pid PID to check.
     * @return bool
     * @codeCoverageIgnore Difficult to test this.
     */
    public function running($pid)
    {
        if (stristr(PHP_OS, 'WIN')) {
            if (`tasklist /fo csv /fi "PID eq $pid"`) {
                return true;
            }
        } else {
            if (in_array($pid, explode(PHP_EOL, `ps -e | awk '{print $1}'`))) {
                return true;
            }
        }

        return false;
    }
}