<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;

echo "========================================================\n";
echo " SubTracker Backend — Task 8 Automated Verification\n";
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
        VALUES (:email, :hash, 'Ledger Tester', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");
    $stmt->execute([':email' => $email, ':hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO user_profiles (user_id, monthly_salary, salary_day, currency, currency_symbol, timezone, created_at, updated_at)
        VALUES (:uid, 30000.00, 25, 'ZAR', 'R', 'Africa/Johannesburg', UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ")->execute([':uid' => $userId]);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("
        INSERT INTO auth_tokens (user_id, token_hash, expires_at, created_at)
        VALUES (:uid, :thash, UTC_TIMESTAMP() + INTERVAL 30 DAY, UTC_TIMESTAMP())
    ")->execute([':uid' => $userId, ':thash' => hash('sha256', $token)]);

    return ['id' => $userId, 'token' => $token];
}

$userA = createTestUser($pdo, 'usera_pmt_' . bin2hex(random_bytes(3)) . '@example.com');
$userB = createTestUser($pdo, 'userb_pmt_' . bin2hex(random_bytes(3)) . '@example.com');

// ---------------------------------------------------------
// 1. Auth & Guards
// ---------------------------------------------------------
echo "--- 1. Auth & Guards (GET /v1/payments) ---\n";
$resNoAuth = makeRequest($app, 'GET', '/v1/payments');
assertTest("GET /v1/payments requires auth (HTTP 401)", $resNoAuth->getStatusCode() === 401);

$resBadToken = makeRequest($app, 'GET', '/v1/payments', null, 'bad-token-12345');
assertTest("GET /v1/payments with bad token returns HTTP 401", $resBadToken->getStatusCode() === 401);

// ---------------------------------------------------------
// 2. Query Parameter Validations
// ---------------------------------------------------------
echo "\n--- 2. Query Parameter Validations ---\n";
// Bad from date
$resBadFrom = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['from' => 'not-a-date']);
assertTest("Invalid 'from' date returns HTTP 422", $resBadFrom->getStatusCode() === 422);
$jsonBadFrom = json_decode((string) $resBadFrom->getBody(), true);
assertTest("Error field 'from' present", isset($jsonBadFrom['error']['fields']['from']));

// Bad to date
$resBadTo = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['to' => '2026-99-99']);
assertTest("Invalid 'to' date returns HTTP 422", $resBadTo->getStatusCode() === 422);

// from > to
$resFromAfterTo = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], [
    'from' => '2026-10-20',
    'to' => '2026-10-10'
]);
assertTest("'from' after 'to' returns HTTP 422", $resFromAfterTo->getStatusCode() === 422);

// Bad subscriptionId
$resBadSubId = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['subscriptionId' => '-5']);
assertTest("Negative subscriptionId returns HTTP 422", $resBadSubId->getStatusCode() === 422);

// Bad page
$resBadPage = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['page' => '0']);
assertTest("Zero page returns HTTP 422", $resBadPage->getStatusCode() === 422);

// Bad perPage
$resBadPerPage = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['perPage' => '-10']);
assertTest("Negative perPage returns HTTP 422", $resBadPerPage->getStatusCode() === 422);

// ---------------------------------------------------------
// 3. Setup Test Subscriptions and Payments for User A & User B
// ---------------------------------------------------------
echo "\n--- 3. Payments Ledger Setup & Seed ---\n";

// Create 2 subscriptions for User A
$resSub1 = makeRequest($app, 'POST', '/v1/subscriptions', [
    'name' => 'Netflix Premium',
    'category' => 'Entertainment',
    'amount' => 199.00,
    'billingCycle' => 'monthly',
    'dueDate' => '2026-10-15',
], $userA['token']);
$sub1 = json_decode((string) $resSub1->getBody(), true)['data'] ?? [];
$sub1Id = (int) $sub1['id'];

$resSub2 = makeRequest($app, 'POST', '/v1/subscriptions', [
    'name' => 'Fibre Internet',
    'category' => 'Internet',
    'amount' => 899.00,
    'billingCycle' => 'monthly',
    'dueDate' => '2026-10-01',
], $userA['token']);
$sub2 = json_decode((string) $resSub2->getBody(), true)['data'] ?? [];
$sub2Id = (int) $sub2['id'];

// Insert payments directly into payments table to test various dates and cycles
// User A payments (total: 5 payments)
// 1. Sub 1: 199.00 on 2026-10-15
// 2. Sub 1: 199.00 on 2026-09-15
// 3. Sub 1: 199.00 on 2026-08-15
// 4. Sub 2: 899.00 on 2026-10-01
// 5. Sub 2: 899.00 on 2026-09-01
// Total User A count: 5, Total User A amount: 199*3 + 899*2 = 597 + 1798 = 2395.00
$insPmt = $pdo->prepare("
    INSERT INTO payments (user_id, subscription_id, subscription_name, amount, cycle_due_date, paid_date, created_at)
    VALUES (:uid, :sid, :name, :amount, :due, :paid, UTC_TIMESTAMP())
");

$insPmt->execute([':uid' => $userA['id'], ':sid' => $sub1Id, ':name' => 'Netflix Premium', ':amount' => 199.00, ':due' => '2026-10-15', ':paid' => '2026-10-15']);
$insPmt->execute([':uid' => $userA['id'], ':sid' => $sub1Id, ':name' => 'Netflix Premium', ':amount' => 199.00, ':due' => '2026-09-15', ':paid' => '2026-09-15']);
$insPmt->execute([':uid' => $userA['id'], ':sid' => $sub1Id, ':name' => 'Netflix Premium', ':amount' => 199.00, ':due' => '2026-08-15', ':paid' => '2026-08-15']);
$insPmt->execute([':uid' => $userA['id'], ':sid' => $sub2Id, ':name' => 'Fibre Internet', ':amount' => 899.00, ':due' => '2026-10-01', ':paid' => '2026-10-01']);
$insPmt->execute([':uid' => $userA['id'], ':sid' => $sub2Id, ':name' => 'Fibre Internet', ':amount' => 899.00, ':due' => '2026-09-01', ':paid' => '2026-09-01']);

// Insert 1 payment for User B (to test tenant isolation)
$insPmt->execute([':uid' => $userB['id'], ':sid' => null, ':name' => 'User B Secret Sub', ':amount' => 5000.00, ':due' => '2026-10-10', ':paid' => '2026-10-10']);

assertTest("Inserted test payment fixtures in database", true);

// ---------------------------------------------------------
// 4. Contract Shape & Totals
// ---------------------------------------------------------
echo "\n--- 4. Contract Shape & Totals (§8.6) ---\n";
$resList = makeRequest($app, 'GET', '/v1/payments', null, $userA['token']);
assertTest("GET /v1/payments returns HTTP 200", $resList->getStatusCode() === 200);
$jsonList = json_decode((string) $resList->getBody(), true);
$data = $jsonList['data'] ?? [];
$meta = $jsonList['meta'] ?? [];

assertTest("Response envelope has 'data' and 'meta'", isset($jsonList['data'], $jsonList['meta']));
assertTest("Meta total is 5", ($meta['total'] ?? null) === 5);
assertTest("Meta totalAmount is 2395.0", (float) ($meta['totalAmount'] ?? 0) === 2395.0);
assertTest("Meta page is 1 and perPage is 50", ($meta['page'] ?? null) === 1 && ($meta['perPage'] ?? null) === 50);

$firstPmt = $data[0] ?? [];
assertTest("Payment item contains id, subscriptionId, name, amount, cycleDueDate, paidDate, createdAt",
    isset($firstPmt['id'], $firstPmt['subscriptionId'], $firstPmt['name'], $firstPmt['amount'], $firstPmt['cycleDueDate'], $firstPmt['paidDate'], $firstPmt['createdAt']));
assertTest("Sorted by paid_date DESC (first item is 2026-10-15)", ($firstPmt['paidDate'] ?? '') === '2026-10-15');

// ---------------------------------------------------------
// 5. Query Filters (subscriptionId & Date Ranges)
// ---------------------------------------------------------
echo "\n--- 5. Query Filters (subscriptionId & Date Ranges) ---\n";
// Filter by subscriptionId (Sub 1 has 3 payments: 199.00 * 3 = 597.00)
$resSubFilter = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['subscriptionId' => $sub1Id]);
$jsonSubFilter = json_decode((string) $resSubFilter->getBody(), true);
$subPayments = $jsonSubFilter['data'] ?? [];
$subMeta = $jsonSubFilter['meta'] ?? [];
assertTest("Filter by subscriptionId returns 3 records", count($subPayments) === 3);
assertTest("Filtered totalAmount is 597.0", (float) ($subMeta['totalAmount'] ?? 0) === 597.0);
assertTest("All records have subscriptionId matching filter",
    $subPayments[0]['subscriptionId'] === $sub1Id && $subPayments[1]['subscriptionId'] === $sub1Id && $subPayments[2]['subscriptionId'] === $sub1Id);

// Filter by date range: from 2026-09-01 to 2026-09-30
// Matches: 2026-09-15 (Netflix: 199) + 2026-09-01 (Fibre: 899) = 1098.00 (2 payments)
$resDateFilter = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], [
    'from' => '2026-09-01',
    'to' => '2026-09-30'
]);
$jsonDateFilter = json_decode((string) $resDateFilter->getBody(), true);
assertTest("Date range filter returns 2 payments for September 2026", count($jsonDateFilter['data'] ?? []) === 2);
assertTest("September totalAmount is 1098.0", (float) ($jsonDateFilter['meta']['totalAmount'] ?? 0) === 1098.0);

// Filter with no matching dates: e.g. year 2024
$resEmptyDate = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], [
    'from' => '2024-01-01',
    'to' => '2024-12-31'
]);
$jsonEmpty = json_decode((string) $resEmptyDate->getBody(), true);
assertTest("Empty filter returns data: []", empty($jsonEmpty['data']));
assertTest("Empty filter returns total: 0 and totalAmount: 0.0", ($jsonEmpty['meta']['total'] ?? null) === 0 && (float) ($jsonEmpty['meta']['totalAmount'] ?? 0) === 0.0);

// ---------------------------------------------------------
// 6. Pagination
// ---------------------------------------------------------
echo "\n--- 6. Pagination (page & perPage) ---\n";
// Total records = 5. Request perPage=2:
// Page 1: 2 records
$resP1 = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['page' => 1, 'perPage' => 2]);
$jsonP1 = json_decode((string) $resP1->getBody(), true);
assertTest("Page 1 returns 2 items", count($jsonP1['data'] ?? []) === 2);
assertTest("Page 1 meta total is 5 and totalAmount is 2395.0", ($jsonP1['meta']['total'] ?? null) === 5 && (float) ($jsonP1['meta']['totalAmount'] ?? 0) === 2395.0);

// Page 2: 2 records
$resP2 = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['page' => 2, 'perPage' => 2]);
$jsonP2 = json_decode((string) $resP2->getBody(), true);
assertTest("Page 2 returns 2 items", count($jsonP2['data'] ?? []) === 2);
assertTest("Page 2 records are distinct from Page 1",
    $jsonP1['data'][0]['id'] !== $jsonP2['data'][0]['id'] && $jsonP1['data'][1]['id'] !== $jsonP2['data'][1]['id']);

// Page 3: 1 record
$resP3 = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['page' => 3, 'perPage' => 2]);
$jsonP3 = json_decode((string) $resP3->getBody(), true);
assertTest("Page 3 returns 1 item", count($jsonP3['data'] ?? []) === 1);

// Page 4: 0 records
$resP4 = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['page' => 4, 'perPage' => 2]);
$jsonP4 = json_decode((string) $resP4->getBody(), true);
assertTest("Page 4 returns 0 items", count($jsonP4['data'] ?? []) === 0);

// ---------------------------------------------------------
// 7. Tenant Isolation
// ---------------------------------------------------------
echo "\n--- 7. Tenant Isolation ---\n";
// User B has only 1 payment (amount 5000)
$resUserB = makeRequest($app, 'GET', '/v1/payments', null, $userB['token']);
$jsonUserB = json_decode((string) $resUserB->getBody(), true);
assertTest("User B sees only their 1 payment", count($jsonUserB['data'] ?? []) === 1);
assertTest("User B totalAmount is 5000.0", (float) ($jsonUserB['meta']['totalAmount'] ?? 0) === 5000.0);

// User B attempting to query User A's subscriptionId
$resUserBTryingA = makeRequest($app, 'GET', '/v1/payments', null, $userB['token'], ['subscriptionId' => $sub1Id]);
$jsonUserBTryingA = json_decode((string) $resUserBTryingA->getBody(), true);
assertTest("User B querying User A's subscriptionId returns 0 rows",
    empty($jsonUserBTryingA['data']) && ($jsonUserBTryingA['meta']['total'] ?? null) === 0);

// ---------------------------------------------------------
// 8. Snapshot & Soft Delete Integrity
// ---------------------------------------------------------
echo "\n--- 8. Snapshot & Soft Delete Integrity ---\n";
// Update Sub 1 amount from 199 to 399 and name to 'Netflix Family'
makeRequest($app, 'PATCH', "/v1/subscriptions/$sub1Id", [
    'name' => 'Netflix Family Modified',
    'amount' => 399.00
], $userA['token']);

// Check payments ledger: snapshot name and amount MUST REMAIN UNCHANGED (199.00 and 'Netflix Premium')
$resCheckSnap = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['subscriptionId' => $sub1Id]);
$snapPayments = json_decode((string) $resCheckSnap->getBody(), true)['data'] ?? [];
assertTest("Payments snapshot amount remains 199.0 despite sub amount update", (float) ($snapPayments[0]['amount'] ?? 0) === 199.0);
assertTest("Payments snapshot name remains 'Netflix Premium' despite sub name update", ($snapPayments[0]['name'] ?? '') === 'Netflix Premium');

// Soft delete Sub 1
makeRequest($app, 'DELETE', "/v1/subscriptions/$sub1Id", null, $userA['token']);

// Payments ledger MUST STILL CONTAIN the payments for the soft-deleted sub
$resAfterDel = makeRequest($app, 'GET', '/v1/payments', null, $userA['token'], ['subscriptionId' => $sub1Id]);
$afterDelPayments = json_decode((string) $resAfterDel->getBody(), true)['data'] ?? [];
assertTest("Payments for soft-deleted subscription remain intact in ledger (count = 3)", count($afterDelPayments) === 3);

// ---------------------------------------------------------
// 9. Demo User Payment History Parity (§8.6)
// ---------------------------------------------------------
echo "\n--- 9. Demo User Payment History Parity (§8.6) ---\n";
$resDemoLogin = makeRequest($app, 'POST', '/v1/auth/login', [
    'email' => 'demo@subtracker.local',
    'password' => 'Demo@1234',
]);
$demoToken = json_decode((string) $resDemoLogin->getBody(), true)['data']['token'] ?? null;
assertTest("Demo user login succeeds", !empty($demoToken));

$resDemoPayments = makeRequest($app, 'GET', '/v1/payments', null, $demoToken);
assertTest("Demo user GET /v1/payments returns HTTP 200", $resDemoPayments->getStatusCode() === 200);
$demoPmtJson = json_decode((string) $resDemoPayments->getBody(), true);
$demoPmts = $demoPmtJson['data'] ?? [];
$spotifyPmt = null;
foreach ($demoPmts as $p) {
    if (($p['name'] ?? '') === 'Spotify Premium') {
        $spotifyPmt = $p;
        break;
    }
}
assertTest("Demo user has Spotify Premium in payments history", $spotifyPmt !== null);
assertTest("Spotify Premium payment has amount 64.99 and valid paidDate",
    (float) ($spotifyPmt['amount'] ?? 0) === 64.99 && !empty($spotifyPmt['paidDate']),
    "Found payment: " . json_encode($spotifyPmt));

echo "\n--------------------------------------------------------\n";
echo " Task 8 Verification Summary: $passed passed, $failed failed.\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
