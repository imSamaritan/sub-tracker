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

    // Route handler for Subscriptions group
    $registerSubRoutes = function (RouteCollectorProxy $group): void {
        $group->get('', [\SubTracker\Controllers\SubscriptionController::class, 'list']);
        $group->post('', [\SubTracker\Controllers\SubscriptionController::class, 'create']);
        $group->post('/reset-paid', [\SubTracker\Controllers\SubscriptionController::class, 'resetPaid']);
        $group->get('/{id:[0-9]+}', [\SubTracker\Controllers\SubscriptionController::class, 'get']);
        $group->put('/{id:[0-9]+}', [\SubTracker\Controllers\SubscriptionController::class, 'update']);
        $group->patch('/{id:[0-9]+}', [\SubTracker\Controllers\SubscriptionController::class, 'patch']);
        $group->delete('/{id:[0-9]+}', [\SubTracker\Controllers\SubscriptionController::class, 'delete']);
        $group->post('/{id:[0-9]+}/pause', [\SubTracker\Controllers\SubscriptionController::class, 'pause']);
        $group->post('/{id:[0-9]+}/resume', [\SubTracker\Controllers\SubscriptionController::class, 'resume']);
        $group->post('/{id:[0-9]+}/payment', [\SubTracker\Controllers\SubscriptionController::class, 'markPaid']);
        $group->delete('/{id:[0-9]+}/payment', [\SubTracker\Controllers\SubscriptionController::class, 'unmarkPaid']);
        $group->post('/{id:[0-9]+}/renew', [\SubTracker\Controllers\SubscriptionController::class, 'renew']);
    };

    // Root-level health check
    $app->get('/health', HealthController::class);

    // State route (root)
    $app->get('/state', [\SubTracker\Controllers\StateController::class, 'state'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);

    // Profile routes (at root level for convenience)
    $app->get('/profile', [\SubTracker\Controllers\ProfileController::class, 'get'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);
    $app->patch('/profile', [\SubTracker\Controllers\ProfileController::class, 'update'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);

    // Categories and Dashboard/Alerts routes (at root level for convenience)
    $app->get('/categories', [\SubTracker\Controllers\CategoryController::class, 'list'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);
    $app->get('/dashboard/summary', [\SubTracker\Controllers\DashboardController::class, 'summary'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);
    $app->get('/alerts', [\SubTracker\Controllers\DashboardController::class, 'alerts'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);

    // Subscriptions routes (at root level for convenience)
    $app->group('/subscriptions', $registerSubRoutes)
        ->add($verifiedMiddleware)
        ->add($authMiddleware);

    // Payments route (at root level for convenience)
    $app->get('/payments', [\SubTracker\Controllers\PaymentController::class, 'list'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);

    // Route handler for Push group (§8.9)
    $registerPushRoutes = function (RouteCollectorProxy $group) use ($authMiddleware, $verifiedMiddleware): void {
        // Public endpoint: VAPID public key
        $group->get('/vapid-public-key', [\SubTracker\Controllers\PushController::class, 'vapidPublicKey']);

        // Public endpoint: service worker re-registers a rotated subscription while the app is closed.
        // Ownership is proven by the previous (secret) endpoint URL.
        $group->post('/subscriptions/renew', [\SubTracker\Controllers\PushController::class, 'renew']);

        // Authenticated + Verified Email endpoints
        $group->post('/subscriptions', [\SubTracker\Controllers\PushController::class, 'subscribe'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        $group->delete('/subscriptions', [\SubTracker\Controllers\PushController::class, 'unsubscribe'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        $group->post('/test', [\SubTracker\Controllers\PushController::class, 'test'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);
    };

    // Backup & Demo Reset routes (at root level for convenience)
    $app->get('/backup', [\SubTracker\Controllers\BackupController::class, 'export'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);
    $app->post('/backup', [\SubTracker\Controllers\BackupController::class, 'restore'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);
    $app->post('/account/reset-demo', [\SubTracker\Controllers\BackupController::class, 'resetDemo'])
        ->add($verifiedMiddleware)
        ->add($authMiddleware);

    // Mount Push directly on /push for convenience
    $app->group('/push', $registerPushRoutes);

    // Mount Auth directly on /auth for convenience
    $app->group('/auth', $registerAuthRoutes);

    // API version 1 route group
    $app->group('/v1', function (RouteCollectorProxy $group) use ($registerAuthRoutes, $registerSubRoutes, $registerPushRoutes, $authMiddleware, $verifiedMiddleware): void {
        $group->get('/health', HealthController::class);
        $group->group('/auth', $registerAuthRoutes);

        // State route in /v1
        $group->get('/state', [\SubTracker\Controllers\StateController::class, 'state'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        // Profile routes in /v1
        $group->get('/profile', [\SubTracker\Controllers\ProfileController::class, 'get'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);
        $group->patch('/profile', [\SubTracker\Controllers\ProfileController::class, 'update'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        // Categories & Dashboard in /v1
        $group->get('/categories', [\SubTracker\Controllers\CategoryController::class, 'list'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);
        $group->get('/dashboard/summary', [\SubTracker\Controllers\DashboardController::class, 'summary'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);
        $group->get('/alerts', [\SubTracker\Controllers\DashboardController::class, 'alerts'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        // Subscriptions routes in /v1
        $group->group('/subscriptions', $registerSubRoutes)
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        // Payments route in /v1
        $group->get('/payments', [\SubTracker\Controllers\PaymentController::class, 'list'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        // Backup & Demo Reset in /v1
        $group->get('/backup', [\SubTracker\Controllers\BackupController::class, 'export'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);
        $group->post('/backup', [\SubTracker\Controllers\BackupController::class, 'restore'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);
        $group->post('/account/reset-demo', [\SubTracker\Controllers\BackupController::class, 'resetDemo'])
            ->add($verifiedMiddleware)
            ->add($authMiddleware);

        // Push routes in /v1
        $group->group('/push', $registerPushRoutes);

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
