<?php

/**
 * =========================
 * DelayedJobs Plugin Config
 * =========================
 * 
 */
use Cake\Core\Configure;

if (!Configure::check('dj.service.name')) {
    Configure::write("dj.service.name", "delayed-job");
    // This name should be unique for every parent app running on the same server
}
if (!Configure::check('dj.max.workers')) {
    Configure::write("dj.max.workers", 10);
}
if (!Configure::check('dj.max.retries')) {
    Configure::write("dj.max.retries", 25);
}
if (!Configure::check('dj.max.execution.time')) {
    Configure::write("dj.max.execution.time", 6 * 60 * 60); // 6 Hours
}

\Cake\Database\Type::map('serialize', 'DelayedJobs\Database\Type\SerializeType');

$job_listener = new \DelayedJobs\Event\DelayedJobsListener();
\Cake\Event\EventManager::instance()->on($job_listener);