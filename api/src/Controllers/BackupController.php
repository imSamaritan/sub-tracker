<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Repositories\SubscriptionRepository;
use SubTracker\Services\BackupService;

class BackupController
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly SubscriptionRepository $subscriptionRepository
    ) {}

    /**
     * GET /backup (§8.8)
     */
    public function export(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $backupData = $this->backupService->export($userId);
        $userToday = $this->subscriptionRepository->getUserToday($userId);

        $filename = "subtracker_backup_{$userToday}.json";

        $response->getBody()->write((string) json_encode($backupData, JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * POST /backup (§8.8)
     */
    public function restore(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);

        $result = $this->backupService->restore($userId, $body);

        if (!$result['success']) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Some fields are invalid.',
                    'fields' => $result['errors'] ?? [],
                ]
            ], JSON_UNESCAPED_SLASHES));

            return $response
                ->withStatus(422)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write((string) json_encode([
            'data' => $result['data']
        ], JSON_UNESCAPED_SLASHES));

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * POST /account/reset-demo (§8.10)
     */
    public function resetDemo(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');

        $result = $this->backupService->resetDemo($userId);

        if (!$result['success']) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => $result['error'] ?? 'Rate limit exceeded.',
                ]
            ], JSON_UNESCAPED_SLASHES));

            $res = $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');

            if (isset($result['retryAfter'])) {
                $res = $res->withHeader('Retry-After', (string) $result['retryAfter']);
            }

            return $res;
        }

        $response->getBody()->write((string) json_encode([
            'data' => $result['data']
        ], JSON_UNESCAPED_SLASHES));

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
