Delayed Jobs
=================
This delayed Jobs module was built by Jaco Roux for the uAfrica eCommerce Platform.

A plugin that allows you to load priority tasks for async processing. 
This is a scalable plugin that can be executed on multiple application servers to 
distribute the load. It uses a combination of a database and a RabbitMQ server to manage
the job queue.

Requirements
------------

* PHP 7.0+
* CakePHP 3.4+
* A database supported by CakePHP
* A RabbitMQ instance with the [delayed message exchange](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange)

Installation
------------

1. Require the plugin with composer `$ composer require uafrica/delayed-jobs`.
2. Load the plugin by running `bin/cake plugin load DelayedJobs`
3. Setup the database by running `bin/cake migrations migrate --plugin DelayedJobs`

Running a worker
----------------

To run a single worker, run `bin/cake worker -v`.
To run multiple workers, run `bin/cake watchdog --workers x` (Where _x_ is the number to run)

It is recommended to put the `watchdog` command in a 5 to 10 minute cron job to ensure 
that if a worker shuts down for any reason, it is restarted.  

Enqueuing a job
---------------

```php
    $job = new \DelayedJob\DelayedJob\Job();
    $job->setWorker('RunJob') //References a \App\Worker\RunJobWorker class
        ->setPayload($payload) //An array of payload data 
        ->setRunAt(new Time('+1 hour')) //Run this job in an hour
        ->setPriority('10'); //Priority of 10

    \DelayedJob\DelayedJob\JobManager::instance()
        ->enqueue($job);
```

Alternatively, you can use the `\DelayedJob\DelayedJob\EnqueueTrait` which gives an
`enqeue($worker, $payload, $options)` method.

Creating a worker
-----------------

Simply create a class in the `Worker` namespace that implements the `\DelayedJob\Worker\JobWorkerInterface`

For example

```php
namespace DelayedJobs\Worker;

use DelayedJobs\DelayedJob\Job;
use DelayedJobs\Result\Success;

/**
 * Class TestWorker
 */
class TestWorker implements JobWorkerInterface
{
    /**
     * @param \DelayedJobs\DelayedJob\Job $job The job that is being run.
     * @return bool
     */
    public function __invoke(Job $job)
    {
        return new Success('We ran!')
    }
}
```
