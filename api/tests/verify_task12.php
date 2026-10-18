<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;
use SubTracker\Services\MailService;

echo "========================================================\n";
echo " SubTracker Production Deployment & Sign-off — Task 12\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$name}\n";
        if ($details !== '') {
            echo "        {$details}\n";
        }
        $passed++;
    } else {
        echo " [FAIL] {$name}\n";
        if ($details !== '') {
            echo "        Error: {$details}\n";
        }
        $failed++;
    }
}

$apiDir = dirname(__DIR__);
$rootDir = dirname($apiDir);
$phpBin = PHP_BINARY;
$phinxBin = $apiDir . '/vendor/bin/phinx';

// =========================================================================
// 1. Production Phinx Migrations & Seeding (§14, §15.3, §16.3)
// =========================================================================
echo "--- 1. Phinx Production Migrations & Seeding (`-e production`) ---\n";

$migrateCmd = sprintf('"%s" "%s" migrate -e production -c "%s"', $phpBin, $phinxBin, $apiDir . '/phinx.php');
exec($migrateCmd . ' 2>&1', $migrateOut, $migrateCode);
assertTest(
    "phinx migrate -e production executes cleanly (exit code 0)",
    $migrateCode === 0,
    "Exit code: {$migrateCode}"
);

$statusCmd = sprintf('"%s" "%s" status -e production -c "%s"', $phpBin, $phinxBin, $apiDir . '/phinx.php');
exec($statusCmd . ' 2>&1', $statusOut, $statusCode);
$statusText = implode("\n", $statusOut);
$upCount = preg_match_all('/^\s*up\s+202610150000\d{2}\s+/m', $statusText);
assertTest(
    "phinx status -e production shows all 12 migrations (001-012) as UP",
    $statusCode === 0 && $upCount === 12,
    "Detected {$upCount}/12 migrations up in production environment"
);

$seedCatCmd = sprintf('"%s" "%s" seed:run -e production -s CategorySeeder -c "%s"', $phpBin, $phinxBin, $apiDir . '/phinx.php');
exec($seedCatCmd . ' 2>&1', $seedCatOut, $seedCatCode);
assertTest(
    "phinx seed:run -e production -s CategorySeeder executes cleanly",
    $seedCatCode === 0
);

$seedDemoCmd = sprintf('"%s" "%s" seed:run -e production -s DemoUserSeeder -c "%s"', $phpBin, $phinxBin, $apiDir . '/phinx.php');
exec($seedDemoCmd . ' 2>&1', $seedDemoOut, $seedDemoCode);
$seedDemoText = implode("\n", $seedDemoOut);
assertTest(
    "DemoUserSeeder is safely blocked when invoked with -e production (§5 / §15.3)",
    $seedDemoCode === 0 && str_contains($seedDemoText, 'Skipping DemoUserSeeder in production environment')
);

// =========================================================================
// 2. Setup, MariaDB Portability & cPanel Release Packaging (§14, §15)
// =========================================================================
echo "\n--- 2. Setup, MariaDB Compatibility & cPanel Release Packaging ---\n";

$gitignore = (string) file_get_contents($rootDir . '/.gitignore');
assertTest(
    ".env.example committed and .gitignore excludes api/vendor/, api/.env, and dist/",
    is_file($apiDir . '/.env.example')
    && str_contains($gitignore, 'api/vendor/')
    && str_contains($gitignore, 'api/.env')
    && str_contains($gitignore, 'dist/')
);

$publicHtaccess = (string) file_get_contents($apiDir . '/public/.htaccess');
assertTest(
    "LiteSpeed .htaccess preserves HTTP_AUTHORIZATION and sets security headers (§15.4)",
    str_contains($publicHtaccess, 'E=HTTP_AUTHORIZATION:%{HTTP:Authorization}')
    && str_contains($publicHtaccess, 'X-Content-Type-Options "nosniff"')
    && str_contains($publicHtaccess, 'X-Frame-Options "DENY"')
    && str_contains($publicHtaccess, 'Referrer-Policy "strict-origin-when-cross-origin"')
);

// Verify no migration uses MySQL-8-only utf8mb4_0900_ai_ci collation (§15.2)
$migrationFiles = glob($apiDir . '/db/migrations/*.php') ?: [];
$has0900Collation = false;
foreach ($migrationFiles as $mFile) {
    if (str_contains((string) file_get_contents($mFile), 'utf8mb4_0900')) {
        $has0900Collation = true;
    }
}
assertTest(
    "All 12 Phinx migrations use MariaDB-compatible utf8mb4_unicode_ci collation (§15.2)",
    count($migrationFiles) === 12 && !$has0900Collation
);

// Verify cPanel split-directory bootstrap.php (§15.1)
$bootstrappedApp = require $apiDir . '/src/bootstrap.php';
assertTest(
    "api/src/bootstrap.php returns a configured Slim\\App instance for cPanel public_html/api/index.php (§15.1)",
    $bootstrappedApp instanceof \Slim\App
);

// Execute build-production.php and verify cPanel release bundle
$buildCmd = sprintf('"%s" "%s" --json', $phpBin, $apiDir . '/bin/build-production.php');
exec($buildCmd . ' 2>&1', $buildOut, $buildCode);
$buildJson = json_decode(implode("\n", $buildOut), true);
assertTest(
    "api/bin/build-production.php generates cPanel NVMe Palladium release bundle (§15.1, §15.9)",
    $buildCode === 0
    && ($buildJson['status'] ?? '') === 'ok'
    && is_file($rootDir . '/dist/cpanel-release/public_html/api/index.php')
    && is_file($rootDir . '/dist/cpanel-release/public_html/api/.htaccess')
    && is_file($rootDir . '/dist/cpanel-release/.env.production.example')
    && is_file($rootDir . '/dist/cpanel-release/cpanel-database-install.sql'),
    "Packaged " . ($buildJson['filesPackaged'] ?? 0) . " files into dist/cpanel-release/"
);

// =========================================================================
// 3. SMTP Email Verification & Cron Jobs Execution (§9, §15.7, §15.8)
// =========================================================================
echo "\n--- 3. SMTP Email Verification & Scheduled Cron Jobs ---\n";

$smtpCmd = sprintf('"%s" "%s" --json', $phpBin, $apiDir . '/bin/verify-smtp.php');
exec($smtpCmd . ' 2>&1', $smtpOut, $smtpCode);
$smtpJson = json_decode(implode("\n", $smtpOut), true);
assertTest(
    "api/bin/verify-smtp.php verifies PHPMailer, OpenSSL, 4 email templates, and deep links (§15.7)",
    $smtpCode === 0
    && ($smtpJson['status'] ?? '') === 'ok'
    && ($smtpJson['verificationEmail']['deepLinkValid'] ?? false) === true
    && ($smtpJson['passwordResetEmail']['deepLinkValid'] ?? false) === true
);

$cleanupCmd = sprintf('"%s" "%s" --json', $phpBin, $apiDir . '/bin/cleanup.php');
exec($cleanupCmd . ' 2>&1', $cleanupOut, $cleanupCode);
$cleanupJson = json_decode(implode("\n", $cleanupOut), true);
assertTest(
    "api/bin/cleanup.php housekeeping cron executes cleanly (§15.8)",
    $cleanupCode === 0 && is_array($cleanupJson) && array_key_exists('rateLimitsDeleted', $cleanupJson)
);

// =========================================================================
// 4. End-to-End Acceptance Checklist (§14)
// =========================================================================
echo "\n--- 4. Full End-to-End Acceptance Checklist Verification (§14) ---\n";

$_ENV['APP_ENV'] = 'test';
$app = App::create();
$container = $app->getContainer();
$pdo = $container->get(PDO::class);
$basePath = $app->getBasePath();
$reqFactory = new ServerRequestFactory();
$streamFactory = new StreamFactory();

$pdo->exec("TRUNCATE TABLE rate_limits");
MailService::clearTestSpool();

function callApi(
    \Slim\App $app,
    string $method,
    string $path,
    ?array $body = null,
    ?string $token = null,
    array $extraHeaders = []
): array {
    global $basePath, $reqFactory, $streamFactory;
    $req = $reqFactory->createServerRequest($method, $basePath . $path);
    if ($token !== null) {
        $req = $req->withHeader('Authorization', 'Bearer ' . $token);
    }
    foreach ($extraHeaders as $hName => $hVal) {
        $req = $req->withHeader($hName, $hVal);
    }
    if ($body !== null) {
        $req = $req
            ->withHeader('Content-Type', 'application/json')
            ->withBody($streamFactory->createStream((string) json_encode($body)));
    }
    $res = $app->handle($req);
    $raw = (string) $res->getBody();
    return [
        'status' => $res->getStatusCode(),
        'headers' => $res->getHeaders(),
        'raw' => $raw,
        'json' => $raw !== '' ? json_decode($raw, true) : null,
    ];
}

$emailA = 'accept_user_a_' . bin2hex(random_bytes(3)) . '@example.co.za';
$emailB = 'accept_user_b_' . bin2hex(random_bytes(3)) . '@example.co.za';
$pdo->prepare("DELETE FROM users WHERE email IN (:a, :b)")->execute([':a' => $emailA, ':b' => $emailB]);

// 4.1 Registration + Unverified Login Block + Email Verification
$regA = callApi($app, 'POST', '/v1/auth/register', [
    'email' => $emailA,
    'password' => 'Accept!Pass123',
    'displayName' => 'User A',
    'timezone' => 'Africa/Johannesburg',
]);
$spool = MailService::getTestSpool();
$verifyTokenA = end($spool)['token'] ?? '';
assertTest(
    "Register creates unverified user, profile, and dispatches verification email (202)",
    $regA['status'] === 202
    && ($regA['json']['data']['requiresVerification'] ?? false) === true
    && $verifyTokenA !== ''
);

$unverifiedLogin = callApi($app, 'POST', '/v1/auth/login', [
    'email' => $emailA,
    'password' => 'Accept!Pass123',
]);
assertTest(
    "Unverified user login attempt returns 403 EMAIL_NOT_VERIFIED",
    $unverifiedLogin['status'] === 403
    && ($unverifiedLogin['json']['error']['code'] ?? '') === 'EMAIL_NOT_VERIFIED'
);

$verA = callApi($app, 'POST', '/v1/auth/verify-email', [
    'token' => $verifyTokenA,
    'deviceName' => 'Acceptance Suite',
]);
$tokenA = $verA['json']['data']['token'] ?? '';
$userIdA = (int) ($verA['json']['data']['user']['id'] ?? 0);
assertTest(
    "POST /v1/auth/verify-email sets email_verified_at and returns active session token (200)",
    $verA['status'] === 200 && $tokenA !== '' && ($verA['json']['data']['user']['emailVerified'] ?? false) === true
);

// 4.2 Wrong password rate limiting (5 failures -> 401, 6th -> 429 with Retry-After)
$pdo->exec("TRUNCATE TABLE rate_limits");
$statuses = [];
$retryAfterHeader = '';
for ($i = 1; $i <= 6; $i++) {
    $badLogin = callApi($app, 'POST', '/v1/auth/login', [
        'email' => $emailA,
        'password' => 'WrongPassword!' . $i,
    ]);
    $statuses[] = $badLogin['status'];
    if ($badLogin['status'] === 429) {
        $retryAfterHeader = $badLogin['headers']['Retry-After'][0] ?? '';
    }
}
assertTest(
    "Wrong password returns 401 INVALID_CREDENTIALS and triggers 429 RATE_LIMITED after 5 attempts",
    $statuses === [401, 401, 401, 401, 401, 429] && $retryAfterHeader !== ''
);
$pdo->exec("TRUNCATE TABLE rate_limits");

// 4.3 Security headers & no sensitive column leakage on authenticated endpoints
$meRes = callApi($app, 'GET', '/v1/auth/me', null, $tokenA);
assertTest(
    "Authenticated responses include X-Content-Type-Options: nosniff and Cache-Control: no-store, without leaking hashes",
    $meRes['status'] === 200
    && ($meRes['headers']['X-Content-Type-Options'][0] ?? '') === 'nosniff'
    && str_contains($meRes['headers']['Cache-Control'][0] ?? '', 'no-store')
    && !str_contains($meRes['raw'], 'password_hash')
    && !str_contains($meRes['raw'], 'token_hash')
);

// 4.4 Subscriptions CRUD, Mark Paid idempotency, Un-mark, Renew (month-end, leap-year, weekly), Reset-paid
$subCreate = callApi($app, 'POST', '/v1/subscriptions', [
    'name' => 'Showmax Pro',
    'category' => 'Entertainment',
    'amount' => 225.00,
    'billingCycle' => 'monthly',
    'dueDate' => '2026-01-31',
    'startDate' => '2026-01-01',
    'icon' => 'fa-tv',
    'color' => '#0084f7',
    'notes' => 'Monthly anchor 31',
], $tokenA);
$subId = (int) ($subCreate['json']['data']['id'] ?? 0);
assertTest(
    "POST /v1/subscriptions creates subscription with billingDay=31 and no deleted_at leak",
    $subCreate['status'] === 201
    && $subId > 0
    && ($subCreate['json']['data']['billingDay'] ?? 0) === 31
    && !array_key_exists('deleted_at', $subCreate['json']['data'])
);

// X-HTTP-Method-Override test (§15.6 ModSecurity / Imunify360 compatibility)
$overridePatch = callApi(
    $app,
    'POST',
    "/v1/subscriptions/{$subId}",
    ['notes' => 'Updated via X-HTTP-Method-Override'],
    $tokenA,
    ['X-HTTP-Method-Override' => 'PATCH']
);
assertTest(
    "MethodOverrideMiddleware accepts POST with X-HTTP-Method-Override: PATCH (§15.6)",
    $overridePatch['status'] === 200
    && ($overridePatch['json']['data']['notes'] ?? '') === 'Updated via X-HTTP-Method-Override'
);

// Mark Paid twice -> only 1 payment row
$pay1 = callApi($app, 'POST', "/v1/subscriptions/{$subId}/payment", ['paidDate' => '2026-01-31'], $tokenA);
$pay2 = callApi($app, 'POST', "/v1/subscriptions/{$subId}/payment", ['paidDate' => '2026-01-31'], $tokenA);
$payCountStmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE subscription_id = :sid");
$payCountStmt->execute([':sid' => $subId]);
$payRows = (int) $payCountStmt->fetchColumn();
assertTest(
    "Mark Paid twice is idempotent (201 then 200) and inserts only ONE payment row",
    $pay1['status'] === 201 && $pay2['status'] === 200 && $payRows === 1
);

// Un-mark Paid -> removes latest payment for cycle
$unpay = callApi($app, 'DELETE', "/v1/subscriptions/{$subId}/payment", null, $tokenA);
$payCountStmt->execute([':sid' => $subId]);
assertTest(
    "Un-mark Paid removes that cycle's payment row and resets isPaidThisCycle=false",
    $unpay['status'] === 200
    && ($unpay['json']['data']['subscription']['isPaidThisCycle'] ?? true) === false
    && (int) $payCountStmt->fetchColumn() === 0
);

// Mark paid again and test Renew: 2026-01-31 -> 2026-02-28 -> 2026-03-31
callApi($app, 'POST', "/v1/subscriptions/{$subId}/payment", ['paidDate' => '2026-01-31'], $tokenA);
$renew1 = callApi($app, 'POST', "/v1/subscriptions/{$subId}/renew", [], $tokenA);
$renew2 = callApi($app, 'POST', "/v1/subscriptions/{$subId}/renew", [], $tokenA);
assertTest(
    "Renew monthly clamps 2026-01-31 -> 2026-02-28 -> 2026-03-31 and sets isPaidThisCycle=false",
    ($renew1['json']['data']['dueDate'] ?? '') === '2026-02-28'
    && ($renew2['json']['data']['dueDate'] ?? '') === '2026-03-31'
    && ($renew2['json']['data']['isPaidThisCycle'] ?? true) === false
);

// Yearly leap day renew: 2028-02-29 -> 2029-02-28, and Weekly +7 days
$subYearly = callApi($app, 'POST', '/v1/subscriptions', [
    'name' => 'Domain Renewal',
    'category' => 'Cloud',
    'amount' => 105.00,
    'billingCycle' => 'yearly',
    'dueDate' => '2028-02-29',
], $tokenA);
$yearlyId = (int) ($subYearly['json']['data']['id'] ?? 0);
$renewYearly = callApi($app, 'POST', "/v1/subscriptions/{$yearlyId}/renew", [], $tokenA);

$subWeekly = callApi($app, 'POST', '/v1/subscriptions', [
    'name' => 'Weekly Data Bundle',
    'category' => 'Telco',
    'amount' => 49.00,
    'billingCycle' => 'weekly',
    'dueDate' => '2026-10-17',
], $tokenA);
$weeklyId = (int) ($subWeekly['json']['data']['id'] ?? 0);
$renewWeekly = callApi($app, 'POST', "/v1/subscriptions/{$weeklyId}/renew", [], $tokenA);
assertTest(
    "Renew yearly clamps 2028-02-29 -> 2029-02-28 and weekly advances +7 days (2026-10-24)",
    ($renewYearly['json']['data']['dueDate'] ?? '') === '2029-02-28'
    && ($renewWeekly['json']['data']['dueDate'] ?? '') === '2026-10-24'
);

// Soft-delete weekly sub -> disappears from list while payments remain
callApi($app, 'DELETE', "/v1/subscriptions/{$weeklyId}", null, $tokenA);
$getDeleted = callApi($app, 'GET', "/v1/subscriptions/{$weeklyId}", null, $tokenA);
assertTest(
    "Soft-deleted subscription returns 404 and disappears from live list while payment history remains",
    $getDeleted['status'] === 404
);

// 4.5 Tenant Isolation: User B gets 404 on User A's subscription IDs
$regB = callApi($app, 'POST', '/v1/auth/register', [
    'email' => $emailB,
    'password' => 'Accept!Pass123',
    'displayName' => 'User B',
]);
$spoolB = MailService::getTestSpool();
$verifyTokenB = end($spoolB)['token'] ?? '';
$verB = callApi($app, 'POST', '/v1/auth/verify-email', ['token' => $verifyTokenB]);
$tokenB = $verB['json']['data']['token'] ?? '';

$isoGet = callApi($app, 'GET', "/v1/subscriptions/{$subId}", null, $tokenB);
$isoPut = callApi($app, 'PUT', "/v1/subscriptions/{$subId}", [
    'name' => 'Hacked', 'category' => 'Other', 'amount' => 1, 'billingCycle' => 'monthly', 'dueDate' => '2026-10-20'
], $tokenB);
$isoPay = callApi($app, 'POST', "/v1/subscriptions/{$subId}/payment", [], $tokenB);
$isoRenew = callApi($app, 'POST', "/v1/subscriptions/{$subId}/renew", [], $tokenB);
$isoDel = callApi($app, 'DELETE', "/v1/subscriptions/{$subId}", null, $tokenB);
assertTest(
    "Tenant isolation: User B gets 404 NOT_FOUND on GET/PUT/pay/renew/DELETE for User A's subscription",
    $isoGet['status'] === 404
    && $isoPut['status'] === 404
    && $isoPay['status'] === 404
    && $isoRenew['status'] === 404
    && $isoDel['status'] === 404
);

// 4.6 Reset to SA Demo Data, Dashboard Summary Math, Urgent Filter & Reset-Paid
$demoRes = callApi($app, 'POST', '/v1/account/reset-demo', [], $tokenA);
$summaryRes = callApi($app, 'GET', '/v1/dashboard/summary', null, $tokenA);
$sumData = $summaryRes['json']['data'] ?? [];
assertTest(
    "POST /v1/account/reset-demo & GET /v1/dashboard/summary match frontend budget formulas identically",
    $demoRes['status'] === 200
    && (float) ($sumData['monthlySalary'] ?? 0) === 28500.0
    && (int) ($sumData['activeSubscriptions'] ?? 0) === 5
    && (float) ($sumData['totalCommittedMonthly'] ?? 0) === 2261.99
    && (float) ($sumData['totalPaidThisCycle'] ?? 0) === 64.99
    && (float) ($sumData['totalDueThisCycle'] ?? 0) === 2197.0
    && (float) ($sumData['remainingBalance'] ?? 0) === 26238.01
    && (float) ($sumData['budgetUtilizationPercent'] ?? 0) === 7.9
);

$urgentRes = callApi($app, 'GET', '/v1/subscriptions?filter=urgent', null, $tokenA);
$urgentSubs = $urgentRes['json']['data'] ?? [];
$urgentHasPaid = false;
foreach ($urgentSubs as $uSub) {
    if (!empty($uSub['isPaidThisCycle'])) {
        $urgentHasPaid = true;
    }
}
assertTest(
    "Filter 'urgent' includes unpaid due <= today+3 (Showmax & Virgin Active) and excludes paid Spotify",
    $urgentRes['status'] === 200 && count($urgentSubs) === 2 && !$urgentHasPaid
);

$resetPaidRes = callApi($app, 'POST', '/v1/subscriptions/reset-paid', [], $tokenA);
$paymentsAfterResetPaid = callApi($app, 'GET', '/v1/payments', null, $tokenA);
assertTest(
    "POST /v1/subscriptions/reset-paid sets isPaidThisCycle=0 for all live subs while preserving payment history",
    $resetPaidRes['status'] === 200
    && ($paymentsAfterResetPaid['json']['meta']['total'] ?? 0) === 1
);

// 4.7 Backup Export & Legacy Import Round-Trip
$exportRes = callApi($app, 'GET', '/v1/backup', null, $tokenA);
$exportedBackup = $exportRes['json'] ?? [];
$reimportRes = callApi($app, 'POST', '/v1/backup', $exportedBackup, $tokenA);
assertTest(
    "Exported backup JSON from GET /v1/backup re-imports via POST /v1/backup with 100% fidelity",
    $exportRes['status'] === 200
    && $reimportRes['status'] === 200
    && ($reimportRes['json']['data']['subscriptions'] ?? 0) === 5
    && ($reimportRes['json']['data']['payments'] ?? 0) === 1
);

// 4.8 Reminder Cron Idempotency (running send-reminders.php twice on same day)
$pdo->prepare("UPDATE user_profiles SET system_notifications_enabled = 1 WHERE user_id = :uid")->execute([':uid' => $userIdA]);
$pdo->prepare("
    INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, content_encoding)
    VALUES (:uid, 'https://fcm.googleapis.com/fcm/send/task12-test-endpoint', 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm')
")->execute([':uid' => $userIdA]);

$cronCmd = sprintf('"%s" "%s" --now=2026-10-17T08:30:00Z --json', $phpBin, $apiDir . '/bin/send-reminders.php');
exec($cronCmd . ' 2>&1', $cronRun1Out, $cronRun1Code);
exec($cronCmd . ' 2>&1', $cronRun2Out, $cronRun2Code);
$cronStats1 = json_decode(implode("\n", $cronRun1Out), true);
$cronStats2 = json_decode(implode("\n", $cronRun2Out), true);
assertTest(
    "Running send-reminders.php twice on the same day logs & sends each reminder only once (idempotent)",
    $cronRun1Code === 0
    && $cronRun2Code === 0
    && ($cronStats1['notificationsLogged'] ?? 0) >= 1
    && ($cronStats2['notificationsLogged'] ?? -1) === 0
);

// 4.9 Password Reset single-use + token revocation + Logout + Account Deletion
$forgotRes = callApi($app, 'POST', '/v1/auth/forgot-password', ['email' => $emailA]);
$spoolReset = MailService::getTestSpool();
$resetTokenA = end($spoolReset)['token'] ?? '';
$resetRes1 = callApi($app, 'POST', '/v1/auth/reset-password', [
    'token' => $resetTokenA,
    'password' => 'NewAccept!Pass456',
]);
$resetRes2 = callApi($app, 'POST', '/v1/auth/reset-password', [
    'token' => $resetTokenA,
    'password' => 'NewAccept!Pass456',
]);
$oldTokenCheck = callApi($app, 'GET', '/v1/auth/me', null, $tokenA);
assertTest(
    "Password reset token works once (204), rejects reuse with 410 TOKEN_EXPIRED, and revokes existing device tokens (401)",
    $resetRes1['status'] === 204
    && $resetRes2['status'] === 410
    && $oldTokenCheck['status'] === 401
);

// Login with new password, test logout revocation, then login & delete account
$reloginA = callApi($app, 'POST', '/v1/auth/login', [
    'email' => $emailA,
    'password' => 'NewAccept!Pass456',
]);
$newTokenA = $reloginA['json']['data']['token'] ?? '';
$logoutA = callApi($app, 'POST', '/v1/auth/logout', [], $newTokenA);
$afterLogoutA = callApi($app, 'GET', '/v1/auth/me', null, $newTokenA);
assertTest(
    "Logout revokes current bearer token (204) and subsequent use returns 401 UNAUTHENTICATED",
    $logoutA['status'] === 204 && $afterLogoutA['status'] === 401
);

$finalLoginA = callApi($app, 'POST', '/v1/auth/login', [
    'email' => $emailA,
    'password' => 'NewAccept!Pass456',
]);
$finalTokenA = $finalLoginA['json']['data']['token'] ?? '';
$delA = callApi($app, 'DELETE', '/v1/auth/account', ['password' => 'NewAccept!Pass456'], $finalTokenA);
$delB = callApi($app, 'DELETE', '/v1/auth/account', ['password' => 'Accept!Pass123'], $tokenB);

$checkCascade = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE user_id = :uid");
$checkCascade->execute([':uid' => $userIdA]);
assertTest(
    "DELETE /v1/auth/account removes user and cascades to all related rows (POPIA compliance)",
    $delA['status'] === 204 && $delB['status'] === 204 && (int) $checkCascade->fetchColumn() === 0
);

echo "\n--------------------------------------------------------\n";
echo " Task 12 Verification Summary: {$passed} passed, {$failed} failed.\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
