<?php

\Cake\Routing\Router::scope('/delayed_jobs', ['plugin' => 'DelayedJobs', 'controller' => 'DelayedJobs'], function (\Cake\Routing\RouteBuilder $routes) {
    $routes->connect('/:action');
});
