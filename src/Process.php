<?php
namespace DelayedJobs;


/* An easy way to keep in track of external processes.
 * Ever wanted to execute a process in php, but you still wanted to have somewhat controll of the process ? Well.. This is a way of doing it.
 * @compability: Linux only. (Windows does not work).
 * @author: Peec
 */

class Process
{

    private $pid;
    private $command;

    public function __construct($cl = false)
    {
        if ($cl != false) {
            $this->command = $cl;
            $this->runCom();
        }
    }

    private function runCom()
    {
        $command = 'nohup ' . $this->command . ' > /dev/null 2>&1 & echo $!';
        exec($command, $op);
        $this->pid = (int)$op[0];
    }

    public function setPid($pid)
    {
        $this->pid = $pid;
    }

    public function getPid()
    {
        return $this->pid;
    }

    public function getPidByName($name)
    {
        $out = [];
        exec("ps aux | grep '$name' | grep -v grep | awk '{ print $2 }' | head -1", $out);
        if (isset($out[0])) {
            return $out[0];
        } else {
            return false;
        }
    }

    public function status()
    {
        $command = 'ps -p ' . $this->pid;
        exec($command, $op);
        if (!isset($op[1])) {
            return false;
        } else {
            return true;
        }
    }

    public function start()
    {
        if ($this->command != '') {
            $this->runCom();
        } else {
            return true;
        }
    }

    public function stop($timeout = 5)
    {
        $command = 'kill ' . $this->pid;
        exec($command);
        $start_time = time();
        do {
        } while ($this->status() !== false && (time() - $start_time <= $timeout));
    }

    public function details()
    {
        $command = 'ps -p ' . $this->pid . " -f";
        exec($command, $op);
        if (isset($op[1])) {
            return $op[1];
        } else {
            return false;
        }
    }

}