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

        // 1. Ensure environment variables are loaded
        if (file_exists($baseDir . '/.env')) {
            $dotenv = Dotenv::createImmutable($baseDir);
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
        $basePath = $_ENV['APP_BASE_PATH'] ?? '';
        if (!empty($basePath)) {
            $app->setBasePath(rtrim($basePath, '/'));
        }

        // 6. Register standard Slim middleware
        $app->addBodyParsingMiddleware();
        $app->add(new MethodOverrideMiddleware());
        $app->addRoutingMiddleware();

        $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $app->addErrorMiddleware($debug, true, true);

        // 7. Register routes
        $routes = require __DIR__ . '/routes.php';
        $routes($app);

        return $app;
    }
}
