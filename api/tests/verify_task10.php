<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Base64Url\Base64Url;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;
use SubTracker\Services\CleanupService;
use SubTracker\Services\PushService;
use SubTracker\Support\CurlHttpClient;

echo "========================================================\n";
echo " SubTracker Backend — Task 10 Automated Verification\n";
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
$resFactory = new ResponseFactory();

/** @var PDO $pdo */
$pdo = $container->get(PDO::class);
/** @var PushService $pushService */
$pushService = $container->get(PushService::class);
/** @var CleanupService $cleanupService */
$cleanupService = $container->get(CleanupService::class);

// Reset rate limits and spools for deterministic runs
$pdo->exec("TRUNCATE TABLE rate_limits");
PushService::clearTestSpool();
CurlHttpClient::clearRecordedRequests();

function makeRequest(\Slim\App $app, string $method, string $path, ?array $body = null, ?string $token = null): ResponseInterface {
    global $basePath, $reqFactory, $streamFactory;
    $uri = $basePath . $path;
    $request = $reqFactory->createServerRequest($method, $uri)
        ->withHeader('User-Agent', 'SubTracker-TestRunner/1.0');

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

/**
 * Generate a valid 65-byte uncompressed P-256 client public key (base64url) and 16-byte auth secret
 */
function generateClientPushKeys(): array {
    $keyRes = openssl_pkey_new([
        'ec' => [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ],
    ]);
    $details = openssl_pkey_get_details($keyRes);
    $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
    $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $rawPub = "\x04" . $x . $y; // 65 bytes uncompressed point

    return [
        'p256dh' => Base64Url::encode($rawPub),
        'auth' => Base64Url::encode(random_bytes(16)),
    ];
}

function createTestUser(PDO $pdo, string $email, string $timezone = 'Africa/Johannesburg'): array {
    $hash = password_hash('Pass#1234', PASSWORD_BCRYPT);
    $pdo->prepare("DELETE FROM users WHERE email = :email")->execute([':email' => $email]);

    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, display_name, email_verified_at, created_at, updated_at)
        VALUES (:email, :hash, 'Push Tester', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");
    $stmt->execute([':email' => $email, ':hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO user_profiles (user_id, monthly_salary, salary_day, currency, currency_symbol, system_notifications_enabled, timezone, created_at, updated_at)
        VALUES (:uid, 25000.00, 25, 'ZAR', 'R', 0, :tz, UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ")->execute([':uid' => $userId, ':tz' => $timezone]);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("
        INSERT INTO auth_tokens (user_id, token_hash, expires_at, created_at)
        VALUES (:uid, :thash, UTC_TIMESTAMP() + INTERVAL 30 DAY, UTC_TIMESTAMP())
    ")->execute([':uid' => $userId, ':thash' => hash('sha256', $token)]);

    return ['id' => $userId, 'token' => $token, 'email' => $email];
}

$suffix = bin2hex(random_bytes(3));
$userA = createTestUser($pdo, "push_user_a_{$suffix}@example.com", 'Africa/Johannesburg');
$userB = createTestUser($pdo, "push_user_b_{$suffix}@example.com", 'Africa/Johannesburg');

// ---------------------------------------------------------
// 1. Public VAPID Key & Auth Guards
// ---------------------------------------------------------
echo "--- 1. VAPID Public Key & Auth Guards ---\n";
$resVapid = makeRequest($app, 'GET', '/v1/push/vapid-public-key');
assertTest("GET /v1/push/vapid-public-key is public and returns HTTP 200", $resVapid->getStatusCode() === 200);
$vapidData = json_decode((string) $resVapid->getBody(), true)['data'] ?? [];
$pubKey = $vapidData['publicKey'] ?? '';
$decodedVapid = Base64Url::decode($pubKey);
assertTest("VAPID publicKey decodes to 65-byte uncompressed P-256 point", strlen($decodedVapid) === 65 && $decodedVapid[0] === "\x04", "publicKey={$pubKey}");

$resSubNoAuth = makeRequest($app, 'POST', '/v1/push/subscriptions', []);
assertTest("POST /v1/push/subscriptions requires auth (HTTP 401)", $resSubNoAuth->getStatusCode() === 401);

$resDelNoAuth = makeRequest($app, 'DELETE', '/v1/push/subscriptions', []);
assertTest("DELETE /v1/push/subscriptions requires auth (HTTP 401)", $resDelNoAuth->getStatusCode() === 401);

$resTestNoAuth = makeRequest($app, 'POST', '/v1/push/test');
assertTest("POST /v1/push/test requires auth (HTTP 401)", $resTestNoAuth->getStatusCode() === 401);

// ---------------------------------------------------------
// 2. Push Subscription Validation
// ---------------------------------------------------------
echo "\n--- 2. Push Subscription Input Validation ---\n";
$resInvalid = makeRequest($app, 'POST', '/v1/push/subscriptions', [
    'endpoint' => 'not-a-url',
    'keys' => ['p256dh' => '', 'auth' => ''],
    'contentEncoding' => 'invalid-enc',
], $userA['token']);
assertTest("Invalid push subscription returns HTTP 422", $resInvalid->getStatusCode() === 422);
$errFields = json_decode((string) $resInvalid->getBody(), true)['error']['fields'] ?? [];
assertTest("Validation reports endpoint, keys.p256dh, keys.auth, contentEncoding errors",
    isset($errFields['endpoint'], $errFields['keys.p256dh'], $errFields['keys.auth'], $errFields['contentEncoding']));

$resDelEmpty = makeRequest($app, 'DELETE', '/v1/push/subscriptions', ['endpoint' => ''], $userA['token']);
assertTest("DELETE /v1/push/subscriptions with empty endpoint returns HTTP 422", $resDelEmpty->getStatusCode() === 422);

// ---------------------------------------------------------
// 3. Subscribe, Re-assign on Shared Browser & Unsubscribe (§8.9)
// ---------------------------------------------------------
echo "\n--- 3. Device Registration, Shared Browser Re-assignment & Unsubscribe ---\n";
$clientKeys = generateClientPushKeys();
$sharedEndpoint = "https://fcm.googleapis.com/fcm/send/shared_device_{$suffix}";

$resSubA = makeRequest($app, 'POST', '/v1/push/subscriptions', [
    'endpoint' => $sharedEndpoint,
    'keys' => $clientKeys,
    'contentEncoding' => 'aes128gcm',
], $userA['token']);
assertTest("POST /v1/push/subscriptions returns HTTP 201", $resSubA->getStatusCode() === 201);

// Check that user_profiles.system_notifications_enabled was set to 1
$stmtNotifFlag = $pdo->prepare("SELECT system_notifications_enabled FROM user_profiles WHERE user_id = :uid");
$stmtNotifFlag->execute([':uid' => $userA['id']]);
assertTest("Registering push subscription enables user_profiles.system_notifications_enabled = 1",
    (int) $stmtNotifFlag->fetchColumn() === 1);

// Now User B logs in on the same browser and registers the same endpoint -> must re-assign to User B
$clientKeysB = generateClientPushKeys();
$resSubB = makeRequest($app, 'POST', '/v1/push/subscriptions', [
    'endpoint' => $sharedEndpoint,
    'keys' => $clientKeysB,
    'contentEncoding' => 'aes128gcm',
], $userB['token']);
assertTest("Shared endpoint registration by User B returns HTTP 201", $resSubB->getStatusCode() === 201);

$stmtOwner = $pdo->prepare("SELECT user_id FROM push_subscriptions WHERE endpoint_hash = :hash");
$stmtOwner->execute([':hash' => hash('sha256', $sharedEndpoint)]);
$owners = $stmtOwner->fetchAll(PDO::FETCH_COLUMN);
assertTest("Endpoint is uniquely stored and re-assigned to User B",
    count($owners) === 1 && (int) $owners[0] === $userB['id']);

// User A attempts to delete the endpoint now owned by User B -> should not delete User B's row
$resDelByA = makeRequest($app, 'DELETE', '/v1/push/subscriptions', ['endpoint' => $sharedEndpoint], $userA['token']);
assertTest("DELETE /v1/push/subscriptions by User A returns HTTP 204", $resDelByA->getStatusCode() === 204);
$stmtOwner->execute([':hash' => hash('sha256', $sharedEndpoint)]);
assertTest("User B's subscription remains intact after User A's delete call (tenant isolation)",
    (int) $stmtOwner->fetchColumn() === $userB['id']);

// User B deletes their own subscription -> row is deleted
$resDelByB = makeRequest($app, 'DELETE', '/v1/push/subscriptions', ['endpoint' => $sharedEndpoint], $userB['token']);
assertTest("DELETE /v1/push/subscriptions by User B returns HTTP 204", $resDelByB->getStatusCode() === 204);
$stmtOwner->execute([':hash' => hash('sha256', $sharedEndpoint)]);
assertTest("User B's subscription row is deleted from push_subscriptions", $stmtOwner->fetchColumn() === false);

// ---------------------------------------------------------
// 4. Test Push Endpoint, RFC 8291 Encryption & Rate Limit (§8.9)
// ---------------------------------------------------------
echo "\n--- 4. Test Notification (POST /v1/push/test), WebPush Encryption & Rate Limiting ---\n";
$deviceAEndpoint = "https://fcm.googleapis.com/fcm/send/user_a_device_{$suffix}";
makeRequest($app, 'POST', '/v1/push/subscriptions', [
    'endpoint' => $deviceAEndpoint,
    'keys' => $clientKeys,
    'contentEncoding' => 'aes128gcm',
], $userA['token']);

CurlHttpClient::clearRecordedRequests();
CurlHttpClient::setMockHandler(fn(RequestInterface $req): ResponseInterface => $resFactory->createResponse(201));

$resPushTest1 = makeRequest($app, 'POST', '/v1/push/test', null, $userA['token']);
assertTest("POST /v1/push/test returns HTTP 202 Accepted", $resPushTest1->getStatusCode() === 202);
$testData1 = json_decode((string) $resPushTest1->getBody(), true)['data'] ?? [];
assertTest("Test push reports 1 targeted device and 1 sent",
    ($testData1['devicesTargeted'] ?? 0) === 1 && ($testData1['sent'] ?? 0) === 1);

$recordedReqs = CurlHttpClient::getRecordedRequests();
assertTest("WebPush dispatched 1 HTTP POST request to push service", count($recordedReqs) === 1);
if (!empty($recordedReqs)) {
    $pushReq = $recordedReqs[0];
    $authHeader = $pushReq->getHeaderLine('Authorization');
    $encodingHeader = $pushReq->getHeaderLine('Content-Encoding');
    $bodySize = $pushReq->getBody()->getSize() ?? strlen((string) $pushReq->getBody());
    assertTest("Request contains signed VAPID Authorization header (vapid t=..., k=...)",
        str_starts_with($authHeader, 'vapid t=') && str_contains($authHeader, ', k='));
    assertTest("Request contains encrypted aes128gcm payload",
        $encodingHeader === 'aes128gcm' && $bodySize > 64);
    assertTest("Request sets Urgency: high so push wakes closed/idle mobile devices",
        $pushReq->getHeaderLine('Urgency') === 'high' && $pushReq->getHeaderLine('TTL') === '259200');
}

// Verify last_success_at was updated on push_subscriptions
$stmtSubRow = $pdo->prepare("SELECT failure_count, last_success_at FROM push_subscriptions WHERE endpoint_hash = :hash");
$stmtSubRow->execute([':hash' => hash('sha256', $deviceAEndpoint)]);
$subRow = $stmtSubRow->fetch();
assertTest("Successful push updates last_success_at and keeps failure_count = 0",
    $subRow && (int) $subRow['failure_count'] === 0 && $subRow['last_success_at'] !== null);

// Rate limit check: max 5 per hour per user (we already did 1, so 2..5 succeed, 6 fails with 429)
for ($i = 2; $i <= 5; $i++) {
    $r = makeRequest($app, 'POST', '/v1/push/test', null, $userA['token']);
    assertTest("Push test attempt #{$i} succeeds (HTTP 202)", $r->getStatusCode() === 202);
}
$resPushTest6 = makeRequest($app, 'POST', '/v1/push/test', null, $userA['token']);
assertTest("Push test attempt #6 is rate-limited (HTTP 429)", $resPushTest6->getStatusCode() === 429);
assertTest("429 response includes Retry-After header", $resPushTest6->getHeaderLine('Retry-After') !== '');

// ---------------------------------------------------------
// 5. Push Service Status Handling: 410 Gone & Failure Count Threshold (§9 step 6)
// ---------------------------------------------------------
echo "\n--- 5. Push Delivery Failure & 410 Gone Handling (§9 step 6) ---\n";
$goneEndpoint = "https://fcm.googleapis.com/fcm/send/gone_device_{$suffix}";
$flakyEndpoint = "https://fcm.googleapis.com/fcm/send/flaky_device_{$suffix}";

makeRequest($app, 'POST', '/v1/push/subscriptions', [
    'endpoint' => $goneEndpoint,
    'keys' => generateClientPushKeys(),
], $userB['token']);

makeRequest($app, 'POST', '/v1/push/subscriptions', [
    'endpoint' => $flakyEndpoint,
    'keys' => generateClientPushKeys(),
], $userB['token']);

// Mock: 410 for goneEndpoint, 500 for flakyEndpoint
CurlHttpClient::setMockHandler(function (RequestInterface $req) use ($resFactory, $goneEndpoint): ResponseInterface {
    if ((string) $req->getUri() === $goneEndpoint) {
        return $resFactory->createResponse(410);
    }
    return $resFactory->createResponse(500);
});

$deliveryReport = $pushService->sendToUserDevices($userB['id'], [
    'title' => 'Status Check',
    'body' => 'Testing 410 and 500 handling',
    'url' => './index.html#calendar',
    'tag' => 'subtracker-alert',
]);

$stmtGone = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint_hash = :hash");
$stmtGone->execute([':hash' => hash('sha256', $goneEndpoint)]);
assertTest("410 Gone from push service immediately deletes push_subscriptions row", $stmtGone->fetchColumn() === false);

$stmtFlaky = $pdo->prepare("SELECT failure_count FROM push_subscriptions WHERE endpoint_hash = :hash");
$stmtFlaky->execute([':hash' => hash('sha256', $flakyEndpoint)]);
assertTest("500 response increments failure_count to 1", (int) $stmtFlaky->fetchColumn() === 1);

// Trigger 9 more failures (total 10) -> should delete flakyEndpoint
for ($f = 2; $f <= 10; $f++) {
    $pushService->sendToUserDevices($userB['id'], [
        'title' => 'Retry Test',
        'body' => 'Testing failure count threshold',
        'url' => './index.html#calendar',
        'tag' => 'subtracker-alert',
    ]);
}
$stmtFlaky->execute([':hash' => hash('sha256', $flakyEndpoint)]);
assertTest("Device row is deleted after reaching 10 consecutive failures", $stmtFlaky->fetchColumn() === false);

// ---------------------------------------------------------
// 6. Scheduled Reminder Cron (`bin/send-reminders.php` & Idempotency) (§9)
// ---------------------------------------------------------
echo "\n--- 6. Scheduled Reminder Digest & Idempotency (§9) ---\n";
// Reset mock handler to 201 Created
CurlHttpClient::setMockHandler(fn(RequestInterface $req): ResponseInterface => $resFactory->createResponse(201));
PushService::clearTestSpool();

// Clear any existing subscriptions/notification_log for test isolation on all users except our test users
$pdo->exec("DELETE FROM notification_log");
$pdo->prepare("DELETE FROM push_subscriptions WHERE user_id NOT IN (:ua, :ub)")
    ->execute([':ua' => $userA['id'], ':ub' => $userB['id']]);

// Reference date: 2026-10-17
// For User A (Africa/Johannesburg = UTC+2):
//   At 2026-10-17T01:00:00Z -> local time is 03:00 SAST (< 08:00, must be skipped!)
//   At 2026-10-17T07:00:00Z -> local time is 09:00 SAST (>= 08:00, must be processed!)
// Create subscriptions for User A relative to 2026-10-17:
//   1. Netflix (active, unpaid, due 2026-10-18 = tomorrow, +1 day -> MUST trigger 'upcoming')
//   2. Spotify (active, paid, due 2026-10-17 -> paid, must NOT trigger)
//   3. Gym (paused, unpaid, due 2026-10-17 -> paused, must NOT trigger)
//   4. Fibre (active, unpaid, due 2026-10-22 -> +5 days, must NOT trigger)

$catId = (int) $pdo->query("SELECT id FROM categories WHERE code = 'Entertainment' LIMIT 1")->fetchColumn();
$insSub = $pdo->prepare("
    INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, billing_day, is_paid_this_cycle, status, icon, color, created_at, updated_at)
    VALUES (:uid, :cid, :name, :amt, 'monthly', :due, DAY(:due_day), :paid, :status, 'fa-tv', '#e50914', UTC_TIMESTAMP(), UTC_TIMESTAMP())
");
$insSub->execute([':uid' => $userA['id'], ':cid' => $catId, ':name' => 'Netflix', ':amt' => 159.00, ':due' => '2026-10-18', ':due_day' => '2026-10-18', ':paid' => 0, ':status' => 'active']);
$insSub->execute([':uid' => $userA['id'], ':cid' => $catId, ':name' => 'Spotify', ':amt' => 64.99, ':due' => '2026-10-17', ':due_day' => '2026-10-17', ':paid' => 1, ':status' => 'active']);
$insSub->execute([':uid' => $userA['id'], ':cid' => $catId, ':name' => 'Gym', ':amt' => 450.00, ':due' => '2026-10-17', ':due_day' => '2026-10-17', ':paid' => 0, ':status' => 'paused']);
$insSub->execute([':uid' => $userA['id'], ':cid' => $catId, ':name' => 'Fibre', ':amt' => 849.00, ':due' => '2026-10-22', ':due_day' => '2026-10-22', ':paid' => 0, ':status' => 'active']);

// Test A: Before 08:00 local time (01:00 UTC = 03:00 SAST)
$before8amStats = $pushService->processScheduledReminders(new DateTimeImmutable('2026-10-17T01:00:00Z'), false);
assertTest("Before 08:00 SAST (03:00 local), user is skipped and 0 digests are sent",
    $before8amStats['usersSkippedBefore8am'] === 1 && $before8amStats['digestsSent'] === 0);

// Test B: At 09:00 local time (07:00 UTC = 09:00 SAST)
$at9amStats = $pushService->processScheduledReminders(new DateTimeImmutable('2026-10-17T07:00:00Z'), false);
assertTest("At 09:00 SAST, 1 digest is sent and 1 notification is logged",
    $at9amStats['digestsSent'] === 1 && $at9amStats['notificationsLogged'] === 1 && $at9amStats['devicesSent'] === 1);

$spool = PushService::getTestSpool();
$lastPush = end($spool);
assertTest("1-item digest has exact title 'Upcoming Bill: Netflix'",
    ($lastPush['payload']['title'] ?? '') === 'Upcoming Bill: Netflix');
assertTest("1-item digest has formatted ZAR body and due date (18 Oct 2026)",
    ($lastPush['payload']['body'] ?? '') === 'Netflix payment of R 159,00 is due tomorrow (18 Oct 2026).'
    && ($lastPush['payload']['url'] ?? '') === './index.html#calendar'
    && ($lastPush['payload']['tag'] ?? '') === 'subtracker-alert',
    "Body: " . ($lastPush['payload']['body'] ?? ''));

// Test C: Idempotency — running again on the same day sends 0 notifications!
$secondRunStats = $pushService->processScheduledReminders(new DateTimeImmutable('2026-10-17T08:00:00Z'), false);
assertTest("Running send-reminders a second time on the same day sends 0 digests (idempotent via notification_log)",
    $secondRunStats['digestsSent'] === 0 && $secondRunStats['notificationsLogged'] === 0);

// Test D: Multi-item digest (N items) for User B
makeRequest($app, 'POST', '/v1/push/subscriptions', [
    'endpoint' => "https://fcm.googleapis.com/fcm/send/user_b_active_{$suffix}",
    'keys' => generateClientPushKeys(),
], $userB['token']);

$insSub->execute([':uid' => $userB['id'], ':cid' => $catId, ':name' => 'Showmax', ':amt' => 99.00, ':due' => '2026-10-17', ':due_day' => '2026-10-17', ':paid' => 0, ':status' => 'active']); // due_today
$insSub->execute([':uid' => $userB['id'], ':cid' => $catId, ':name' => 'DSTV', ':amt' => 799.00, ':due' => '2026-10-15', ':due_day' => '2026-10-15', ':paid' => 0, ':status' => 'active']); // overdue (-2d)
$insSub->execute([':uid' => $userB['id'], ':cid' => $catId, ':name' => 'iCloud', ':amt' => 59.00, ':due' => '2026-10-18', ':due_day' => '2026-10-18', ':paid' => 0, ':status' => 'active']); // upcoming (+1d)

$multiRunStats = $pushService->processScheduledReminders(new DateTimeImmutable('2026-10-17T09:00:00Z'), false);
assertTest("Multi-item user logs 3 notifications and sends 1 combined digest",
    $multiRunStats['notificationsLogged'] === 3 && $multiRunStats['digestsSent'] === 1);

$spool = PushService::getTestSpool();
$multiPush = end($spool);
assertTest("Multi-item digest title is '3 Subscriptions Due Soon!'",
    ($multiPush['payload']['title'] ?? '') === '3 Subscriptions Due Soon!'
    && ($multiPush['payload']['body'] ?? '') === 'You have 3 bills due today or tomorrow. Open SubTracker to review.');

// Test E: CLI execution of bin/send-reminders.php
$cliReminderOut = [];
$cliReminderExit = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/send-reminders.php') . ' --now=2026-10-17T10:00:00Z --json',
    $cliReminderOut,
    $cliReminderExit
);
$cliReminderJson = json_decode(implode("\n", $cliReminderOut), true);
assertTest("CLI script bin/send-reminders.php exits with 0 and valid JSON",
    $cliReminderExit === 0 && is_array($cliReminderJson) && ($cliReminderJson['digestsSent'] ?? -1) === 0);

// Test F: Background subscription renewal (pushsubscriptionchange) & retry when all devices fail
$renewedEndpoint = "https://fcm.googleapis.com/fcm/send/user_a_renewed_{$suffix}";
$resRenew = makeRequest($app, 'POST', '/v1/push/subscriptions/renew', [
    'oldEndpoint' => $deviceAEndpoint,
    'endpoint' => $renewedEndpoint,
    'keys' => generateClientPushKeys(),
    'contentEncoding' => 'aes128gcm',
]);
assertTest("POST /v1/push/subscriptions/renew rotates subscription without bearer token (HTTP 201)",
    $resRenew->getStatusCode() === 201);

// Advance to next day (2026-10-18 = Netflix due_today). First simulate push service outage (500), then recovery (201)
CurlHttpClient::setMockHandler(fn(RequestInterface $req): ResponseInterface => $resFactory->createResponse(500));
$failedRunStats = $pushService->processScheduledReminders(new DateTimeImmutable('2026-10-18T07:00:00Z'), false);
assertTest("When all devices fail, notification_log is rolled back and digestsRetryPending >= 1",
    ($failedRunStats['digestsSent'] ?? -1) === 0 && ($failedRunStats['digestsRetryPending'] ?? 0) >= 1);

CurlHttpClient::setMockHandler(fn(RequestInterface $req): ResponseInterface => $resFactory->createResponse(201));
$retryRunStats = $pushService->processScheduledReminders(new DateTimeImmutable('2026-10-18T08:00:00Z'), false);
assertTest("Next cron run retries and delivers the previously failed due_today reminder",
    ($retryRunStats['digestsSent'] ?? 0) >= 1 && ($retryRunStats['devicesSent'] ?? 0) >= 1);

// ---------------------------------------------------------
// 7. Housekeeping Cleanup Script (`bin/cleanup.php`) (§15.8)
// ---------------------------------------------------------
echo "\n--- 7. Housekeeping Cleanup Script (bin/cleanup.php) (§15.8) ---\n";
// 1. Insert an unverified user older than 7 days (8 days old) and one recent (2 days old)
$oldUnverifiedEmail = "old_unverified_{$suffix}@example.com";
$recentUnverifiedEmail = "recent_unverified_{$suffix}@example.com";
$pdo->prepare("
    INSERT INTO users (email, password_hash, display_name, email_verified_at, created_at, updated_at)
    VALUES (:e1, 'hash', 'Old Unverified', NULL, UTC_TIMESTAMP() - INTERVAL 8 DAY, UTC_TIMESTAMP() - INTERVAL 8 DAY),
           (:e2, 'hash', 'Recent Unverified', NULL, UTC_TIMESTAMP() - INTERVAL 2 DAY, UTC_TIMESTAMP() - INTERVAL 2 DAY)
")->execute([':e1' => $oldUnverifiedEmail, ':e2' => $recentUnverifiedEmail]);

// 2. Insert expired email_verifications row
$pdo->prepare("
    INSERT INTO email_verifications (user_id, email, token_hash, expires_at, created_at)
    VALUES (:uid, :email, :hash, UTC_TIMESTAMP() - INTERVAL 2 HOUR, UTC_TIMESTAMP() - INTERVAL 26 HOUR)
")->execute([':uid' => $userA['id'], ':email' => $userA['email'], ':hash' => hash('sha256', "expired_ev_{$suffix}")]);

// 3. Insert expired password_resets row
$pdo->prepare("
    INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
    VALUES (:uid, :hash, UTC_TIMESTAMP() - INTERVAL 10 MINUTE, UTC_TIMESTAMP() - INTERVAL 70 MINUTE)
")->execute([':uid' => $userA['id'], ':hash' => hash('sha256', "expired_pr_{$suffix}")]);

// 4. Insert old rate_limits row (2 days old)
$pdo->prepare("
    INSERT INTO rate_limits (bucket, window_start, hits)
    VALUES (:bucket, UTC_TIMESTAMP() - INTERVAL 2 DAY, 4)
")->execute([':bucket' => "old_bucket:{$suffix}"]);

// 5. Insert expired auth_token and revoked auth_token (>30 days old)
$pdo->prepare("
    INSERT INTO auth_tokens (user_id, token_hash, expires_at, revoked_at, created_at)
    VALUES (:uid1, :h1, UTC_TIMESTAMP() - INTERVAL 1 DAY, NULL, UTC_TIMESTAMP() - INTERVAL 31 DAY),
           (:uid2, :h2, UTC_TIMESTAMP() + INTERVAL 5 DAY, UTC_TIMESTAMP() - INTERVAL 35 DAY, UTC_TIMESTAMP() - INTERVAL 40 DAY)
")->execute([
    ':uid1' => $userA['id'],
    ':uid2' => $userA['id'],
    ':h1' => hash('sha256', "expired_tok_{$suffix}"),
    ':h2' => hash('sha256', "revoked_old_tok_{$suffix}"),
]);

// Run bin/cleanup.php via CLI
$cliCleanupOut = [];
$cliCleanupExit = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/cleanup.php') . ' --json',
    $cliCleanupOut,
    $cliCleanupExit
);
$cleanupStats = json_decode(implode("\n", $cliCleanupOut), true);

assertTest("CLI script bin/cleanup.php exits with 0 and returns stats JSON",
    $cliCleanupExit === 0 && is_array($cleanupStats));
assertTest("Cleanup deleted unverified account older than 7 days",
    ($cleanupStats['unverifiedUsersDeleted'] ?? 0) >= 1);
assertTest("Cleanup deleted expired email verification token",
    ($cleanupStats['emailVerificationsDeleted'] ?? 0) >= 1);
assertTest("Cleanup deleted expired password reset token",
    ($cleanupStats['passwordResetsDeleted'] ?? 0) >= 1);
assertTest("Cleanup pruned old rate_limits rows (>24h)",
    ($cleanupStats['rateLimitsDeleted'] ?? 0) >= 1);
assertTest("Cleanup deleted expired and old revoked (>30d) auth tokens",
    ($cleanupStats['authTokensDeleted'] ?? 0) >= 2);

// Verify recent unverified user (2 days old) was preserved
$stmtRecent = $pdo->prepare("SELECT id FROM users WHERE email = :email");
$stmtRecent->execute([':email' => $recentUnverifiedEmail]);
assertTest("Recent unverified user (< 7 days old) is preserved", $stmtRecent->fetchColumn() !== false);

// Clean up test users created by this script
$pdo->prepare("DELETE FROM users WHERE email IN (:e1, :e2, :e3)")
    ->execute([':e1' => $userA['email'], ':e2' => $userB['email'], ':e3' => $recentUnverifiedEmail]);
$pdo->exec("TRUNCATE TABLE rate_limits");

echo "\n--------------------------------------------------------\n";
echo " Task 10 Verification Summary: $passed passed, $failed failed.\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
