<?php
namespace DelayedJobs;

/* An easy way to keep in track of external processes.
 * Ever wanted to execute a process in php, but you still wanted to have somewhat controll of the process ? Well.. This is a way of doing it.
 * @compability: Linux only. (Windows does not work).
 * @author: Peec
 */

class Process
{

    /**
     * @var
     */
    private $pid;
    /**
     * @var bool
     */
    private $command;

    /**
     * Process constructor.
     *
     * @param bool $cl
     */
    public function __construct($cl = false)
    {
        if ($cl != false) {
            $this->command = $cl;
            $this->runCom();
        }
    }

    /**
     * @return void
     */
    private function runCom()
    {
        $command = 'nohup ' . $this->command . ' > /dev/null 2>&1 & echo $!';
        exec($command, $op);
        $this->pid = (int)$op[0];
    }

    /**
     * @param $pid
     * @return void
     */
    public function setPid($pid)
    {
        $this->pid = $pid;
    }

    /**
     * @return mixed
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @param $name
     * @return bool|mixed
     */
    public function getPidByName($name)
    {
        $out = [];
        exec("ps aux | grep '$name' | grep -v grep | awk '{ print $2 }' | head -1", $out);
        if (isset($out[0])) {
            return $out[0];
        }

        return false;
    }

    /**
     * @return bool
     */
    public function status(): bool
    {
        return posix_getpgid($this->pid) !== false;
    }

    /**
     * @return void
     */
    public function start()
    {
        if ($this->command != '') {
            $this->runCom();
        }
    }

    /**
     * @param int $timeout
     * @return void
     */
    public function stop($timeout = 5)
    {
        $command = 'kill -9 ' . $this->pid;
        exec($command);
        $start_time = time();
        do {
            //Wait for it to stop
        } while ($this->status() !== false && (time() - $start_time <= $timeout));
    }

    /**
     * @return bool
     */
    public function details()
    {
        $command = 'ps -p ' . $this->pid . ' -f';
        exec($command, $op);
        if (isset($op[1])) {
            return $op[1];
        }

        return false;
    }
}
