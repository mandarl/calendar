<?php
declare(strict_types=1);

/**
 * Routes configuration (CakePHP 5). Mirrors the Cake 3 routes: `/` -> Events::index, the explicit event routes,
 * the honoraria routes, and the DashedRoute fallbacks for everything else.
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

return function (RouteBuilder $routes): void {
    // The default route class turns CamelCase controller/action names into dashed URLs (e.g. /calendar-admin/edit).
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->connect('/', ['controller' => 'Events', 'action' => 'index']);

        // Events View
        $builder->connect(
            '/events/view/{id}',
            ['controller' => 'Events', 'action' => 'view'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // Feed
        $builder->connect(
            '/events/feed/{feedtype}',
            ['controller' => 'Events', 'action' => 'feed'],
            ['feedtype' => '[a-zA-Z][a-zA-Z0-9]+', 'pass' => ['feedtype']]
        );

        // Application-specific routes.
        $builder->connect('/events/honoraria/pending', ['controller' => 'Events', 'action' => 'pendingHonoraria']);
        $builder->connect('/events/honoraria/accepted', ['controller' => 'Events', 'action' => 'acceptedHonoraria']);
        $builder->connect('/events/honoraria/rejected', ['controller' => 'Events', 'action' => 'rejectedHonoraria']);
        $builder->connect('/events/honoraria/upcoming', ['controller' => 'Events', 'action' => 'upcomingHonoraria']);

        // Catch-all routes: /:controller and /:controller/:action/*
        $builder->fallbacks();
    });
};
