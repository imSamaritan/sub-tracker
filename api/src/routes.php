<?php
declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use SubTracker\Controllers\HealthController;

return function (App $app): void {
    // Root level ping / health check
    $app->get('/health', HealthController::class);

    // API version 1 route group
    $app->group('/v1', function (RouteCollectorProxy $group): void {
        $group->get('/health', HealthController::class);
    });
};
