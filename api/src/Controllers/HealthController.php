<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Support\ApiResponse;

class HealthController
{
    private array $settings;

    public function __construct(
        private readonly PDO $pdo,
        ContainerInterface $container
    ) {
        $this->settings = $container->has('settings') ? $container->get('settings') : [];
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT @@session.time_zone AS session_tz, NOW() AS db_now");
        $dbRow = $stmt->fetch();

        $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return ApiResponse::success($response, [
            'status' => 'healthy',
            'app' => 'SubTracker REST API',
            'version' => '1.0.0',
            'environment' => $this->settings['app_env'] ?? 'local',
            'database' => [
                'connected' => true,
                'sessionTimezone' => $dbRow['session_tz'] ?? null,
                'dbNow' => $dbRow['db_now'] ?? null,
            ],
            'phpTimezone' => date_default_timezone_get(),
            'timestamp' => $utcNow->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
