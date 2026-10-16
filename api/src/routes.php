<?php
declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use SubTracker\Controllers\AuthController;
use SubTracker\Controllers\HealthController;
use SubTracker\Middleware\AuthMiddleware;
use SubTracker\Middleware\VerifiedEmailMiddleware;

return function (App $app): void {
    $container = $app->getContainer();
    $authMiddleware = $container->get(AuthMiddleware::class);
    $verifiedMiddleware = $container->get(VerifiedEmailMiddleware::class);

    // Route handler for Auth group
    $registerAuthRoutes = function (RouteCollectorProxy $group) use ($authMiddleware, $verifiedMiddleware): void {
        // Public endpoints
        $group->post('/register', [AuthController::class, 'register']);
        $group->post('/verify-email', [AuthController::class, 'verifyEmail']);
        $group->post('/resend-verification', [AuthController::class, 'resendVerification']);
        $group->post('/login', [AuthController::class, 'login']);
        $group->post('/forgot-password', [AuthController::class, 'forgotPassword']);
        $group->post('/reset-password', [AuthController::class, 'resetPassword']);

        // Authenticated endpoints
        $group->post('/logout', [AuthController::class, 'logout'])
            ->add($authMiddleware);

        // Authenticated + Verified Email endpoints
        $group->get('/me', [AuthController::class, 'me'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        $group->put('/password', [AuthController::class, 'changePassword'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        $group->delete('/account', [AuthController::class, 'deleteAccount'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);
    };

    // Root-level health check
    $app->get('/health', HealthController::class);

    // Profile routes (at root level for convenience)
    $app->get('/profile', [\SubTracker\Controllers\ProfileController::class, 'get'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);
    $app->patch('/profile', [\SubTracker\Controllers\ProfileController::class, 'update'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);

    // Mount Auth directly on /auth for convenience
    $app->group('/auth', $registerAuthRoutes);

    // API version 1 route group
    $app->group('/v1', function (RouteCollectorProxy $group) use ($registerAuthRoutes, $authMiddleware, $verifiedMiddleware): void {
        $group->get('/health', HealthController::class);
        $group->group('/auth', $registerAuthRoutes);

        // Profile routes in /v1
        $group->get('/profile', [\SubTracker\Controllers\ProfileController::class, 'get'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);
        $group->patch('/profile', [\SubTracker\Controllers\ProfileController::class, 'update'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

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
