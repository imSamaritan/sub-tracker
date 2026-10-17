<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$envDir = file_exists(__DIR__ . '/.env')
    ? __DIR__
    : (file_exists(dirname(__DIR__) . '/.env') ? dirname(__DIR__) : __DIR__);

if (file_exists($envDir . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($envDir);
    $dotenv->safeLoad();
}

$env = static fn(string $key, string $default = ''): string =>
    (string) ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default);

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => ($env('APP_ENV', 'local') === 'production') ? 'production' : 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $env('DB_HOST', '127.0.0.1'),
            'name' => $env('DB_NAME', 'subtracker'),
            'user' => $env('DB_USER', 'root'),
            'pass' => $env('DB_PASS', ''),
            'port' => (int) $env('DB_PORT', '3306'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
        'production' => [
            'adapter' => 'mysql',
            'host' => $env('DB_HOST', 'localhost'),
            'name' => $env('DB_NAME', 'subtracker'),
            'user' => $env('DB_USER', 'root'),
            'pass' => $env('DB_PASS', ''),
            'port' => (int) $env('DB_PORT', '3306'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];
