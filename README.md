Delayed Jobs
=================

Version: 1.0
------------

This Delayed Jobs Plugin was built for uAfrica.com

A plugin that allows you to load priority based tasks for async background processing. This is a scalable plugin that can be executed on multiple application servers to distribute the load.

Requirements
------------

* CakePHP 2.5.4+
* PHP 5.2.8+
* Twitter Bootstrap 3.0+ (Only needed for management interfaces)
* jQuery 1.9+ (Only needed for management interfaces)

Installation
------------

* Clone the repo into /app/Plugins/DelayedJobs.
* Run the Config/Schema/DelayedJobs.sql script to create the needed tables.
* Enable the plugin by adding CakePlugin::load('DelayedJobs'); to your bootstrap.php

Usage
-------------

Loading new Jobs

```php
var $uses = array('DelayedJobs.DelayedJob');

$options = array("max_execution_time" => 10);
$payload = array("SomeVariable" => "Some Value");

$data = array(
            'group' => 'test',
            'class' => 'SomeModel',
            'method' => 'TestDelayedJobMethod',
            'options' => $options,
            'payload' => $payload,
            'priority' => 1,
        );
$this->DelayedJob->queue($data);
```

> **1** is the highest priority and the higher the number the lower the
> priority.

Starting the Job Servers
------------------------
The following Shell Commands are available

**Starting & Monitor Instances:**

    cake DelayedJobs.Watchdog 1

**Run Individual Job:**

    cake DelayedJobs.Worker {job_id}

The [1] argument instructs how many workers need to be started. The watchdog can run as many times as you want, it will just confirm that the number of required job servers is running. The maximum number of workers that can bes started per server is **10**.


Changelog
-----

**1.1: ?? November 2014**
* New Interface (Using Standard Twitter Bootstrap 3.0 & jQuery)

**1.0: Initial Release - 9 November 2014**

To Do
-----

* Need a better interface to manage and monitor delayed jobs
* Improved routing to views & controllers
* Implement an archiving solution
* Unit Testing

If you find any issues with the plugin, please create a new issue within [GitHub](https://github.com/uafrica/delayed-jobs/issues)