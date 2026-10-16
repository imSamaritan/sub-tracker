<?php
declare(strict_types=1);

use Psr\Container\ContainerInterface;

return [
    'settings' => [
        'app_env' => $_ENV['APP_ENV'] ?? 'local',
        'app_debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'app_url' => $_ENV['APP_URL'] ?? 'http://localhost/projects/sub-tracker',
        'app_base_path' => $_ENV['APP_BASE_PATH'] ?? '/projects/sub-tracker/api/public',
        'cors_allowed_origin' => $_ENV['CORS_ALLOWED_ORIGIN'] ?? '',
        'db' => [
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
            'database' => $_ENV['DB_NAME'] ?? 'subtracker',
            'username' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'charset' => 'utf8mb4',
        ],
        'token_ttl_days' => (int) ($_ENV['TOKEN_TTL_DAYS'] ?? 30),
        'password_reset_ttl_minutes' => (int) ($_ENV['PASSWORD_RESET_TTL_MINUTES'] ?? 60),
        'email_verify_ttl_hours' => (int) ($_ENV['EMAIL_VERIFY_TTL_HOURS'] ?? 24),
        'unverified_account_ttl_days' => (int) ($_ENV['UNVERIFIED_ACCOUNT_TTL_DAYS'] ?? 7),
    ],

    PDO::class => function (ContainerInterface $c): PDO {
        $db = $c->get('settings')['db'];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );

        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Audit timestamps and sessions MUST be UTC across both local MySQL 8.4 and production MariaDB
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    },

    \Psr\Http\Message\ResponseFactoryInterface::class => function (): \Psr\Http\Message\ResponseFactoryInterface {
        return new \Slim\Psr7\Factory\ResponseFactory();
    },

    \SubTracker\Services\RateLimiter::class => function (ContainerInterface $c): \SubTracker\Services\RateLimiter {
        return new \SubTracker\Services\RateLimiter($c->get(PDO::class));
    },

    \SubTracker\Middleware\ApiErrorHandler::class => function (ContainerInterface $c): \SubTracker\Middleware\ApiErrorHandler {
        return new \SubTracker\Middleware\ApiErrorHandler($c->get(\Psr\Http\Message\ResponseFactoryInterface::class));
    },

    \SubTracker\Middleware\JsonBodyParserMiddleware::class => function (ContainerInterface $c): \SubTracker\Middleware\JsonBodyParserMiddleware {
        return new \SubTracker\Middleware\JsonBodyParserMiddleware($c->get(\Psr\Http\Message\ResponseFactoryInterface::class));
    },

    \SubTracker\Middleware\LiteSpeedAuthMiddleware::class => function (): \SubTracker\Middleware\LiteSpeedAuthMiddleware {
        return new \SubTracker\Middleware\LiteSpeedAuthMiddleware();
    },
];
