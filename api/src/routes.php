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

        // Debug route to test JSON body parsing and method override in development
        if (filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $group->post('/test/echo', function (\Psr\Http\Message\ServerRequestInterface $request, \Psr\Http\Message\ResponseInterface $response) {
                return \SubTracker\Support\ApiResponse::success($response, [
                    'parsedBody' => $request->getParsedBody(),
                    'method' => $request->getMethod(),
                    'authHeader' => $request->getHeaderLine('Authorization'),
                ]);
            });
        }
    });
};
