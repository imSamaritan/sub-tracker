<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;

echo "========================================================\n";
echo " SubTracker Backend — Task 6 Automated Verification\n";
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

$app = App::create();
$container = $app->getContainer();
$basePath = $app->getBasePath();
$reqFactory = new ServerRequestFactory();
$streamFactory = new StreamFactory();
/** @var PDO $pdo */
$pdo = $container->get(PDO::class);

function makeRequest(\Slim\App $app, string $method, string $path, ?array $body = null, ?string $token = null, array $server = []): \Psr\Http\Message\ResponseInterface {
    global $basePath, $reqFactory, $streamFactory;
    $uri = $basePath . $path;
    $request = $reqFactory->createServerRequest($method, $uri, $server);

    if ($token !== null) {
        $request = $request->withHeader('Authorization', 'Bearer ' . $token);
    }

    if ($body !== null) {
        $request = $request
            ->withHeader('Content-Type', 'application/json')
            ->withBody($streamFactory->createStream((string) json_encode($body)));
    }

    return $app->handle($request);
}

// ---------------------------------------------------------
// Helper: Create a verified test user with given timezone and salary
// ---------------------------------------------------------
function createTestUser(PDO $pdo, string $email, float $salary = 20000.0, string $tz = 'Africa/Johannesburg'): array {
    $hash = password_hash('Pass#1234', PASSWORD_BCRYPT);
    $pdo->prepare("DELETE FROM users WHERE email = :email")->execute([':email' => $email]);

    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, display_name, email_verified_at, created_at, updated_at)
        VALUES (:email, :hash, 'Test User', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");
    $stmt->execute([':email' => $email, ':hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();

    $profileStmt = $pdo->prepare("
        INSERT INTO user_profiles (user_id, monthly_salary, salary_day, currency, currency_symbol, system_notifications_enabled, theme, timezone, created_at, updated_at)
        VALUES (:user_id, :salary, 25, 'ZAR', 'R', 0, 'dark', :tz, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE monthly_salary = VALUES(monthly_salary), timezone = VALUES(timezone)
    ");
    $profileStmt->execute([':user_id' => $userId, ':salary' => $salary, ':tz' => $tz]);

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $tokenStmt = $pdo->prepare("
        INSERT INTO auth_tokens (user_id, token_hash, device_name, expires_at, created_at)
        VALUES (:user_id, :token_hash, 'PHPUnit', UTC_TIMESTAMP() + INTERVAL 30 DAY, UTC_TIMESTAMP())
    ");
    $tokenStmt->execute([':user_id' => $userId, ':token_hash' => $tokenHash]);

    return ['userId' => $userId, 'email' => $email, 'token' => $plainToken];
}

$user = createTestUser($pdo, 'task6_user_' . bin2hex(random_bytes(3)) . '@example.co.za', 20000.0);

// ---------------------------------------------------------
// 1. Test GET /v1/categories
// ---------------------------------------------------------
echo "--- 1. Categories Endpoint (GET /v1/categories) ---\n";
$pdo->exec("TRUNCATE TABLE rate_limits");
// A. Auth required
$resCatNoAuth = makeRequest($app, 'GET', '/v1/categories');
assertTest("GET /v1/categories requires authentication (HTTP 401)", $resCatNoAuth->getStatusCode() === 401);

// B. Authenticated
$resCat = makeRequest($app, 'GET', '/v1/categories', null, $user['token']);
assertTest("GET /v1/categories returns HTTP 200", $resCat->getStatusCode() === 200);
assertTest("GET /v1/categories has Cache-Control: max-age=86400", str_contains($resCat->getHeaderLine('Cache-Control'), '86400'));

$jsonCat = json_decode((string) $resCat->getBody(), true);
$categories = $jsonCat['data'] ?? [];
assertTest("Categories list contains 14 seeded categories", count($categories) === 14, "Found: " . count($categories));

$firstCat = $categories[0] ?? [];
assertTest("Category schema contains code, label, defaultIcon, defaultColor, sortOrder",
    isset($firstCat['code'], $firstCat['label'], $firstCat['defaultIcon'], $firstCat['defaultColor'], $firstCat['sortOrder']));
assertTest("First category is Entertainment with fa-tv", ($firstCat['code'] ?? '') === 'Entertainment' && ($firstCat['defaultIcon'] ?? '') === 'fa-tv');

// ---------------------------------------------------------
// 2. Test GET /v1/alerts
// ---------------------------------------------------------
echo "\n--- 2. Alerts Urgency Classifier (GET /v1/alerts) ---\n";
// Set up test subscriptions with relative dates in user's timezone:
// User today:
$tz = new DateTimeZone('Africa/Johannesburg');
$todayObj = new DateTimeImmutable('now', $tz);
$todayStr = $todayObj->format('Y-m-d');
$overdueStr = $todayObj->modify('-2 days')->format('Y-m-d');
$tomorrowStr = $todayObj->modify('+1 day')->format('Y-m-d');
$in3DaysStr = $todayObj->modify('+3 days')->format('Y-m-d');
$in5DaysStr = $todayObj->modify('+5 days')->format('Y-m-d');

// Clear existing subs for test user
$pdo->prepare("DELETE FROM subscriptions WHERE user_id = :uid")->execute([':uid' => $user['userId']]);

// Sub 1: Overdue by 2 days (unpaid, active) -> should be alert type: 'overdue', days: -2
$pdo->prepare("
    INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, is_paid_this_cycle, status, created_at, updated_at)
    VALUES (:uid, 1, 'Overdue Bill', 100.00, 'monthly', :due, 0, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
")->execute([':uid' => $user['userId'], ':due' => $overdueStr]);

// Sub 2: Due Today (unpaid, active) -> should be alert type: 'today', days: 0
$pdo->prepare("
    INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, is_paid_this_cycle, status, created_at, updated_at)
    VALUES (:uid, 1, 'Today Bill', 200.00, 'monthly', :due, 0, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
")->execute([':uid' => $user['userId'], ':due' => $todayStr]);

// Sub 3: Due Tomorrow (unpaid, active) -> should be alert type: 'urgent', days: 1
$pdo->prepare("
    INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, is_paid_this_cycle, status, created_at, updated_at)
    VALUES (:uid, 1, 'Tomorrow Bill', 300.00, 'monthly', :due, 0, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
")->execute([':uid' => $user['userId'], ':due' => $tomorrowStr]);

// Sub 4: Due in 3 days (unpaid, active) -> should be alert type: 'urgent', days: 3
$pdo->prepare("
    INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, is_paid_this_cycle, status, created_at, updated_at)
    VALUES (:uid, 1, '3-Days Bill', 400.00, 'monthly', :due, 0, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
")->execute([':uid' => $user['userId'], ':due' => $in3DaysStr]);

// Sub 5: Due in 5 days (unpaid, active) -> days > 3 -> should NOT appear in alerts
$pdo->prepare("
    INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, is_paid_this_cycle, status, created_at, updated_at)
    VALUES (:uid, 1, '5-Days Bill', 500.00, 'monthly', :due, 0, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
")->execute([':uid' => $user['userId'], ':due' => $in5DaysStr]);

// Sub 6: Due Tomorrow but ALREADY PAID -> should NOT appear in alerts
$pdo->prepare("
    INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, is_paid_this_cycle, status, created_at, updated_at)
    VALUES (:uid, 1, 'Paid Bill', 600.00, 'monthly', :due, 1, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
")->execute([':uid' => $user['userId'], ':due' => $tomorrowStr]);

// Sub 7: Due Tomorrow but PAUSED -> should NOT appear in alerts
$pdo->prepare("
    INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, is_paid_this_cycle, status, created_at, updated_at)
    VALUES (:uid, 1, 'Paused Bill', 700.00, 'monthly', :due, 0, 'paused', UTC_TIMESTAMP(), UTC_TIMESTAMP())
")->execute([':uid' => $user['userId'], ':due' => $tomorrowStr]);

// Sub 8: Due Tomorrow but SOFT-DELETED -> should NOT appear in alerts
$pdo->prepare("
    INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, is_paid_this_cycle, status, deleted_at, created_at, updated_at)
    VALUES (:uid, 1, 'Deleted Bill', 800.00, 'monthly', :due, 0, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
")->execute([':uid' => $user['userId'], ':due' => $tomorrowStr]);

$resAlerts = makeRequest($app, 'GET', '/v1/alerts', null, $user['token']);
assertTest("GET /v1/alerts returns HTTP 200", $resAlerts->getStatusCode() === 200);
$jsonAlerts = json_decode((string) $resAlerts->getBody(), true);
$alerts = $jsonAlerts['data'] ?? [];

assertTest("Exactly 4 alerts returned (overdue, today, tomorrow, 3-days)", count($alerts) === 4, "Count: " . count($alerts));

// Verify sorting by days ascending
assertTest("Alert 0 is overdue (-2 days)", ($alerts[0]['days'] ?? null) === -2 && ($alerts[0]['type'] ?? null) === 'overdue');
assertTest("Alert 1 is today (0 days)", ($alerts[1]['days'] ?? null) === 0 && ($alerts[1]['type'] ?? null) === 'today');
assertTest("Alert 2 is tomorrow (1 day)", ($alerts[2]['days'] ?? null) === 1 && ($alerts[2]['type'] ?? null) === 'urgent');
assertTest("Alert 3 is 3-days (3 days)", ($alerts[3]['days'] ?? null) === 3 && ($alerts[3]['type'] ?? null) === 'urgent');

// ---------------------------------------------------------
// 3. Test GET /v1/dashboard/summary
// ---------------------------------------------------------
echo "\n--- 3. Dashboard Budget Summary (GET /v1/dashboard/summary) ---\n";
$resSummary = makeRequest($app, 'GET', '/v1/dashboard/summary', null, $user['token']);
assertTest("GET /v1/dashboard/summary returns HTTP 200", $resSummary->getStatusCode() === 200);
$jsonSummary = json_decode((string) $resSummary->getBody(), true);
$summary = $jsonSummary['data'] ?? [];

// Active subscriptions: Subs 1, 2, 3, 4, 5, 6 (100 + 200 + 300 + 400 + 500 + 600 = 2100)
// Sub 7 is paused (excluded from active), Sub 8 is deleted (excluded)
assertTest("activeSubscriptions is 6", ($summary['activeSubscriptions'] ?? null) === 6, "Got: " . ($summary['activeSubscriptions'] ?? 'none'));
assertTest("totalCommittedMonthly is 2100.0", (float) ($summary['totalCommittedMonthly'] ?? 0) === 2100.0);
assertTest("totalPaidThisCycle is 600.0 (Sub 6)", (float) ($summary['totalPaidThisCycle'] ?? 0) === 600.0);
assertTest("totalDueThisCycle is 1500.0 (Subs 1,2,3,4,5)", (float) ($summary['totalDueThisCycle'] ?? 0) === 1500.0);
// Remaining: 20000 - 2100 = 17900.0
assertTest("remainingBalance is 17900.0", (float) ($summary['remainingBalance'] ?? 0) === 17900.0);
// Utilization %: 2100 / 20000 * 100 = 10.5%
assertTest("budgetUtilizationPercent is 10.5", (float) ($summary['budgetUtilizationPercent'] ?? 0) === 10.5);
assertTest("alertCount is 4 (matches alerts count)", ($summary['alertCount'] ?? null) === 4);

// ---------------------------------------------------------
// 4. Test Demo User Summary Parity (Section 8.7 contract)
// ---------------------------------------------------------
echo "\n--- 4. Demo User Summary Parity (§8.7) ---\n";
// Login as demo user
$resDemoLogin = makeRequest($app, 'POST', '/v1/auth/login', [
    'email' => 'demo@subtracker.local',
    'password' => 'Demo@1234',
]);
$demoToken = json_decode((string) $resDemoLogin->getBody(), true)['data']['token'] ?? null;
assertTest("Demo user login succeeds", !empty($demoToken));

$resDemoSummary = makeRequest($app, 'GET', '/v1/dashboard/summary', null, $demoToken);
assertTest("Demo user summary returns HTTP 200", $resDemoSummary->getStatusCode() === 200);
$demoSummary = json_decode((string) $resDemoSummary->getBody(), true)['data'] ?? [];

assertTest("Demo user monthlySalary is 28500.0", (float) ($demoSummary['monthlySalary'] ?? 0) === 28500.0);
assertTest("Demo user activeSubscriptions is 5", ($demoSummary['activeSubscriptions'] ?? null) === 5);
assertTest("Demo user totalCommittedMonthly is 2261.99", (float) ($demoSummary['totalCommittedMonthly'] ?? 0) === 2261.99);
assertTest("Demo user totalPaidThisCycle is 64.99", (float) ($demoSummary['totalPaidThisCycle'] ?? 0) === 64.99);
assertTest("Demo user totalDueThisCycle is 2197.0", (float) ($demoSummary['totalDueThisCycle'] ?? 0) === 2197.0);
assertTest("Demo user remainingBalance is 26238.01", (float) ($demoSummary['remainingBalance'] ?? 0) === 26238.01);
assertTest("Demo user budgetUtilizationPercent is 7.9", (float) ($demoSummary['budgetUtilizationPercent'] ?? 0) === 7.9);

echo "\n--------------------------------------------------------\n";
echo " Task 6 Verification Summary: $passed passed, $failed failed.\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
