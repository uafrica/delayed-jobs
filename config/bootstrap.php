<?php

/**
 * =========================
 * DelayedJobs Plugin Config
 * =========================
 * 
 */
use Cake\Core\Configure;

$defaultConfig = [
    'maximum' => [
        'maxRetries' => 5,
        'pulseTime' => 6 * 60 * 60,
        'priority' => 100,
    ],
    'default' => [
        'maxRetries' => 5,
    ],
    'archive' => [
        'enabled' => false,
        'tableName' => 'delayed_jobs_archive',
        'recurring' => 'tomorrow 00:30am'
    ]
];

$delayedJobsConfig = Configure::read('DelayedJobs');

Configure::write($delayedJobsConfig + $defaultConfig);

\Cake\Database\Type::map('serialize', 'DelayedJobs\Database\Type\SerializeType');

\DelayedJobs\RecurringJobBuilder::add([
    'worker' => 'DelayedJobs.Archive',
    'priority' => Configure::read('maximum.priority')
]);
