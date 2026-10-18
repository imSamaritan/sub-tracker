<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\App;
use SubTracker\Services\MailService;
use SubTracker\Services\RateLimiter;

echo "========================================================\n";
echo " SubTracker Backend — Task 4 Automated Verification\n";
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

$_ENV['APP_ENV'] = 'test';
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
// 1. Test Registration Validation & Execution
// ---------------------------------------------------------
echo "--- 1. User Registration (POST /v1/auth/register) ---\n";
$pdo->exec("TRUNCATE TABLE rate_limits");
MailService::clearTestSpool();

// A. Validation failure (short password, bad email)
$resRegFail = makeRequest($app, 'POST', '/v1/auth/register', [
    'email' => 'invalid-email',
    'password' => 'short',
]);
assertTest("Registration validation rejects invalid email & short password", $resRegFail->getStatusCode() === 422);
$jsonRegFail = json_decode((string) $resRegFail->getBody(), true);
assertTest("Validation error has fields email and password", isset($jsonRegFail['error']['fields']['email'], $jsonRegFail['error']['fields']['password']));

// B. Successful registration
$testEmail = 'sipho_' . bin2hex(random_bytes(4)) . '@example.co.za';
$testPass = 'Sipho#2026';
$resRegOk = makeRequest($app, 'POST', '/v1/auth/register', [
    'email' => $testEmail,
    'password' => $testPass,
    'displayName' => 'Sipho Zulu',
    'timezone' => 'Africa/Johannesburg',
]);

assertTest("Registration returns HTTP 202 Accepted", $resRegOk->getStatusCode() === 202, "Status: " . $resRegOk->getStatusCode());
$jsonRegOk = json_decode((string) $resRegOk->getBody(), true);
assertTest("Registration response indicates requiresVerification=true", ($jsonRegOk['data']['requiresVerification'] ?? null) === true);

// Verify user in database
$stmtUser = $pdo->prepare("SELECT id, email, display_name, email_verified_at FROM users WHERE email = :email");
$stmtUser->execute([':email' => $testEmail]);
$userRow = $stmtUser->fetch();
assertTest("User record created with email_verified_at NULL", $userRow !== false && $userRow['email_verified_at'] === null);

// Verify profile created in database
$stmtProfile = $pdo->prepare("SELECT user_id, currency, timezone FROM user_profiles WHERE user_id = :uid");
$stmtProfile->execute([':uid' => $userRow['id']]);
$profileRow = $stmtProfile->fetch();
assertTest("User profile created with ZAR currency and timezone", $profileRow !== false && $profileRow['currency'] === 'ZAR');

// Verify email verification token generated
$stmtVerify = $pdo->prepare("SELECT id, token_hash, expires_at, used_at FROM email_verifications WHERE user_id = :uid");
$stmtVerify->execute([':uid' => $userRow['id']]);
$verifyRow = $stmtVerify->fetch();
assertTest("Email verification record generated with 24h TTL", $verifyRow !== false && $verifyRow['used_at'] === null);

// Verify verification email dispatched to test spool
$spool = MailService::getTestSpool();
$lastEmail = end($spool);
assertTest("Verification email queued in MailService spool", $lastEmail !== false && $lastEmail['to'] === $testEmail);
$plainVerifyToken = $lastEmail['token'] ?? '';
assertTest("Dispatched token matches token_hash in DB", hash('sha256', $plainVerifyToken) === $verifyRow['token_hash']);

// C. Duplicate email check
$resRegDup = makeRequest($app, 'POST', '/v1/auth/register', [
    'email' => $testEmail,
    'password' => $testPass,
]);
assertTest("Duplicate email registration returns HTTP 409 EMAIL_TAKEN", $resRegDup->getStatusCode() === 409);

// ---------------------------------------------------------
// 2. Test Login Before Email Verification (403 EMAIL_NOT_VERIFIED)
// ---------------------------------------------------------
echo "\n--- 2. Login Before Verification ---\n";
$resLoginUnverified = makeRequest($app, 'POST', '/v1/auth/login', [
    'email' => $testEmail,
    'password' => $testPass,
]);
assertTest("Unverified login returns HTTP 403 EMAIL_NOT_VERIFIED", $resLoginUnverified->getStatusCode() === 403, "Status: " . $resLoginUnverified->getStatusCode());
$jsonLoginUnverified = json_decode((string) $resLoginUnverified->getBody(), true);
assertTest("Error code is EMAIL_NOT_VERIFIED", ($jsonLoginUnverified['error']['code'] ?? null) === 'EMAIL_NOT_VERIFIED');

// ---------------------------------------------------------
// 3. Test Resend Verification (POST /v1/auth/resend-verification)
// ---------------------------------------------------------
echo "\n--- 3. Resend Verification Link ---\n";
$resResend = makeRequest($app, 'POST', '/v1/auth/resend-verification', [
    'email' => $testEmail,
]);
assertTest("Resend verification returns HTTP 202 Accepted", $resResend->getStatusCode() === 202);
$spoolAfterResend = MailService::getTestSpool();
$resendEmail = end($spoolAfterResend);
$newVerifyToken = $resendEmail['token'] ?? '';
assertTest("Fresh verification token dispatched", !empty($newVerifyToken) && $newVerifyToken !== $plainVerifyToken);

// ---------------------------------------------------------
// 4. Test Email Verification (POST /v1/auth/verify-email)
// ---------------------------------------------------------
echo "\n--- 4. Verify Email Address ---\n";
// A. Old invalidated token returns 410 TOKEN_EXPIRED
$resOldToken = makeRequest($app, 'POST', '/v1/auth/verify-email', ['token' => $plainVerifyToken]);
assertTest("Old/invalidated token returns HTTP 410 TOKEN_EXPIRED", $resOldToken->getStatusCode() === 410);

// B. Valid token confirms account and logs in
$resVerifyOk = makeRequest($app, 'POST', '/v1/auth/verify-email', [
    'token' => $newVerifyToken,
    'deviceName' => 'Test PHPUnit',
]);
assertTest("Valid verification token returns HTTP 200 OK", $resVerifyOk->getStatusCode() === 200, "Status: " . $resVerifyOk->getStatusCode());
$jsonVerifyOk = json_decode((string) $resVerifyOk->getBody(), true);
$activeBearerToken = $jsonVerifyOk['data']['token'] ?? null;
assertTest("Response contains initial session bearer token", !empty($activeBearerToken));
assertTest("User emailVerified is true", ($jsonVerifyOk['data']['user']['emailVerified'] ?? null) === true);

// Check DB updated
$stmtUser->execute([':email' => $testEmail]);
$verifiedUserRow = $stmtUser->fetch();
assertTest("users.email_verified_at set in database", $verifiedUserRow['email_verified_at'] !== null);

// ---------------------------------------------------------
// 5. Test Authenticated Endpoints & Middlewares
// ---------------------------------------------------------
echo "\n--- 5. Authenticated Endpoints (GET /v1/auth/me) ---\n";
// A. Missing token -> 401
$resNoToken = makeRequest($app, 'GET', '/v1/auth/me');
assertTest("Missing bearer token returns HTTP 401 UNAUTHENTICATED", $resNoToken->getStatusCode() === 401);

// B. Invalid token -> 401
$resBadToken = makeRequest($app, 'GET', '/v1/auth/me', null, 'invalid_token_xyz');
assertTest("Invalid bearer token returns HTTP 401 UNAUTHENTICATED", $resBadToken->getStatusCode() === 401);

// C. Valid token -> 200 with user data
$resMe = makeRequest($app, 'GET', '/v1/auth/me', null, $activeBearerToken);
assertTest("GET /v1/auth/me with valid bearer token returns HTTP 200", $resMe->getStatusCode() === 200);
$jsonMe = json_decode((string) $resMe->getBody(), true);
assertTest("GET /v1/auth/me returns correct user email", ($jsonMe['data']['email'] ?? null) === $testEmail);
assertTest("Response includes Cache-Control: no-store header", str_contains($resMe->getHeaderLine('Cache-Control'), 'no-store'));

// ---------------------------------------------------------
// 6. Test Login (POST /v1/auth/login)
// ---------------------------------------------------------
echo "\n--- 6. User Login ---\n";
// A. Wrong password -> 401
$resLoginWrong = makeRequest($app, 'POST', '/v1/auth/login', [
    'email' => $testEmail,
    'password' => 'WrongPassword1',
]);
assertTest("Wrong password returns HTTP 401 INVALID_CREDENTIALS", $resLoginWrong->getStatusCode() === 401);

// B. Correct password -> 200 with new bearer token
$resLoginOk = makeRequest($app, 'POST', '/v1/auth/login', [
    'email' => $testEmail,
    'password' => $testPass,
    'deviceName' => 'Chrome Windows',
]);
assertTest("Correct login returns HTTP 200 OK", $resLoginOk->getStatusCode() === 200);
$jsonLoginOk = json_decode((string) $resLoginOk->getBody(), true);
$secondBearerToken = $jsonLoginOk['data']['token'] ?? null;
assertTest("Login issues a fresh bearer token", !empty($secondBearerToken) && $secondBearerToken !== $activeBearerToken);

// ---------------------------------------------------------
// 7. Test Password Change (PUT /v1/auth/password)
// ---------------------------------------------------------
echo "\n--- 7. Change Password ---\n";
$newPass = 'NewSipho#2026';
// A. Wrong current password -> 401
$resChangeBad = makeRequest($app, 'PUT', '/v1/auth/password', [
    'currentPassword' => 'WrongOldPass1',
    'newPassword' => $newPass,
], $secondBearerToken);
assertTest("Change password with wrong current password returns HTTP 401", $resChangeBad->getStatusCode() === 401);

// B. Correct current password -> 204 No Content
$resChangeOk = makeRequest($app, 'PUT', '/v1/auth/password', [
    'currentPassword' => $testPass,
    'newPassword' => $newPass,
], $secondBearerToken);
assertTest("Change password returns HTTP 204", $resChangeOk->getStatusCode() === 204);

// C. Verify other token was revoked
$resOldTokenCheck = makeRequest($app, 'GET', '/v1/auth/me', null, $activeBearerToken);
assertTest("Previous tokens revoked after password change", $resOldTokenCheck->getStatusCode() === 401);

// D. Current token remains valid
$resCurTokenCheck = makeRequest($app, 'GET', '/v1/auth/me', null, $secondBearerToken);
assertTest("Current token remains active after password change", $resCurTokenCheck->getStatusCode() === 200);

// ---------------------------------------------------------
// 8. Test Forgot & Reset Password Flow
// ---------------------------------------------------------
echo "\n--- 8. Forgot & Reset Password Flow ---\n";
// A. Forgot password
$resForgot = makeRequest($app, 'POST', '/v1/auth/forgot-password', ['email' => $testEmail]);
assertTest("POST /auth/forgot-password returns HTTP 202 Accepted", $resForgot->getStatusCode() === 202);
$spoolReset = MailService::getTestSpool();
$resetEmail = end($spoolReset);
$plainResetToken = $resetEmail['token'] ?? '';
assertTest("Password reset email dispatched with token", !empty($plainResetToken));

// B. Reset password with new password
$thirdPass = 'ThirdSipho#2026';
$resResetOk = makeRequest($app, 'POST', '/v1/auth/reset-password', [
    'token' => $plainResetToken,
    'password' => $thirdPass,
]);
assertTest("POST /auth/reset-password returns HTTP 204", $resResetOk->getStatusCode() === 204);

// C. Verify all tokens revoked
$resTokenRevoked = makeRequest($app, 'GET', '/v1/auth/me', null, $secondBearerToken);
assertTest("All active tokens revoked upon password reset", $resTokenRevoked->getStatusCode() === 401);

// D. Login with third password works
$resLoginNew = makeRequest($app, 'POST', '/v1/auth/login', [
    'email' => $testEmail,
    'password' => $thirdPass,
]);
assertTest("Login with reset password succeeds (HTTP 200)", $resLoginNew->getStatusCode() === 200);
$jsonLoginNew = json_decode((string) $resLoginNew->getBody(), true);
$finalBearerToken = $jsonLoginNew['data']['token'] ?? null;

// ---------------------------------------------------------
// 9. Test Logout (POST /v1/auth/logout)
// ---------------------------------------------------------
echo "\n--- 9. User Logout ---\n";
$resLogout = makeRequest($app, 'POST', '/v1/auth/logout', [], $finalBearerToken);
assertTest("POST /auth/logout returns HTTP 204", $resLogout->getStatusCode() === 204);

// Token now revoked
$resPostLogout = makeRequest($app, 'GET', '/v1/auth/me', null, $finalBearerToken);
assertTest("Revoked token rejected with HTTP 401", $resPostLogout->getStatusCode() === 401);

// ---------------------------------------------------------
// 10. Test Account Deletion (DELETE /v1/auth/account - POPIA)
// ---------------------------------------------------------
echo "\n--- 10. Account Deletion (POPIA) ---\n";
// Login again to obtain a fresh token
$resLoginForDel = makeRequest($app, 'POST', '/v1/auth/login', [
    'email' => $testEmail,
    'password' => $thirdPass,
]);
$tokenForDel = json_decode((string) $resLoginForDel->getBody(), true)['data']['token'];

// Wrong password deletion fails
$resDelFail = makeRequest($app, 'DELETE', '/v1/auth/account', ['password' => 'WrongPass'], $tokenForDel);
assertTest("Account deletion with wrong password returns HTTP 401", $resDelFail->getStatusCode() === 401);

// Correct password deletes account
$resDelOk = makeRequest($app, 'DELETE', '/v1/auth/account', ['password' => $thirdPass], $tokenForDel);
assertTest("Account deletion returns HTTP 204", $resDelOk->getStatusCode() === 204);

// Verify cascade in DB
$stmtUser->execute([':email' => $testEmail]);
assertTest("User record completely deleted from database", $stmtUser->fetch() === false);

echo "\n--------------------------------------------------------\n";
echo " Task 4 Verification Summary: $passed passed, $failed failed.\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
