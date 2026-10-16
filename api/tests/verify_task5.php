<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;

echo "========================================================\n";
echo " SubTracker Backend — Task 5 Automated Verification\n";
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
// Helper: Create a verified test user and return token
// ---------------------------------------------------------
function createVerifiedUser(PDO $pdo, string $email, string $password): array {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare("DELETE FROM users WHERE email = :email")->execute([':email' => $email]);

    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, display_name, email_verified_at, created_at, updated_at)
        VALUES (:email, :hash, 'Profile Tester', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");
    $stmt->execute([':email' => $email, ':hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();

    $profileStmt = $pdo->prepare("
        INSERT INTO user_profiles (user_id, monthly_salary, salary_day, currency, currency_symbol, system_notifications_enabled, theme, timezone, created_at, updated_at)
        VALUES (:user_id, 25000.00, 25, 'ZAR', 'R', 0, 'dark', 'Africa/Johannesburg', UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE monthly_salary = 25000.00, salary_day = 25, theme = 'dark'
    ");
    $profileStmt->execute([':user_id' => $userId]);

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $tokenStmt = $pdo->prepare("
        INSERT INTO auth_tokens (user_id, token_hash, device_name, expires_at, created_at)
        VALUES (:user_id, :token_hash, 'PHPUnit', UTC_TIMESTAMP() + INTERVAL 30 DAY, UTC_TIMESTAMP())
    ");
    $tokenStmt->execute([':user_id' => $userId, ':token_hash' => $tokenHash]);

    return ['userId' => $userId, 'email' => $email, 'token' => $plainToken];
}

$userA = createVerifiedUser($pdo, 'usera_' . bin2hex(random_bytes(3)) . '@example.co.za', 'UserA#Pass1');
$userB = createVerifiedUser($pdo, 'userb_' . bin2hex(random_bytes(3)) . '@example.co.za', 'UserB#Pass2');

// ---------------------------------------------------------
// 1. Test Auth & Security Guards
// ---------------------------------------------------------
echo "--- 1. Auth & Security Guards ---\n";
// A. Missing token -> 401
$resNoToken = makeRequest($app, 'GET', '/v1/profile');
assertTest("GET /v1/profile without auth returns HTTP 401", $resNoToken->getStatusCode() === 401);

// B. Invalid token -> 401
$resBadToken = makeRequest($app, 'GET', '/v1/profile', null, 'bad_token_123');
assertTest("GET /v1/profile with bad token returns HTTP 401", $resBadToken->getStatusCode() === 401);

// C. Unverified user token -> 403 EMAIL_NOT_VERIFIED
$unverifiedEmail = 'unverified_' . bin2hex(random_bytes(3)) . '@example.co.za';
$stmtUnver = $pdo->prepare("
    INSERT INTO users (email, password_hash, display_name, email_verified_at, created_at, updated_at)
    VALUES (:email, 'dummy_hash', 'Unverified', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
");
$stmtUnver->execute([':email' => $unverifiedEmail]);
$unverId = (int) $pdo->lastInsertId();
$unverPlain = bin2hex(random_bytes(32));
$pdo->prepare("
    INSERT INTO auth_tokens (user_id, token_hash, device_name, expires_at, created_at)
    VALUES (:user_id, :hash, 'PHPUnit', UTC_TIMESTAMP() + INTERVAL 30 DAY, UTC_TIMESTAMP())
")->execute([':user_id' => $unverId, ':hash' => hash('sha256', $unverPlain)]);

$resUnver = makeRequest($app, 'GET', '/v1/profile', null, $unverPlain);
assertTest("GET /v1/profile with unverified email returns HTTP 403 EMAIL_NOT_VERIFIED", $resUnver->getStatusCode() === 403);
$jsonUnver = json_decode((string) $resUnver->getBody(), true);
assertTest("Error code is EMAIL_NOT_VERIFIED", ($jsonUnver['error']['code'] ?? null) === 'EMAIL_NOT_VERIFIED');

// ---------------------------------------------------------
// 2. Test GET /v1/profile
// ---------------------------------------------------------
echo "\n--- 2. GET /v1/profile ---\n";
$resGetA = makeRequest($app, 'GET', '/v1/profile', null, $userA['token']);
assertTest("GET /v1/profile returns HTTP 200 OK", $resGetA->getStatusCode() === 200);
assertTest("Response has Cache-Control: no-store header", str_contains($resGetA->getHeaderLine('Cache-Control'), 'no-store'));

$jsonGetA = json_decode((string) $resGetA->getBody(), true);
assertTest("Response envelope has 'data' key", isset($jsonGetA['data']));
$prof = $jsonGetA['data'] ?? [];
assertTest("Profile contains monthlySalary (numeric)", is_numeric($prof['monthlySalary']) && (float) $prof['monthlySalary'] === 25000.0);
assertTest("Profile contains salaryDay (int)", is_int($prof['salaryDay']) && $prof['salaryDay'] === 25);
assertTest("Profile currency is 'ZAR'", ($prof['currency'] ?? null) === 'ZAR');
assertTest("Profile currencySymbol is 'R'", ($prof['currencySymbol'] ?? null) === 'R');
assertTest("Profile systemNotificationsEnabled is boolean", is_bool($prof['systemNotificationsEnabled']));
assertTest("Profile theme is 'dark'", ($prof['theme'] ?? null) === 'dark');
assertTest("Profile timezone is 'Africa/Johannesburg'", ($prof['timezone'] ?? null) === 'Africa/Johannesburg');

// ---------------------------------------------------------
// 3. Test PATCH /v1/profile Validations
// ---------------------------------------------------------
echo "\n--- 3. PATCH /v1/profile Validations ---\n";
// A. Negative salary
$resVal1 = makeRequest($app, 'PATCH', '/v1/profile', ['monthlySalary' => -500], $userA['token']);
assertTest("Negative salary returns HTTP 422", $resVal1->getStatusCode() === 422);
$jsonVal1 = json_decode((string) $resVal1->getBody(), true);
assertTest("Error field monthlySalary present", isset($jsonVal1['error']['fields']['monthlySalary']));

// B. Invalid salaryDay (< 1 or > 31)
$resVal2 = makeRequest($app, 'PATCH', '/v1/profile', ['salaryDay' => 32], $userA['token']);
assertTest("Salary day > 31 returns HTTP 422", $resVal2->getStatusCode() === 422);
$jsonVal2 = json_decode((string) $resVal2->getBody(), true);
assertTest("Error field salaryDay present", isset($jsonVal2['error']['fields']['salaryDay']));

// C. Invalid theme
$resVal3 = makeRequest($app, 'PATCH', '/v1/profile', ['theme' => 'blue'], $userA['token']);
assertTest("Invalid theme returns HTTP 422", $resVal3->getStatusCode() === 422);

// D. Invalid timezone
$resVal4 = makeRequest($app, 'PATCH', '/v1/profile', ['timezone' => 'Mars/Olympus'], $userA['token']);
assertTest("Invalid timezone returns HTTP 422", $resVal4->getStatusCode() === 422, "Status: " . $resVal4->getStatusCode() . ", Body: " . (string) $resVal4->getBody());

// ---------------------------------------------------------
// 4. Test PATCH /v1/profile Partial Updates
// ---------------------------------------------------------
echo "\n--- 4. PATCH /v1/profile Partial Updates ---\n";
// A. Update salary only
$resPatchSalary = makeRequest($app, 'PATCH', '/v1/profile', ['monthlySalary' => 32500.50], $userA['token']);
assertTest("Update salary returns HTTP 200", $resPatchSalary->getStatusCode() === 200);
$jsonSalary = json_decode((string) $resPatchSalary->getBody(), true);
assertTest("monthlySalary updated to 32500.5", ($jsonSalary['data']['monthlySalary'] ?? null) === 32500.5);
assertTest("Other fields preserved (theme still dark)", ($jsonSalary['data']['theme'] ?? null) === 'dark');

// B. Update theme to light
$resPatchTheme = makeRequest($app, 'PATCH', '/v1/profile', ['theme' => 'light'], $userA['token']);
assertTest("Update theme returns HTTP 200", $resPatchTheme->getStatusCode() === 200);
$jsonTheme = json_decode((string) $resPatchTheme->getBody(), true);
assertTest("theme updated to light", ($jsonTheme['data']['theme'] ?? null) === 'light');

// C. Update notifications enabled and salaryDay
$resPatchMulti = makeRequest($app, 'PATCH', '/v1/profile', [
    'systemNotificationsEnabled' => true,
    'salaryDay' => 28,
], $userA['token']);
assertTest("Update notifications & salaryDay returns HTTP 200", $resPatchMulti->getStatusCode() === 200);
$jsonMulti = json_decode((string) $resPatchMulti->getBody(), true);
assertTest("systemNotificationsEnabled is true", ($jsonMulti['data']['systemNotificationsEnabled'] ?? null) === true);
assertTest("salaryDay is 28", ($jsonMulti['data']['salaryDay'] ?? null) === 28);

// D. Read-only fields immutability (currency cannot be changed from ZAR)
$resPatchRo = makeRequest($app, 'PATCH', '/v1/profile', [
    'currency' => 'USD',
    'currencySymbol' => '$',
], $userA['token']);
assertTest("Read-only field attempt returns HTTP 200 without altering currency", $resPatchRo->getStatusCode() === 200);
$jsonRo = json_decode((string) $resPatchRo->getBody(), true);
assertTest("Currency remains ZAR", ($jsonRo['data']['currency'] ?? null) === 'ZAR');
assertTest("CurrencySymbol remains R", ($jsonRo['data']['currencySymbol'] ?? null) === 'R');

// ---------------------------------------------------------
// 5. Test Database Persistence & Tenant Isolation
// ---------------------------------------------------------
echo "\n--- 5. Database Persistence & Tenant Isolation ---\n";
// Verify User A in DB
$stmtDbA = $pdo->prepare("SELECT * FROM user_profiles WHERE user_id = :uid");
$stmtDbA->execute([':uid' => $userA['userId']]);
$dbRowA = $stmtDbA->fetch();
assertTest("Database reflects User A monthly_salary 32500.50", (float) $dbRowA['monthly_salary'] === 32500.50);
assertTest("Database reflects User A theme 'light'", $dbRowA['theme'] === 'light');
assertTest("Database reflects User A system_notifications_enabled = 1", (int) $dbRowA['system_notifications_enabled'] === 1);

// Verify User B profile was untouched (Tenant Isolation)
$resGetB = makeRequest($app, 'GET', '/v1/profile', null, $userB['token']);
$jsonGetB = json_decode((string) $resGetB->getBody(), true);
assertTest("User B salary untouched at 25000.0", is_numeric($jsonGetB['data']['monthlySalary']) && (float) $jsonGetB['data']['monthlySalary'] === 25000.0);
assertTest("User B theme untouched at 'dark'", ($jsonGetB['data']['theme'] ?? null) === 'dark');

echo "\n--------------------------------------------------------\n";
echo " Task 5 Verification Summary: $passed passed, $failed failed.\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
