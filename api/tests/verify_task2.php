<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use SubTracker\App;

echo "========================================================\n";
echo " SubTracker Backend — Task 2 Automated Verification\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $name\n";
        if ($details) {
            echo "        $details\n";
        }
        $passed++;
    } else {
        echo " [FAIL] $name\n";
        if ($details) {
            echo "        Error: $details\n";
        }
        $failed++;
    }
}

// 1. Test Slim App Creation & DI Container
try {
    $app = App::create();
    assertTest("Slim 4 App instantiation via App::create()", true);
} catch (Throwable $e) {
    assertTest("Slim 4 App instantiation via App::create()", false, $e->getMessage());
    exit(1);
}

// 2. Test Container resolution
$container = $app->getContainer();
assertTest("PHP-DI container attached to Slim App", $container !== null);

// 3. Test Settings resolution
$settings = $container->get('settings');
assertTest("Container resolved 'settings'", is_array($settings) && isset($settings['db']), "DB name: " . ($settings['db']['database'] ?? 'unknown'));

// 4. Test PDO connection & UTC enforcement
try {
    /** @var PDO $pdo */
    $pdo = $container->get(PDO::class);
    assertTest("Container resolved PDO::class instance", $pdo instanceof PDO);

    $stmt = $pdo->query("SELECT @@session.time_zone AS session_tz, @@global.time_zone AS global_tz");
    $tzRow = $stmt->fetch();
    assertTest(
        "PDO session timezone is strictly UTC (+00:00)",
        $tzRow['session_tz'] === '+00:00',
        "Session TZ: {$tzRow['session_tz']}, Global TZ: {$tzRow['global_tz']}"
    );

    assertTest(
        "PHP runtime default timezone is UTC",
        date_default_timezone_get() === 'UTC',
        "date_default_timezone_get() = " . date_default_timezone_get()
    );

    // Verify database tables created in Task 1
    $tablesStmt = $pdo->query("SHOW TABLES");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
    $expectedTables = ['users', 'user_profiles', 'auth_tokens', 'categories', 'subscriptions', 'payments', 'rate_limits'];
    $allPresent = count(array_intersect($expectedTables, $tables)) === count($expectedTables);
    assertTest(
        "Database schema verified (" . count($tables) . " tables found)",
        $allPresent,
        "Found tables: " . implode(', ', $tables)
    );

} catch (Throwable $e) {
    assertTest("PDO execution & verification", false, $e->getMessage());
}

// 5. Test HTTP Request to /health endpoint through Slim pipeline
try {
    $basePath = $app->getBasePath();
    $requestFactory = new ServerRequestFactory();
    $request = $requestFactory->createServerRequest('GET', $basePath . '/health');
    $response = $app->handle($request);

    assertTest("Route GET /health returns HTTP 200", $response->getStatusCode() === 200, "Status: " . $response->getStatusCode());
    
    $contentType = $response->getHeaderLine('Content-Type');
    assertTest("Content-Type is application/json", str_contains($contentType, 'application/json'), "Content-Type: $contentType");

    $body = (string) $response->getBody();
    $json = json_decode($body, true);
    assertTest("Response envelope has 'data' key", is_array($json) && isset($json['data']), "Raw: $body");
    assertTest("Health status is healthy", ($json['data']['status'] ?? null) === 'healthy');
    assertTest("Database health connected is true", ($json['data']['database']['connected'] ?? null) === true);
    assertTest("Database reported sessionTimezone is +00:00", ($json['data']['database']['sessionTimezone'] ?? null) === '+00:00');

} catch (Throwable $e) {
    assertTest("Slim pipeline execution (/health)", false, $e->getMessage());
}

// 6. Test HTTP Request to /v1/health endpoint
try {
    $request = (new ServerRequestFactory())->createServerRequest('GET', $basePath . '/v1/health');
    $response = $app->handle($request);

    assertTest("Route GET /v1/health returns HTTP 200", $response->getStatusCode() === 200, "Status: " . $response->getStatusCode());
    $json = json_decode((string) $response->getBody(), true);
    assertTest("Versioned endpoint (/v1/health) is operational", ($json['data']['version'] ?? null) === '1.0.0');

} catch (Throwable $e) {
    assertTest("Slim pipeline execution (/v1/health)", false, $e->getMessage());
}

echo "\n--------------------------------------------------------\n";
echo " Verification Summary: $passed passed, $failed failed.\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
