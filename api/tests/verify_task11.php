<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;
use SubTracker\Services\MailService;

echo "========================================================\n";
echo " SubTracker Frontend & API — Task 11 Verification\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $name\n";
        if ($details !== '') {
            echo "        $details\n";
        }
        $passed++;
    } else {
        echo " [FAIL] $name\n";
        if ($details !== '') {
            echo "        Error: $details\n";
        }
        $failed++;
    }
}

$rootDir = dirname(__DIR__, 2);
$swContent = (string) file_get_contents($rootDir . '/sw.js');
$storeContent = (string) file_get_contents($rootDir . '/js/store.js');
$utilsContent = (string) file_get_contents($rootDir . '/js/utils.js');
$notifContent = (string) file_get_contents($rootDir . '/js/notifications.js');
$htmlContent = (string) file_get_contents($rootDir . '/index.html');
$cssContent = (string) file_get_contents($rootDir . '/css/app.css');

// -------------------------------------------------------------------------
// 1. Service Worker (sw.js) Verification (§9, §12.1)
// -------------------------------------------------------------------------
echo "--- 1. Service Worker (sw.js) API Bypass & Push Listener ---\n";

assertTest(
    "sw.js bumps CACHE_NAME to subtracker-cache-v13",
    str_contains($swContent, "const CACHE_NAME = 'subtracker-cache-v13';")
);

assertTest(
    "sw.js fetch handler bypasses /api/ requests from caching",
    (bool) preg_match('/if\s*\(\s*url\.pathname\.includes\(\s*[\'"]\/api\/[\'"]\s*\)\s*\)\s*return;/', $swContent)
);

assertTest(
    "sw.js registers 'push' event listener",
    (bool) preg_match('/self\.addEventListener\(\s*[\'"]push[\'"]/', $swContent)
);

assertTest(
    "sw.js push listener invokes self.registration.showNotification()",
    str_contains($swContent, 'self.registration.showNotification(data.title, options)')
);

assertTest(
    "sw.js notificationclick listener navigates to notification data.url",
    str_contains($swContent, 'event.notification.data.url') && str_contains($swContent, 'clients.openWindow(targetUrl)')
);

// -------------------------------------------------------------------------
// 2. Utilities & Notifications (js/utils.js, js/notifications.js)
// -------------------------------------------------------------------------
echo "\n--- 2. Utilities & Web Push Client Helpers ---\n";

assertTest(
    "SubUtils supports serverToday from GET /state (§8.2, §11)",
    str_contains($utilsContent, 'serverToday:') && str_contains($utilsContent, 'if (this.serverToday) return this.serverToday;')
);

assertTest(
    "SubUtils formats local ISO dates without UTC shift (§11)",
    str_contains($utilsContent, 'formatLocalISO(') && !str_contains($utilsContent, 'new Date().toISOString().slice(0, 10)')
);

assertTest(
    "SubUtils includes urlBase64ToUint8Array helper for VAPID public key",
    str_contains($utilsContent, 'urlBase64ToUint8Array(base64String)')
);

assertTest(
    "SubNotifier includes subscribeToPush() and getPushEndpoint() (§8.9)",
    str_contains($notifContent, 'async subscribeToPush(vapidPublicKey)') && str_contains($notifContent, 'async getPushEndpoint()')
);

// -------------------------------------------------------------------------
// 3. Alpine.js Store & API Integration (js/store.js) (§8, §12)
// -------------------------------------------------------------------------
echo "\n--- 3. Alpine.js Store REST API Endpoint Integration ---\n";

assertTest(
    "StorageRepository manages subtracker_token in localStorage (§12.2)",
    str_contains($storeContent, "TOKEN_KEY: 'subtracker_token'") && str_contains($storeContent, 'Authorization`: `Bearer ${token}`') || str_contains($storeContent, "'Authorization'] = `Bearer \${token}`")
);

$requiredEndpoints = [
    '/state' => 'GET /state bootstrap',
    '/auth/login' => 'POST /auth/login',
    '/auth/register' => 'POST /auth/register',
    '/auth/verify-email' => 'POST /auth/verify-email',
    '/auth/resend-verification' => 'POST /auth/resend-verification',
    '/auth/forgot-password' => 'POST /auth/forgot-password',
    '/auth/reset-password' => 'POST /auth/reset-password',
    '/auth/password' => 'PUT /auth/password',
    '/auth/logout' => 'POST /auth/logout',
    '/auth/account' => 'DELETE /auth/account',
    '/auth/me' => 'GET /auth/me',
    '/profile' => 'PATCH /profile',
    '/categories' => 'GET /categories',
    '/subscriptions' => 'Subscriptions CRUD',
    '/subscriptions/reset-paid' => 'POST /subscriptions/reset-paid',
    '/backup' => 'GET & POST /backup',
    '/account/reset-demo' => 'POST /account/reset-demo',
    '/push/vapid-public-key' => 'GET /push/vapid-public-key',
    '/push/subscriptions' => 'POST /push/subscriptions',
    '/push/test' => 'POST /push/test',
];

foreach ($requiredEndpoints as $endpoint => $label) {
    assertTest(
        "store.js integrates endpoint {$endpoint} ({$label})",
        str_contains($storeContent, $endpoint)
    );
}

assertTest(
    "store.js handles 401 UNAUTHENTICATED by clearing session and prompting login (§12.2)",
    str_contains($storeContent, 'err.status === 401') && str_contains($storeContent, 'StorageRepository.clearSession()')
);

assertTest(
    "store.js handles 403 EMAIL_NOT_VERIFIED by opening verify-notice screen (§12.2)",
    str_contains($storeContent, "err.code === 'EMAIL_NOT_VERIFIED'") && str_contains($storeContent, "this.authMode = 'verify-notice'")
);

assertTest(
    "store.js handles #verify?token=... and #reset?token=... deep-link hash routes (§12.2)",
    str_contains($storeContent, "hash.startsWith('#verify')") && str_contains($storeContent, "hash.startsWith('#reset')")
);

// -------------------------------------------------------------------------
// 4. UI Modals & Dark/Light Mode Styling (index.html, css/app.css)
// -------------------------------------------------------------------------
echo "\n--- 4. UI Modals, Verification Notice & Dark/Light Theme Styling ---\n";

assertTest(
    "index.html includes header Account button (#account-auth-btn) with active status dot",
    str_contains($htmlContent, 'id="account-auth-btn"') && str_contains($htmlContent, 'class="auth-status-dot"')
);

assertTest(
    "index.html includes Email Verification Pending Notice banner (.verify-banner)",
    str_contains($htmlContent, 'class="verify-banner mb-4"') && str_contains($htmlContent, 'Email Verification Required')
);

assertTest(
    "index.html includes Account & Cloud Sync panel in Settings tab",
    str_contains($htmlContent, 'Account & Cloud Sync') && str_contains($htmlContent, 'Sync Now')
);

$authModes = ['login', 'register', 'verify-notice', 'forgot-password', 'reset-password', 'change-password', 'delete-account'];
foreach ($authModes as $mode) {
    assertTest(
        "index.html Auth Bottom Sheet Modal supports '{$mode}' view",
        str_contains($htmlContent, "\$store.app.authMode === '{$mode}'")
    );
}

assertTest(
    "css/app.css defines dark mode and [data-theme=\"light\"] styles for auth UI components",
    str_contains($cssContent, '.auth-mode-tabs') &&
    str_contains($cssContent, '.auth-alert.is-error') &&
    str_contains($cssContent, '.verify-banner') &&
    str_contains($cssContent, '[data-theme="light"] .auth-mode-tabs') &&
    str_contains($cssContent, '[data-theme="light"] .verify-banner')
);

// -------------------------------------------------------------------------
// 5. End-to-End Frontend Workflow Simulation Against REST API
// -------------------------------------------------------------------------
echo "\n--- 5. End-to-End Frontend API Workflow Verification ---\n";

$_ENV['APP_ENV'] = 'test';
$app = App::create();
$container = $app->getContainer();
$pdo = $container->get(PDO::class);
$basePath = $app->getBasePath();
$reqFactory = new ServerRequestFactory();
$streamFactory = new StreamFactory();

$pdo->exec("TRUNCATE TABLE rate_limits");
MailService::clearTestSpool();

$testEmail = 'task11_frontend_' . bin2hex(random_bytes(3)) . '@example.co.za';
$pdo->prepare("DELETE FROM users WHERE email = :e")->execute([':e' => $testEmail]);

function callApi(\Slim\App $app, string $method, string $path, ?array $body = null, ?string $token = null): array {
    global $basePath, $reqFactory, $streamFactory;
    $req = $reqFactory->createServerRequest($method, $basePath . $path);
    if ($token !== null) {
        $req = $req->withHeader('Authorization', 'Bearer ' . $token);
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
        'json' => $raw !== '' ? json_decode($raw, true) : null,
    ];
}

// Step A: Register new user from frontend Register modal
$regRes = callApi($app, 'POST', '/v1/auth/register', [
    'email' => $testEmail,
    'password' => 'FrontEnd!2026',
    'displayName' => 'Sipho Frontend',
    'timezone' => 'Africa/Johannesburg',
    'deviceName' => 'Browser on Windows',
]);
assertTest(
    "Frontend registration (POST /v1/auth/register) returns 202 Accepted with requiresVerification=true",
    $regRes['status'] === 202 && ($regRes['json']['data']['requiresVerification'] ?? false) === true
);

// Step B: Attempting login before verification triggers 403 EMAIL_NOT_VERIFIED (shows verify-notice UI)
$unverifiedLogin = callApi($app, 'POST', '/v1/auth/login', [
    'email' => $testEmail,
    'password' => 'FrontEnd!2026',
]);
assertTest(
    "Unverified login returns 403 EMAIL_NOT_VERIFIED for frontend verify-notice modal",
    $unverifiedLogin['status'] === 403 && ($unverifiedLogin['json']['error']['code'] ?? '') === 'EMAIL_NOT_VERIFIED'
);

// Step C: Verify email using token from MailService spool (simulating #verify?token=... route)
$spool = MailService::getTestSpool();
$verifyToken = end($spool)['token'] ?? '';
$verifyRes = callApi($app, 'POST', '/v1/auth/verify-email', [
    'token' => $verifyToken,
    'deviceName' => 'Browser on Windows',
]);
$bearerToken = $verifyRes['json']['data']['token'] ?? '';
assertTest(
    "Frontend #verify?token=... route (POST /v1/auth/verify-email) activates account and returns bearer token",
    $verifyRes['status'] === 200 && $bearerToken !== ''
);

// Step D: Reset to SA demo data (POST /v1/account/reset-demo) and fetch /v1/state
$demoRes = callApi($app, 'POST', '/v1/account/reset-demo', [], $bearerToken);
assertTest(
    "Frontend 'Reset to Demo Data' button (POST /v1/account/reset-demo) seeds 5 SA subscriptions",
    $demoRes['status'] === 200 && ($demoRes['json']['data']['subscriptions'] ?? 0) === 5
);

$stateRes = callApi($app, 'GET', '/v1/state', null, $bearerToken);
$subs = $stateRes['json']['data']['subscriptions'] ?? [];
assertTest(
    "Frontend init() bootstrap (GET /v1/state) returns profile, 5 subscriptions, 1 payment, and user-local today",
    $stateRes['status'] === 200
    && count($subs) === 5
    && count($stateRes['json']['data']['paymentHistory'] ?? []) === 1
    && !empty($stateRes['json']['data']['today'])
);

// Step E: Update salary & theme (PATCH /v1/profile)
$profRes = callApi($app, 'PATCH', '/v1/profile', [
    'monthlySalary' => 32000,
    'theme' => 'light',
], $bearerToken);
assertTest(
    "Frontend salary & theme sync (PATCH /v1/profile) updates monthlySalary=32000 and theme=light",
    $profRes['status'] === 200
    && (float) ($profRes['json']['data']['monthlySalary'] ?? 0) === 32000.0
    && ($profRes['json']['data']['theme'] ?? '') === 'light'
);

// Step F: Add subscription, Mark Paid, Renew Next Cycle
$addRes = callApi($app, 'POST', '/v1/subscriptions', [
    'name' => 'Netflix SA',
    'category' => 'Entertainment',
    'amount' => 159.00,
    'billingCycle' => 'monthly',
    'dueDate' => '2026-01-31',
    'icon' => 'fa-film',
    'color' => '#e50914',
    'notes' => 'Standard plan',
], $bearerToken);
$newSubId = (int) ($addRes['json']['data']['id'] ?? 0);
assertTest(
    "Frontend Add Subscription modal (POST /v1/subscriptions) creates subscription",
    $addRes['status'] === 201 && $newSubId > 0
);

$payRes = callApi($app, 'POST', "/v1/subscriptions/{$newSubId}/payment", [
    'paidDate' => $stateRes['json']['data']['today'],
], $bearerToken);
assertTest(
    "Frontend Mark Paid toggle (POST /v1/subscriptions/{id}/payment) sets isPaidThisCycle=true",
    $payRes['status'] === 201 && ($payRes['json']['data']['subscription']['isPaidThisCycle'] ?? false) === true
);

$renewRes = callApi($app, 'POST', "/v1/subscriptions/{$newSubId}/renew", [], $bearerToken);
assertTest(
    "Frontend Renew Next Cycle button (POST /v1/subscriptions/{id}/renew) clamps 2026-01-31 -> 2026-02-28",
    $renewRes['status'] === 200
    && ($renewRes['json']['data']['dueDate'] ?? '') === '2026-02-28'
    && ($renewRes['json']['data']['isPaidThisCycle'] ?? true) === false
);

// Clean up test user
$delRes = callApi($app, 'DELETE', '/v1/auth/account', ['password' => 'FrontEnd!2026'], $bearerToken);
assertTest(
    "Frontend Delete Account modal (DELETE /v1/auth/account) removes user and cascades data (204)",
    $delRes['status'] === 204
);

echo "\n--------------------------------------------------------\n";
echo " Task 11 Verification Summary: {$passed} passed, {$failed} failed.\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
