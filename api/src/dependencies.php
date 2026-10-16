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
        'mail' => [
            'host' => $_ENV['MAIL_HOST'] ?? '',
            'port' => (int) ($_ENV['MAIL_PORT'] ?? 465),
            'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'ssl',
            'username' => $_ENV['MAIL_USERNAME'] ?? '',
            'password' => $_ENV['MAIL_PASSWORD'] ?? '',
            'from' => $_ENV['MAIL_FROM'] ?? 'no-reply@subtracker.local',
            'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'SubTracker',
        ],
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

    \SubTracker\Services\MailService::class => function (ContainerInterface $c): \SubTracker\Services\MailService {
        return new \SubTracker\Services\MailService($c->get('settings'));
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

    \SubTracker\Middleware\AuthMiddleware::class => function (ContainerInterface $c): \SubTracker\Middleware\AuthMiddleware {
        return new \SubTracker\Middleware\AuthMiddleware(
            $c->get(PDO::class),
            $c->get(\Psr\Http\Message\ResponseFactoryInterface::class)
        );
    },

    \SubTracker\Middleware\VerifiedEmailMiddleware::class => function (ContainerInterface $c): \SubTracker\Middleware\VerifiedEmailMiddleware {
        return new \SubTracker\Middleware\VerifiedEmailMiddleware(
            $c->get(\Psr\Http\Message\ResponseFactoryInterface::class)
        );
    },

    \SubTracker\Controllers\AuthController::class => function (ContainerInterface $c): \SubTracker\Controllers\AuthController {
        return new \SubTracker\Controllers\AuthController(
            $c->get(PDO::class),
            $c->get(\SubTracker\Services\RateLimiter::class),
            $c->get(\SubTracker\Services\MailService::class),
            $c->get('settings')
        );
    },

    \SubTracker\Repositories\ProfileRepository::class => function (ContainerInterface $c): \SubTracker\Repositories\ProfileRepository {
        return new \SubTracker\Repositories\ProfileRepository($c->get(PDO::class));
    },

    \SubTracker\Controllers\ProfileController::class => function (ContainerInterface $c): \SubTracker\Controllers\ProfileController {
        return new \SubTracker\Controllers\ProfileController(
            $c->get(\SubTracker\Repositories\ProfileRepository::class)
        );
    },

    \SubTracker\Repositories\CategoryRepository::class => function (ContainerInterface $c): \SubTracker\Repositories\CategoryRepository {
        return new \SubTracker\Repositories\CategoryRepository($c->get(PDO::class));
    },

    \SubTracker\Controllers\CategoryController::class => function (ContainerInterface $c): \SubTracker\Controllers\CategoryController {
        return new \SubTracker\Controllers\CategoryController(
            $c->get(\SubTracker\Repositories\CategoryRepository::class)
        );
    },

    \SubTracker\Services\AlertService::class => function (ContainerInterface $c): \SubTracker\Services\AlertService {
        return new \SubTracker\Services\AlertService($c->get(PDO::class));
    },

    \SubTracker\Services\BudgetService::class => function (ContainerInterface $c): \SubTracker\Services\BudgetService {
        return new \SubTracker\Services\BudgetService(
            $c->get(PDO::class),
            $c->get(\SubTracker\Services\AlertService::class)
        );
    },

    \SubTracker\Controllers\DashboardController::class => function (ContainerInterface $c): \SubTracker\Controllers\DashboardController {
        return new \SubTracker\Controllers\DashboardController(
            $c->get(\SubTracker\Services\BudgetService::class),
            $c->get(\SubTracker\Services\AlertService::class)
        );
    },

    \SubTracker\Repositories\SubscriptionRepository::class => function (ContainerInterface $c): \SubTracker\Repositories\SubscriptionRepository {
        return new \SubTracker\Repositories\SubscriptionRepository($c->get(PDO::class));
    },

    \SubTracker\Controllers\SubscriptionController::class => function (ContainerInterface $c): \SubTracker\Controllers\SubscriptionController {
        return new \SubTracker\Controllers\SubscriptionController(
            $c->get(\SubTracker\Repositories\SubscriptionRepository::class),
            $c->get(\SubTracker\Repositories\CategoryRepository::class)
        );
    },

    \SubTracker\Controllers\StateController::class => function (ContainerInterface $c): \SubTracker\Controllers\StateController {
        return new \SubTracker\Controllers\StateController(
            $c->get(PDO::class),
            $c->get(\SubTracker\Repositories\ProfileRepository::class),
            $c->get(\SubTracker\Repositories\SubscriptionRepository::class)
        );
    },

    \SubTracker\Repositories\PaymentRepository::class => function (ContainerInterface $c): \SubTracker\Repositories\PaymentRepository {
        return new \SubTracker\Repositories\PaymentRepository($c->get(PDO::class));
    },

    \SubTracker\Controllers\PaymentController::class => function (ContainerInterface $c): \SubTracker\Controllers\PaymentController {
        return new \SubTracker\Controllers\PaymentController(
            $c->get(\SubTracker\Repositories\PaymentRepository::class)
        );
    },

    \SubTracker\Services\BackupService::class => function (ContainerInterface $c): \SubTracker\Services\BackupService {
        return new \SubTracker\Services\BackupService(
            $c->get(PDO::class),
            $c->get(\SubTracker\Repositories\ProfileRepository::class),
            $c->get(\SubTracker\Repositories\SubscriptionRepository::class),
            $c->get(\SubTracker\Repositories\PaymentRepository::class),
            $c->get(\SubTracker\Repositories\CategoryRepository::class),
            $c->get(\SubTracker\Services\RateLimiter::class)
        );
    },

    \SubTracker\Controllers\BackupController::class => function (ContainerInterface $c): \SubTracker\Controllers\BackupController {
        return new \SubTracker\Controllers\BackupController(
            $c->get(\SubTracker\Services\BackupService::class),
            $c->get(\SubTracker\Repositories\SubscriptionRepository::class)
        );
    },
];
