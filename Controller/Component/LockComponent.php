<?php

class LockComponent extends Object
{

    var $actions;
    var $controller;

    function initialize(&$controller, $settings = array())
    {
        $this->controller = & $controller;
        $this->actions = $settings;
    }

    function startup(&$controller)
    {
        if ($this->actions && in_array($this->controller->action, $this->actions))
        {
            $this->lock($this->controller->name . '.' . $this->controller->action);
        }
    }

    function shutdown(&$controller)
    {
        $this->unlock($this->controller->name . '.' . $this->controller->action);
    }

    function lock($key)
    {
        if (!is_dir(TMP . 'lock'))
            mkdir(TMP . 'lock');
        $lock_file = TMP . 'lock' . DS . $key;
        if (file_exists($lock_file))
        {
            if ($this->running(file_get_contents($lock_file)))
            {
                echo __('action is already running' . "\n", true);
                exit;
            }
        }
        file_put_contents($lock_file, getmypid());
        return true;
    }

    function unlock($key)
    {
        $lock_file = TMP . 'lock' . DS . $key;
        if (file_exists($lock_file))
            unlink($lock_file);
        return true;
    }

    function running($pid)
    {
        
        if (stristr(PHP_OS, 'WIN'))
        {
            if (`tasklist /fo csv /fi "PID eq $pid"`)
                return true;
        }
        else
        {
            if (in_array($pid, explode(PHP_EOL, `ps -e | awk '{print $1}'`)))
                return true;
        }
        return false;
    }

}