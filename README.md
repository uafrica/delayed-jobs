Delayed Jobs
=================

Version: 0.4
------------

This Delayed Jobs Plugin was built for uAfrica.com

A plugin that allows you to load priority tasks for async background processing. This is a scalable plugin that can be executed on multiple application servers to distribute the load.

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
```php
var $uses = array('DelayedJobs.DelayedJob');

$options = array("max_execution_time" => 10);
$payload = array("SomeVariable" => "Some Value");

$data = array(
            'group' => 'test',
            'class' => 'Product',
            'method' => 'TestDelayedJob2',
            'options' => $options,
            'payload' => $payload,
            'priority' => 1,
        );
$this->DelayedJob->queue($data);
```

Changelog
-----

**1.1: ?? November 2014**
* New Interface (Using Standard Twitter Bootstrap 3.0 & jQuery)

**1.0: Initial Release - 9 November 2014**

To Do
-----

* Need a better interface to manage lists and list items
* Improved routing to views & controllers
* Need to improve data validation
* Complete the import & export functionality
* Test cases
* When editing a list item, and the name changes to an existing name within the same list, an error is thrown.
* Checks to ensure integrity of data (List must always have at least one default option etc)


If you find any issues with the plugin, please create a new issue within [GitHub](https://github.com/jacoroux/cakephp-lookuplists-plugin/issues)