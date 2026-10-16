<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;

echo "========================================================\n";
echo " SubTracker Backend — Task 9 Automated Verification\n";
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

// Reset rate limits table so test runs are deterministic
$pdo->exec("TRUNCATE TABLE rate_limits");

function makeRequest(\Slim\App $app, string $method, string $path, ?array $body = null, ?string $token = null): \Psr\Http\Message\ResponseInterface {
    global $basePath, $reqFactory, $streamFactory;
    $uri = $basePath . $path;
    $request = $reqFactory->createServerRequest($method, $uri);

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
// Helper: Create a verified test user
// ---------------------------------------------------------
function createTestUser(PDO $pdo, string $email): array {
    $hash = password_hash('Pass#1234', PASSWORD_BCRYPT);
    $pdo->prepare("DELETE FROM users WHERE email = :email")->execute([':email' => $email]);

    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, display_name, email_verified_at, created_at, updated_at)
        VALUES (:email, :hash, 'Backup Tester', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");
    $stmt->execute([':email' => $email, ':hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO user_profiles (user_id, monthly_salary, salary_day, currency, currency_symbol, timezone, created_at, updated_at)
        VALUES (:uid, 18000.00, 20, 'ZAR', 'R', 'Africa/Johannesburg', UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ")->execute([':uid' => $userId]);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("
        INSERT INTO auth_tokens (user_id, token_hash, expires_at, created_at)
        VALUES (:uid, :thash, UTC_TIMESTAMP() + INTERVAL 30 DAY, UTC_TIMESTAMP())
    ")->execute([':uid' => $userId, ':thash' => hash('sha256', $token)]);

    return ['id' => $userId, 'token' => $token];
}

$user = createTestUser($pdo, 'backup_tester_' . bin2hex(random_bytes(3)) . '@example.com');

// ---------------------------------------------------------
// 1. Auth Guards
// ---------------------------------------------------------
echo "--- 1. Auth & Security Guards ---\n";
$resExpNoAuth = makeRequest($app, 'GET', '/v1/backup');
assertTest("GET /v1/backup requires auth (HTTP 401)", $resExpNoAuth->getStatusCode() === 401);

$resImpNoAuth = makeRequest($app, 'POST', '/v1/backup', ['profile' => [], 'subscriptions' => []]);
assertTest("POST /v1/backup requires auth (HTTP 401)", $resImpNoAuth->getStatusCode() === 401);

$resRstNoAuth = makeRequest($app, 'POST', '/v1/account/reset-demo');
assertTest("POST /v1/account/reset-demo requires auth (HTTP 401)", $resRstNoAuth->getStatusCode() === 401);

// ---------------------------------------------------------
// 2. Export Backup (GET /v1/backup)
// ---------------------------------------------------------
echo "\n--- 2. Export Backup (GET /v1/backup) ---\n";
// Create a subscription for the test user so export is non-trivial
makeRequest($app, 'POST', '/v1/subscriptions', [
    'name' => 'Initial Sub Before Export',
    'category' => 'Cloud',
    'amount' => 120.00,
    'billingCycle' => 'monthly',
    'dueDate' => '2026-10-30',
], $user['token']);

$resExport = makeRequest($app, 'GET', '/v1/backup', null, $user['token']);
assertTest("GET /v1/backup returns HTTP 200", $resExport->getStatusCode() === 200);

$dispHeader = $resExport->getHeaderLine('Content-Disposition');
assertTest("Content-Disposition header includes attachment and .json filename",
    str_contains($dispHeader, 'attachment') && str_contains($dispHeader, 'subtracker_backup_'));

$jsonExport = json_decode((string) $resExport->getBody(), true);
assertTest("Export contains profile object", isset($jsonExport['profile']['monthlySalary']));
assertTest("Export contains subscriptions array with 1 item", isset($jsonExport['subscriptions']) && count($jsonExport['subscriptions']) === 1);
assertTest("Export contains paymentHistory array", isset($jsonExport['paymentHistory']) && is_array($jsonExport['paymentHistory']));

// ---------------------------------------------------------
// 3. Restore Validations (POST /v1/backup)
// ---------------------------------------------------------
echo "\n--- 3. Restore Validations (POST /v1/backup) ---\n";
// Missing profile and subscriptions
$resValEmpty = makeRequest($app, 'POST', '/v1/backup', [], $user['token']);
assertTest("Empty payload returns HTTP 422", $resValEmpty->getStatusCode() === 422);

// Invalid subscription inside backup
$resValSub = makeRequest($app, 'POST', '/v1/backup', [
    'profile' => ['monthlySalary' => 20000],
    'subscriptions' => [
        [
            'name' => 'Valid Sub 1',
            'amount' => 100,
            'billingCycle' => 'monthly',
            'dueDate' => '2026-10-25'
        ],
        [
            'name' => '', // invalid empty name
            'amount' => -20, // invalid negative amount
            'billingCycle' => 'daily', // invalid cycle
            'dueDate' => 'invalid-date'
        ]
    ]
], $user['token']);

assertTest("Invalid subscription in array returns HTTP 422", $resValSub->getStatusCode() === 422);
$jsonValSub = json_decode((string) $resValSub->getBody(), true);
$fields = $jsonValSub['error']['fields'] ?? [];
assertTest("Indexed error fields returned (subscriptions[1].name, amount, billingCycle, dueDate)",
    isset($fields['subscriptions[1].name'], $fields['subscriptions[1].amount'], $fields['subscriptions[1].billingCycle'], $fields['subscriptions[1].dueDate']));

// ---------------------------------------------------------
// 4. Atomic Restore with Legacy ID Mapping & Category Fallback
// ---------------------------------------------------------
echo "\n--- 4. Restore Execution & Legacy ID Mapping ---\n";
$legacyBackup = [
    'profile' => [
        'monthlySalary' => 35000.00,
        'salaryDay' => 28,
        'theme' => 'light',
        'systemNotificationsEnabled' => true,
        'timezone' => 'Africa/Johannesburg'
    ],
    'subscriptions' => [
        [
            'id' => 'legacy_sub_001_aws', // legacy string ID
            'name' => 'Amazon Web Services',
            'category' => 'Cloud',
            'amount' => 540.50,
            'billingCycle' => 'monthly',
            'dueDate' => '2026-11-05',
            'isPaidThisCycle' => false,
            'notes' => 'Cloud servers'
        ],
        [
            'id' => 'legacy_sub_002_custom',
            'name' => 'Special Custom Service',
            'category' => 'NonExistentCategoryCode', // must fallback to 'Other'
            'amount' => 150.00,
            'billingCycle' => 'monthly',
            'dueDate' => '2026-11-12',
            'isPaidThisCycle' => true,
        ]
    ],
    'paymentHistory' => [
        [
            'subscriptionId' => 'legacy_sub_001_aws', // links to legacy string ID
            'name' => 'Amazon Web Services',
            'amount' => 540.50,
            'cycleDueDate' => '2026-10-05',
            'paidDate' => '2026-10-05'
        ],
        [
            'subscriptionId' => 'unmatched_old_deleted_sub', // unmatched ID -> should set subscription_id = NULL
            'name' => 'Old Dropped Service',
            'amount' => 89.00,
            'cycleDueDate' => '2026-09-01',
            'paidDate' => '2026-09-01'
        ]
    ]
];

$resRestore = makeRequest($app, 'POST', '/v1/backup', $legacyBackup, $user['token']);
assertTest("Restore returns HTTP 200", $resRestore->getStatusCode() === 200);
$restoreData = json_decode((string) $resRestore->getBody(), true)['data'] ?? [];
assertTest("Restore response reports 2 subscriptions", ($restoreData['subscriptions'] ?? null) === 2);
assertTest("Restore response reports 2 payments", ($restoreData['payments'] ?? null) === 2);

// Verify profile updated in database
$stmtProf = $pdo->prepare("SELECT monthly_salary, salary_day, theme, system_notifications_enabled FROM user_profiles WHERE user_id = :uid");
$stmtProf->execute([':uid' => $user['id']]);
$profRow = $stmtProf->fetch(PDO::FETCH_ASSOC);
assertTest("Profile updated: monthly_salary = 35000.00", (float) $profRow['monthly_salary'] === 35000.00);
assertTest("Profile updated: salary_day = 28 and theme = 'light'", (int) $profRow['salary_day'] === 28 && $profRow['theme'] === 'light');

// Verify subscriptions replaced and old sub dropped
$resSubList = makeRequest($app, 'GET', '/v1/subscriptions', null, $user['token']);
$subList = json_decode((string) $resSubList->getBody(), true)['data'] ?? [];
assertTest("GET /v1/subscriptions returns exactly the 2 restored subscriptions", count($subList) === 2);

$awsSub = null;
$customSub = null;
foreach ($subList as $s) {
    if ($s['name'] === 'Amazon Web Services') $awsSub = $s;
    if ($s['name'] === 'Special Custom Service') $customSub = $s;
}

assertTest("AWS subscription created with integer ID", !empty($awsSub['id']) && is_int($awsSub['id']));
assertTest("Special Custom Service mapped to 'Other' category", ($customSub['category'] ?? '') === 'Other');
assertTest("Special Custom Service preserved isPaidThisCycle true", ($customSub['isPaidThisCycle'] ?? null) === true);

// Verify payments relinked
$resPmtList = makeRequest($app, 'GET', '/v1/payments', null, $user['token']);
$pmtList = json_decode((string) $resPmtList->getBody(), true)['data'] ?? [];
assertTest("GET /v1/payments returns 2 restored payments", count($pmtList) === 2);

$awsPmt = null;
$unmatchedPmt = null;
foreach ($pmtList as $p) {
    if ($p['name'] === 'Amazon Web Services') $awsPmt = $p;
    if ($p['name'] === 'Old Dropped Service') $unmatchedPmt = $p;
}

assertTest("AWS payment relinked to new AWS subscription integer ID", ($awsPmt['subscriptionId'] ?? null) === $awsSub['id']);
assertTest("Unmatched payment inserted with subscriptionId null", array_key_exists('subscriptionId', $unmatchedPmt) && $unmatchedPmt['subscriptionId'] === null);

// ---------------------------------------------------------
// 5. Demo Reset Endpoint (POST /v1/account/reset-demo)
// ---------------------------------------------------------
echo "\n--- 5. Demo Reset Endpoint (§8.10) ---\n";
$resReset = makeRequest($app, 'POST', '/v1/account/reset-demo', null, $user['token']);
assertTest("POST /v1/account/reset-demo returns HTTP 200", $resReset->getStatusCode() === 200);
$resetJson = json_decode((string) $resReset->getBody(), true)['data'] ?? [];
assertTest("Reset reports 5 subscriptions and 1 payment",
    ($resetJson['subscriptions'] ?? null) === 5 && ($resetJson['payments'] ?? null) === 1);

// Verify user profile reset to standard demo defaults
$stmtResetProf = $pdo->prepare("SELECT monthly_salary, salary_day, theme FROM user_profiles WHERE user_id = :uid");
$stmtResetProf->execute([':uid' => $user['id']]);
$resetProf = $stmtResetProf->fetch(PDO::FETCH_ASSOC);
assertTest("Profile salary reset to 28500.00", (float) $resetProf['monthly_salary'] === 28500.00);
assertTest("Profile salary_day reset to 25 and theme to 'dark'", (int) $resetProf['salary_day'] === 25 && $resetProf['theme'] === 'dark');

// Verify dashboard summary parity
$resSum = makeRequest($app, 'GET', '/v1/dashboard/summary', null, $user['token']);
$sumData = json_decode((string) $resSum->getBody(), true)['data'] ?? [];
assertTest("Dashboard summary activeSubscriptions is 5", ($sumData['activeSubscriptions'] ?? null) === 5);
assertTest("Dashboard summary totalCommittedMonthly is 2261.99", (float) ($sumData['totalCommittedMonthly'] ?? 0) === 2261.99);
assertTest("Dashboard summary totalPaidThisCycle is 64.99 (Spotify)", (float) ($sumData['totalPaidThisCycle'] ?? 0) === 64.99);
assertTest("Dashboard summary totalDueThisCycle is 2197.0", (float) ($sumData['totalDueThisCycle'] ?? 0) === 2197.0);

// Rate-limiting check: max 3 resets per hour per user
// We just did reset 1. Let's do reset 2 and 3:
$res2 = makeRequest($app, 'POST', '/v1/account/reset-demo', null, $user['token']);
assertTest("Reset attempt 2 returns HTTP 200", $res2->getStatusCode() === 200);

$res3 = makeRequest($app, 'POST', '/v1/account/reset-demo', null, $user['token']);
assertTest("Reset attempt 3 returns HTTP 200", $res3->getStatusCode() === 200);

// Reset attempt 4 should trigger 429 RATE_LIMITED
$res4 = makeRequest($app, 'POST', '/v1/account/reset-demo', null, $user['token']);
assertTest("Reset attempt 4 returns HTTP 429 RATE_LIMITED", $res4->getStatusCode() === 429);
assertTest("Response has Retry-After header", !empty($res4->getHeaderLine('Retry-After')));
$json4 = json_decode((string) $res4->getBody(), true);
assertTest("Error code is RATE_LIMITED", ($json4['error']['code'] ?? '') === 'RATE_LIMITED');

echo "\n--------------------------------------------------------\n";
echo " Task 9 Verification Summary: $passed passed, $failed failed.\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
