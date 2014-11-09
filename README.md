Delayed Jobs
=================
This delayed Jobs module was build by Jaco Roux for the uAfrica eCommerce Platform.

A plugin that allows you to load priority tasks for async processing. This is a scalable plugin that can be executed on multiple application servers to distribute the load.

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

Future Functionality
-------------
* Ability to test a job without queuing the job


Further documentation to follow.