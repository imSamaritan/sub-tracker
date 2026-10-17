<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Services\PushService;
use SubTracker\Support\ApiResponse;
use SubTracker\Support\Validator;

class PushController
{
    public function __construct(
        private readonly PushService $pushService
    ) {}

    /**
     * GET /push/vapid-public-key (§8.9)
     */
    public function vapidPublicKey(Request $request, Response $response): Response
    {
        return ApiResponse::success($response, [
            'publicKey' => $this->pushService->getPublicKey(),
        ]);
    }

    /**
     * POST /push/subscriptions (§8.9)
     */
    public function subscribe(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);

        $errors = Validator::validatePushSubscription($body);
        if (!empty($errors)) {
            return ApiResponse::error($response, 'VALIDATION_FAILED', 'Some fields are invalid.', 422, $errors);
        }

        $userAgent = !empty($body['userAgent'])
            ? trim((string) $body['userAgent'])
            : ($request->getHeaderLine('User-Agent') ?: null);

        $subscription = $this->pushService->subscribe($userId, $body, $userAgent);

        return ApiResponse::success($response, $subscription, 201);
    }

    /**
     * DELETE /push/subscriptions (§8.9)
     */
    public function unsubscribe(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);
        $endpoint = isset($body['endpoint']) ? trim((string) $body['endpoint']) : '';

        if ($endpoint === '') {
            return ApiResponse::error($response, 'VALIDATION_FAILED', 'Some fields are invalid.', 422, [
                'endpoint' => 'Push subscription endpoint URL is required.',
            ]);
        }

        $this->pushService->unsubscribe($userId, $endpoint);

        return $response->withStatus(204);
    }

    /**
     * POST /push/test (§8.9)
     */
    public function test(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');

        $outcome = $this->pushService->sendTestNotification($userId);
        if (!$outcome['allowed']) {
            $retryAfter = (int) ($outcome['retryAfter'] ?? 60);
            $response = $response->withHeader('Retry-After', (string) $retryAfter);
            return ApiResponse::error(
                $response,
                'RATE_LIMITED',
                sprintf('Too many test notifications sent. Please try again in %d seconds.', $retryAfter),
                429
            );
        }

        return ApiResponse::success($response, $outcome['result'], 202);
    }
}
