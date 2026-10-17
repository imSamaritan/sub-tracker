<?php
declare(strict_types=1);

namespace SubTracker;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;

class App
{
    /**
     * Bootstrap and create the configured Slim 4 application instance.
     *
     * @param string|null $baseDir Base directory of the api package (defaults to parent of src/)
     * @return SlimApp
     */
    public static function create(?string $baseDir = null): SlimApp
    {
        $baseDir = $baseDir ?? dirname(__DIR__);

        // 1. Ensure environment variables are loaded (supports api/.env locally and /home/<user>/.env on cPanel)
        if (file_exists($baseDir . '/.env')) {
            $dotenv = Dotenv::createImmutable($baseDir);
            $dotenv->safeLoad();
        } elseif (file_exists(dirname($baseDir) . '/.env')) {
            $dotenv = Dotenv::createImmutable(dirname($baseDir));
            $dotenv->safeLoad();
        }

        // 2. Strict UTC timezone enforcement on PHP runtime
        date_default_timezone_set('UTC');

        // 3. Build PHP-DI container
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(require __DIR__ . '/dependencies.php');
        $container = $containerBuilder->build();

        // 4. Instantiate Slim application with the DI container
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // 5. Configure URL base path (e.g. /projects/sub-tracker/api/public or /api)
        $basePath = rtrim((string) ($_ENV['APP_BASE_PATH'] ?? ''), '/');
        if ($basePath !== '') {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            $altBasePath = (string) preg_replace('#/public$#', '', $basePath);
            if (
                $requestUri !== ''
                && !str_starts_with($requestUri, $basePath)
                && $altBasePath !== ''
                && str_starts_with($requestUri, $altBasePath)
            ) {
                $app->setBasePath($altBasePath);
            } else {
                $app->setBasePath($basePath);
            }
        }

        // 6. Register middleware pipeline (Slim runs middleware in LIFO order)
        // Inner: parses JSON request bodies and rejects malformed JSON with 400
        $app->add($container->get(\SubTracker\Middleware\JsonBodyParserMiddleware::class));

        // Routing middleware
        $app->addRoutingMiddleware();

        // Method override (X-HTTP-Method-Override for PUT/PATCH/DELETE under WAF) — runs before routing in LIFO order
        $app->add(new MethodOverrideMiddleware());

        // LiteSpeed / Apache FastCGI authorization header normalization
        $app->add($container->get(\SubTracker\Middleware\LiteSpeedAuthMiddleware::class));

        // Outermost: Catch all exceptions and return standardized JSON error envelopes
        $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $errorMiddleware = $app->addErrorMiddleware($debug, true, true);
        $errorMiddleware->setDefaultErrorHandler($container->get(\SubTracker\Middleware\ApiErrorHandler::class));

        // 7. Register routes
        $routes = require __DIR__ . '/routes.php';
        $routes($app);

        return $app;
    }
}
