<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;

echo "========================================================\n";
echo " SubTracker Backend — Task 7 Automated Verification\n";
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

function makeRequest(\Slim\App $app, string $method, string $path, ?array $body = null, ?string $token = null, array $query = []): \Psr\Http\Message\ResponseInterface {
    global $basePath, $reqFactory, $streamFactory;
    $uri = $basePath . $path;
    if (!empty($query)) {
        $uri .= '?' . http_build_query($query);
    }
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
        VALUES (:email, :hash, 'Sub Tester', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");
    $stmt->execute([':email' => $email, ':hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO user_profiles (user_id, monthly_salary, salary_day, currency, currency_symbol, timezone, created_at, updated_at)
        VALUES (:uid, 25000.00, 25, 'ZAR', 'R', 'Africa/Johannesburg', UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ")->execute([':uid' => $userId]);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("
        INSERT INTO auth_tokens (user_id, token_hash, expires_at, created_at)
        VALUES (:uid, :thash, UTC_TIMESTAMP() + INTERVAL 30 DAY, UTC_TIMESTAMP())
    ")->execute([':uid' => $userId, ':thash' => hash('sha256', $token)]);

    return ['id' => $userId, 'token' => $token];
}

$userA = createTestUser($pdo, 'usera_sub_' . bin2hex(random_bytes(3)) . '@example.com');
$userB = createTestUser($pdo, 'userb_sub_' . bin2hex(random_bytes(3)) . '@example.com');

// ---------------------------------------------------------
// 1. Authentication & Validations on POST /v1/subscriptions
// ---------------------------------------------------------
echo "--- 1. Subscriptions Creation & Validation (POST /v1/subscriptions) ---\n";
$resNoAuth = makeRequest($app, 'POST', '/v1/subscriptions', ['name' => 'Spotify']);
assertTest("POST /v1/subscriptions requires auth (HTTP 401)", $resNoAuth->getStatusCode() === 401);

// Validation failures
$resValFail = makeRequest($app, 'POST', '/v1/subscriptions', [
    'name' => '',
    'amount' => -50,
    'billingCycle' => 'hourly',
    'dueDate' => 'not-a-date',
    'category' => 'NonExistentCategory'
], $userA['token']);

assertTest("Invalid payload returns HTTP 422", $resValFail->getStatusCode() === 422);
$jsonValFail = json_decode((string) $resValFail->getBody(), true);
$fields = $jsonValFail['error']['fields'] ?? [];
assertTest("Validation fields contain name, amount, billingCycle, dueDate, category",
    isset($fields['name'], $fields['amount'], $fields['billingCycle'], $fields['dueDate'], $fields['category']));

// Valid creation
$resCreate = makeRequest($app, 'POST', '/v1/subscriptions', [
    'name' => 'Spotify Premium',
    'category' => 'Music',
    'amount' => 64.99,
    'billingCycle' => 'monthly',
    'dueDate' => '2026-10-25',
    'startDate' => '2026-01-01',
    'icon' => 'fa-music',
    'color' => '#1db954',
    'notes' => 'Family plan',
    // Read-only fields to verify they are ignored
    'id' => 9999,
    'isPaidThisCycle' => true,
], $userA['token']);

assertTest("Valid subscription creation returns HTTP 201", $resCreate->getStatusCode() === 201);
$sub1 = json_decode((string) $resCreate->getBody(), true)['data'] ?? [];
assertTest("Created sub has valid ID", !empty($sub1['id']) && $sub1['id'] !== 9999);
assertTest("Created sub name is 'Spotify Premium'", ($sub1['name'] ?? null) === 'Spotify Premium');
assertTest("Created sub category is 'Music' with label 'Music'", ($sub1['category'] ?? null) === 'Music' && ($sub1['categoryLabel'] ?? null) === 'Music');
assertTest("Created sub amount is 64.99", (float) ($sub1['amount'] ?? 0) === 64.99);
assertTest("Created sub billingDay is 25 (derived from dueDate)", ($sub1['billingDay'] ?? null) === 25);
assertTest("Created sub isPaidThisCycle is false (ignored client override)", ($sub1['isPaidThisCycle'] ?? null) === false);
assertTest("Created sub status is 'active'", ($sub1['status'] ?? null) === 'active');
$sub1Id = (int) $sub1['id'];

// Create second subscription for User A (Entertainment, due today: 2026-10-16)
$resCreate2 = makeRequest($app, 'POST', '/v1/subscriptions', [
    'name' => 'Netflix 4K',
    'category' => 'Entertainment',
    'amount' => 199.00,
    'billingCycle' => 'monthly',
    'dueDate' => '2026-10-16',
    'icon' => 'fa-tv',
    'color' => '#e50914',
], $userA['token']);
$sub2 = json_decode((string) $resCreate2->getBody(), true)['data'] ?? [];
$sub2Id = (int) $sub2['id'];
assertTest("Second sub created with due date 2026-10-16", ($sub2['dueDate'] ?? null) === '2026-10-16');

// Create third subscription for User A (urgent/overdue: 2026-10-14)
$resCreate3 = makeRequest($app, 'POST', '/v1/subscriptions', [
    'name' => 'Gym Membership',
    'category' => 'Fitness',
    'amount' => 450.00,
    'billingCycle' => 'monthly',
    'dueDate' => '2026-10-14',
], $userA['token']);
$sub3 = json_decode((string) $resCreate3->getBody(), true)['data'] ?? [];
$sub3Id = (int) $sub3['id'];

// ---------------------------------------------------------
// 2. Listing & Filters (GET /v1/subscriptions)
// ---------------------------------------------------------
echo "\n--- 2. Subscriptions Listing & Filters (GET /v1/subscriptions) ---\n";
// Default list: sort order unpaid first, then dueDate ASC
$resList = makeRequest($app, 'GET', '/v1/subscriptions', null, $userA['token']);
assertTest("GET /v1/subscriptions returns HTTP 200", $resList->getStatusCode() === 200);
$list = json_decode((string) $resList->getBody(), true)['data'] ?? [];
assertTest("User A has 3 subscriptions", count($list) === 3);
assertTest("First listed is 2026-10-14 (earliest due date)", ($list[0]['dueDate'] ?? '') === '2026-10-14');
assertTest("Second listed is 2026-10-16", ($list[1]['dueDate'] ?? '') === '2026-10-16');
assertTest("Third listed is 2026-10-25", ($list[2]['dueDate'] ?? '') === '2026-10-25');

// Filter by category
$resCatFilter = makeRequest($app, 'GET', '/v1/subscriptions', null, $userA['token'], ['category' => 'Music']);
$catList = json_decode((string) $resCatFilter->getBody(), true)['data'] ?? [];
assertTest("Category filter 'Music' returns 1 subscription", count($catList) === 1 && ($catList[0]['category'] ?? '') === 'Music');

// Search query (q)
$resQFilter = makeRequest($app, 'GET', '/v1/subscriptions', null, $userA['token'], ['q' => 'netflix']);
$qList = json_decode((string) $resQFilter->getBody(), true)['data'] ?? [];
assertTest("Search filter 'netflix' returns 1 subscription", count($qList) === 1 && ($qList[0]['name'] ?? '') === 'Netflix 4K');

// ---------------------------------------------------------
// 3. Single Subscription & Tenant Isolation
// ---------------------------------------------------------
echo "\n--- 3. Single Subscription & Tenant Isolation ---\n";
$resGet1 = makeRequest($app, 'GET', "/v1/subscriptions/$sub1Id", null, $userA['token']);
assertTest("GET /v1/subscriptions/{id} returns HTTP 200", $resGet1->getStatusCode() === 200);

// Tenant isolation: User B trying to access User A's subscription
$resGetOther = makeRequest($app, 'GET', "/v1/subscriptions/$sub1Id", null, $userB['token']);
assertTest("User B accessing User A's sub returns HTTP 404", $resGetOther->getStatusCode() === 404);

// Non-existent ID
$resGet404 = makeRequest($app, 'GET', "/v1/subscriptions/999999", null, $userA['token']);
assertTest("Non-existent sub returns HTTP 404", $resGet404->getStatusCode() === 404);

// ---------------------------------------------------------
// 4. Update (PUT & PATCH) and Status Actions
// ---------------------------------------------------------
echo "\n--- 4. Updates (PUT, PATCH, Pause, Resume) ---\n";
// PUT full update
$resPut = makeRequest($app, 'PUT', "/v1/subscriptions/$sub1Id", [
    'name' => 'Spotify Duo',
    'category' => 'Music',
    'amount' => 84.99,
    'billingCycle' => 'monthly',
    'dueDate' => '2026-10-28', // changed day from 25 to 28
    'icon' => 'fa-headphones',
    'color' => '#1db954',
    'notes' => 'Upgraded to Duo',
], $userA['token']);

assertTest("PUT returns HTTP 200", $resPut->getStatusCode() === 200);
$putSub = json_decode((string) $resPut->getBody(), true)['data'] ?? [];
assertTest("PUT updated name to 'Spotify Duo'", ($putSub['name'] ?? '') === 'Spotify Duo');
assertTest("PUT updated amount to 84.99", (float) ($putSub['amount'] ?? 0) === 84.99);
assertTest("PUT recomputed billingDay to 28", ($putSub['billingDay'] ?? null) === 28);

// PATCH partial update
$resPatch = makeRequest($app, 'PATCH', "/v1/subscriptions/$sub1Id", [
    'notes' => 'Duo plan with partner',
], $userA['token']);

assertTest("PATCH returns HTTP 200", $resPatch->getStatusCode() === 200);
$patchSub = json_decode((string) $resPatch->getBody(), true)['data'] ?? [];
assertTest("PATCH updated notes", ($patchSub['notes'] ?? '') === 'Duo plan with partner');
assertTest("PATCH preserved other fields (amount 84.99)", (float) ($patchSub['amount'] ?? 0) === 84.99);

// Pause action
$resPause = makeRequest($app, 'POST', "/v1/subscriptions/$sub1Id/pause", null, $userA['token']);
assertTest("POST /pause returns HTTP 200", $resPause->getStatusCode() === 200);
$pausedSub = json_decode((string) $resPause->getBody(), true)['data'] ?? [];
assertTest("Sub status is 'paused'", ($pausedSub['status'] ?? '') === 'paused');

// Filter status=active vs status=paused
$resActiveSubs = makeRequest($app, 'GET', '/v1/subscriptions', null, $userA['token'], ['status' => 'active']);
$activeSubs = json_decode((string) $resActiveSubs->getBody(), true)['data'] ?? [];
assertTest("status=active excludes paused sub (count is 2)", count($activeSubs) === 2);

// Resume action
$resResume = makeRequest($app, 'POST', "/v1/subscriptions/$sub1Id/resume", null, $userA['token']);
assertTest("POST /resume returns HTTP 200", $resResume->getStatusCode() === 200);
$resumedSub = json_decode((string) $resResume->getBody(), true)['data'] ?? [];
assertTest("Sub status restored to 'active'", ($resumedSub['status'] ?? '') === 'active');

// ---------------------------------------------------------
// 5. Mark Paid & Un-mark Paid Actions
// ---------------------------------------------------------
echo "\n--- 5. Payment Toggle Actions (POST & DELETE /payment) ---\n";
// Reject future paidDate
$resFutureDate = makeRequest($app, 'POST', "/v1/subscriptions/$sub2Id/payment", [
    'paidDate' => '2099-01-01',
], $userA['token']);
assertTest("Future paidDate returns HTTP 422", $resFutureDate->getStatusCode() === 422);

// Mark Paid (HTTP 201 Created)
$resMarkPaid = makeRequest($app, 'POST', "/v1/subscriptions/$sub2Id/payment", [
    'paidDate' => '2026-10-15',
], $userA['token']);
assertTest("Mark Paid returns HTTP 201", $resMarkPaid->getStatusCode() === 201);
$markPaidData = json_decode((string) $resMarkPaid->getBody(), true)['data'] ?? [];
$subAfterPaid = $markPaidData['subscription'] ?? [];
$paymentRecord = $markPaidData['payment'] ?? [];
assertTest("isPaidThisCycle is true", ($subAfterPaid['isPaidThisCycle'] ?? null) === true);
assertTest("Payment snapshot amount is 199.0", (float) ($paymentRecord['amount'] ?? 0) === 199.0);
assertTest("Payment cycleDueDate matches sub dueDate (2026-10-16)", ($paymentRecord['cycleDueDate'] ?? '') === '2026-10-16');
assertTest("Payment paidDate is 2026-10-15", ($paymentRecord['paidDate'] ?? '') === '2026-10-15');

// Idempotent Mark Paid (HTTP 200 OK without duplicate payment)
$resMarkPaidAgain = makeRequest($app, 'POST', "/v1/subscriptions/$sub2Id/payment", null, $userA['token']);
assertTest("Idempotent Mark Paid returns HTTP 200", $resMarkPaidAgain->getStatusCode() === 200);

// Check payments table has exactly 1 payment
$stmtPmtCount = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE subscription_id = :sid");
$stmtPmtCount->execute([':sid' => $sub2Id]);
assertTest("Payments table has exactly 1 payment row", (int) $stmtPmtCount->fetchColumn() === 1);

// Un-mark Paid (DELETE /subscriptions/{id}/payment)
$resUnmarkPaid = makeRequest($app, 'DELETE', "/v1/subscriptions/$sub2Id/payment", null, $userA['token']);
assertTest("Un-mark Paid returns HTTP 200", $resUnmarkPaid->getStatusCode() === 200);
$unmarkData = json_decode((string) $resUnmarkPaid->getBody(), true)['data'] ?? [];
assertTest("isPaidThisCycle is false after un-mark", ($unmarkData['subscription']['isPaidThisCycle'] ?? null) === false);
assertTest("removedPaymentId returned", !empty($unmarkData['removedPaymentId']));

// Second Un-mark Paid (no-op)
$resUnmarkAgain = makeRequest($app, 'DELETE', "/v1/subscriptions/$sub2Id/payment", null, $userA['token']);
assertTest("Second Un-mark returns HTTP 200 with removedPaymentId null",
    $resUnmarkAgain->getStatusCode() === 200 && ($json = json_decode((string) $resUnmarkAgain->getBody(), true)) && $json['data']['removedPaymentId'] === null);

// ---------------------------------------------------------
// 6. Month-Clamped Renewal Algorithm (POST /subscriptions/{id}/renew)
// ---------------------------------------------------------
echo "\n--- 6. Month-Clamped Renewal Algorithm (§6.4) ---\n";
// Create a subscription with anchor billing day 31 in January: 2026-01-31
$resJan31 = makeRequest($app, 'POST', '/v1/subscriptions', [
    'name' => 'Month Clamp Test',
    'amount' => 100,
    'dueDate' => '2026-01-31',
    'billingCycle' => 'monthly',
], $userA['token']);
$subJan = json_decode((string) $resJan31->getBody(), true)['data'] ?? [];
$janId = (int) $subJan['id'];
assertTest("Jan 31 sub created with billingDay 31", ($subJan['billingDay'] ?? null) === 31);

// Renew Jan 31 -> February (non-leap year 2026 has 28 days) -> clamped to 2026-02-28
$resFebRenew = makeRequest($app, 'POST', "/v1/subscriptions/$janId/renew", null, $userA['token']);
assertTest("Renew returns HTTP 200", $resFebRenew->getStatusCode() === 200);
$subFeb = json_decode((string) $resFebRenew->getBody(), true)['data'] ?? [];
assertTest("Jan 31 renewed to 2026-02-28 (clamped to end of Feb)", ($subFeb['dueDate'] ?? '') === '2026-02-28');
assertTest("billingDay anchor preserved at 31", ($subFeb['billingDay'] ?? null) === 31);

// Renew Feb 28 -> March (has 31 days) -> expands back to 2026-03-31
$resMarRenew = makeRequest($app, 'POST', "/v1/subscriptions/$janId/renew", null, $userA['token']);
$subMar = json_decode((string) $resMarRenew->getBody(), true)['data'] ?? [];
assertTest("Feb 28 renewed to 2026-03-31 (expands back to anchor 31)", ($subMar['dueDate'] ?? '') === '2026-03-31');

// ---------------------------------------------------------
// 7. Reset Paid (POST /v1/subscriptions/reset-paid)
// ---------------------------------------------------------
echo "\n--- 7. Reset Paid (POST /v1/subscriptions/reset-paid) ---\n";
// Mark sub1 and sub3 paid
makeRequest($app, 'POST', "/v1/subscriptions/$sub1Id/payment", null, $userA['token']);
makeRequest($app, 'POST', "/v1/subscriptions/$sub3Id/payment", null, $userA['token']);

$resReset = makeRequest($app, 'POST', '/v1/subscriptions/reset-paid', null, $userA['token']);
assertTest("POST /v1/subscriptions/reset-paid returns HTTP 200", $resReset->getStatusCode() === 200);
$resetData = json_decode((string) $resReset->getBody(), true)['data'] ?? [];
assertTest("Updated count is 2", ($resetData['updated'] ?? null) === 2);

// Check that sub1 and sub3 are now unpaid
$sub1Check = json_decode((string) makeRequest($app, 'GET', "/v1/subscriptions/$sub1Id", null, $userA['token'])->getBody(), true)['data'];
assertTest("sub1 isPaidThisCycle is false", ($sub1Check['isPaidThisCycle'] ?? null) === false);

// ---------------------------------------------------------
// 8. Soft Delete (DELETE /v1/subscriptions/{id})
// ---------------------------------------------------------
echo "\n--- 8. Soft Delete (DELETE /v1/subscriptions/{id}) ---\n";
$resDelete = makeRequest($app, 'DELETE', "/v1/subscriptions/$janId", null, $userA['token']);
assertTest("DELETE returns HTTP 204", $resDelete->getStatusCode() === 204);

// Subsequent GET returns 404
$resGetDeleted = makeRequest($app, 'GET', "/v1/subscriptions/$janId", null, $userA['token']);
assertTest("Deleted subscription returns HTTP 404", $resGetDeleted->getStatusCode() === 404);

// Soft delete in DB has deleted_at set
$stmtDel = $pdo->prepare("SELECT deleted_at FROM subscriptions WHERE id = :id");
$stmtDel->execute([':id' => $janId]);
assertTest("Database record has deleted_at set (not hard-deleted)", $stmtDel->fetchColumn() !== null);

// ---------------------------------------------------------
// 9. Bootstrap State Endpoint (GET /v1/state)
// ---------------------------------------------------------
echo "\n--- 9. Bootstrap State Endpoint (GET /v1/state) ---\n";
$resState = makeRequest($app, 'GET', '/v1/state', null, $userA['token']);
assertTest("GET /v1/state returns HTTP 200", $resState->getStatusCode() === 200);
$state = json_decode((string) $resState->getBody(), true)['data'] ?? [];
assertTest("State contains profile object", isset($state['profile']['monthlySalary']));
assertTest("State contains subscriptions array", isset($state['subscriptions']) && is_array($state['subscriptions']));
assertTest("State contains paymentHistory array", isset($state['paymentHistory']) && is_array($state['paymentHistory']));
assertTest("State contains user-local today string", !empty($state['today']));

echo "\n--------------------------------------------------------\n";
echo " Task 7 Verification Summary: $passed passed, $failed failed.\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
