<?php

\Cake\Routing\Router::plugin('DelayedJobs', ['path' => '/delayed-jobs'], function (\Cake\Routing\RouteBuilder $routes) {
    $routes->connect('/', ['controller' => 'DelayedJobs', 'action' => 'index']);
    $routes->connect('/:action/*', ['controller' => 'DelayedJobs']);
});
