<?php

\Cake\Routing\Router::plugin('DelayedJobs', ['path' => '/delayed_jobs'], function (\Cake\Routing\RouteBuilder $routes) {
    $routes->routeClass('InflectedRoute');
    $routes->connect('/', ['controller' => 'DelayedJobs', 'action' => 'index']);
    $routes->connect('/:action/*', ['controller' => 'DelayedJobs']);
});
