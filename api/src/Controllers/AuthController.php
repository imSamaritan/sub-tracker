<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Services\MailService;
use SubTracker\Services\RateLimiter;
use SubTracker\Support\ApiResponse;
use SubTracker\Support\Validator;

class AuthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $rateLimiter,
        private readonly MailService $mailService,
        private readonly array $settings
    ) {}

    /**
     * POST /auth/register
     */
    public function register(Request $request, Response $response): Response
    {
        $ip = $this->getClientIp($request);

        // Rate limit: max 5 registrations/hour per IP (§8.1)
        $rateCheck = $this->rateLimiter->attempt("register:ip:{$ip}", 5, 3600);
        if (!$rateCheck['allowed']) {
            $response = $response->withHeader('Retry-After', (string) $rateCheck['retryAfter']);
            return ApiResponse::error(
                $response,
                'RATE_LIMITED',
                sprintf('Too many registrations from this IP. Please try again in %d seconds.', $rateCheck['retryAfter']),
                429
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = Validator::validateRegistration($body);
        if (!empty($errors)) {
            return ApiResponse::error($response, 'VALIDATION_FAILED', 'Some fields are invalid.', 422, $errors);
        }

        $email = strtolower(trim((string) $body['email']));
        $password = (string) $body['password'];
        $displayName = !empty($body['displayName']) ? trim((string) $body['displayName']) : explode('@', $email)[0];
        $timezone = !empty($body['timezone']) ? trim((string) $body['timezone']) : 'Africa/Johannesburg';

        // Check if email is already registered
        $stmt = $this->pdo->prepare("SELECT id, email_verified_at FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $existing = $stmt->fetch();

        if ($existing) {
            return ApiResponse::error(
                $response,
                'EMAIL_TAKEN',
                'An account with this email address already exists.',
                409
            );
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $this->pdo->beginTransaction();
        try {
            // 1. Insert user
            $userStmt = $this->pdo->prepare("
                INSERT INTO users (email, password_hash, display_name, email_verified_at, created_at, updated_at)
                VALUES (:email, :password_hash, :display_name, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ");
            $userStmt->execute([
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':display_name' => $displayName,
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            // 2. Insert user profile
            $profileStmt = $this->pdo->prepare("
                INSERT INTO user_profiles (user_id, monthly_salary, salary_day, currency, currency_symbol, system_notifications_enabled, theme, timezone)
                VALUES (:user_id, 0.00, NULL, 'ZAR', 'R', 0, 'dark', :timezone)
            ");
            $profileStmt->execute([
                ':user_id' => $userId,
                ':timezone' => $timezone,
            ]);

            // 3. Insert email verification token (24 hour TTL)
            $verifyStmt = $this->pdo->prepare("
                INSERT INTO email_verifications (user_id, email, token_hash, expires_at, created_at)
                VALUES (:user_id, :email, :token_hash, UTC_TIMESTAMP() + INTERVAL 24 HOUR, UTC_TIMESTAMP())
            ");
            $verifyStmt->execute([
                ':user_id' => $userId,
                ':email' => $email,
                ':token_hash' => $tokenHash,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // 4. Dispatch verification email
        $this->mailService->sendVerificationEmail($email, $displayName, $plainToken);

        return ApiResponse::success($response, [
            'message' => 'Account created. Please check your email to verify your account before logging in.',
            'email' => $email,
            'requiresVerification' => true,
        ], 202);
    }

    /**
     * POST /auth/verify-email
     */
    public function verifyEmail(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $token = trim((string) ($body['token'] ?? ''));
        $deviceName = !empty($body['deviceName']) ? trim((string) $body['deviceName']) : 'Browser';

        if (empty($token)) {
            return ApiResponse::error($response, 'VALIDATION_FAILED', 'Verification token is required.', 422, [
                'token' => 'Please provide a valid verification token.',
            ]);
        }

        $tokenHash = hash('sha256', $token);

        $stmt = $this->pdo->prepare("
            SELECT id, user_id, email, expires_at, used_at
            FROM email_verifications
            WHERE token_hash = :token_hash
            LIMIT 1
        ");
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) <= time()) {
            return ApiResponse::error(
                $response,
                'TOKEN_EXPIRED',
                'Verification link has expired or has already been used.',
                410
            );
        }

        $userId = (int) $row['user_id'];
        $plainAuthToken = bin2hex(random_bytes(32));
        $authTokenHash = hash('sha256', $plainAuthToken);
        $ttlDays = (int) ($this->settings['token_ttl_days'] ?? 30);
        $tokenExpiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400));

        $this->pdo->beginTransaction();
        try {
            // Mark token as used
            $updateVerify = $this->pdo->prepare("
                UPDATE email_verifications
                SET used_at = UTC_TIMESTAMP()
                WHERE id = :id
            ");
            $updateVerify->execute([':id' => $row['id']]);

            // Mark user email verified
            $updateUser = $this->pdo->prepare("
                UPDATE users
                SET email_verified_at = UTC_TIMESTAMP(), last_login_at = UTC_TIMESTAMP()
                WHERE id = :user_id
            ");
            $updateUser->execute([':user_id' => $userId]);

            // Issue session bearer token
            $insertToken = $this->pdo->prepare("
                INSERT INTO auth_tokens (user_id, token_hash, device_name, expires_at, created_at)
                VALUES (:user_id, :token_hash, :device_name, :expires_at, UTC_TIMESTAMP())
            ");
            $insertToken->execute([
                ':user_id' => $userId,
                ':token_hash' => $authTokenHash,
                ':device_name' => $deviceName,
                ':expires_at' => $tokenExpiresAt,
            ]);

            // Fetch user info
            $userStmt = $this->pdo->prepare("SELECT id, email, display_name, created_at FROM users WHERE id = :id");
            $userStmt->execute([':id' => $userId]);
            $user = $userStmt->fetch();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $utcCreated = new DateTimeImmutable($user['created_at'], new DateTimeZone('UTC'));
        $utcExpires = new DateTimeImmutable($tokenExpiresAt, new DateTimeZone('UTC'));

        return ApiResponse::success($response, [
            'token' => $plainAuthToken,
            'expiresAt' => $utcExpires->format('Y-m-d\TH:i:s\Z'),
            'user' => [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'displayName' => $user['display_name'],
                'emailVerified' => true,
                'createdAt' => $utcCreated->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);
    }

    /**
     * POST /auth/resend-verification
     */
    public function resendVerification(Request $request, Response $response): Response
    {
        $ip = $this->getClientIp($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (!empty($email)) {
            $emailHash = hash('sha256', $email);
            // Rate limit: max 3 per 15 minutes (§8.1)
            $checkIp = $this->rateLimiter->attempt("verify-resend:ip:{$ip}", 3, 900);
            $checkEmail = $this->rateLimiter->attempt("verify-resend:email:{$emailHash}", 3, 900);

            if (!$checkIp['allowed'] || !$checkEmail['allowed']) {
                $retryAfter = max($checkIp['retryAfter'], $checkEmail['retryAfter']);
                $response = $response->withHeader('Retry-After', (string) $retryAfter);
                return ApiResponse::error(
                    $response,
                    'RATE_LIMITED',
                    sprintf('Too many requests. Please try again in %d seconds.', $retryAfter),
                    429
                );
            }
        }

        // Always return 202 to prevent user enumeration
        $standardResponse = ApiResponse::success($response, [
            'message' => 'If the account exists and is unverified, a verification link has been sent.',
        ], 202);

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $standardResponse;
        }

        $stmt = $this->pdo->prepare("SELECT id, email, display_name, email_verified_at FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || $user['email_verified_at'] !== null) {
            return $standardResponse;
        }

        $userId = (int) $user['id'];
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $this->pdo->beginTransaction();
        try {
            // Invalidate unused prior tokens
            $invalidate = $this->pdo->prepare("
                UPDATE email_verifications
                SET used_at = UTC_TIMESTAMP()
                WHERE user_id = :user_id AND used_at IS NULL
            ");
            $invalidate->execute([':user_id' => $userId]);

            // Insert new token
            $insert = $this->pdo->prepare("
                INSERT INTO email_verifications (user_id, email, token_hash, expires_at, created_at)
                VALUES (:user_id, :email, :token_hash, UTC_TIMESTAMP() + INTERVAL 24 HOUR, UTC_TIMESTAMP())
            ");
            $insert->execute([
                ':user_id' => $userId,
                ':email' => $email,
                ':token_hash' => $tokenHash,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->mailService->sendVerificationEmail($email, $user['display_name'], $plainToken);

        return $standardResponse;
    }

    /**
     * POST /auth/login
     */
    public function login(Request $request, Response $response): Response
    {
        $ip = $this->getClientIp($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = Validator::validateLogin($body);
        if (!empty($errors)) {
            return ApiResponse::error($response, 'VALIDATION_FAILED', 'Some fields are invalid.', 422, $errors);
        }

        $email = strtolower(trim((string) $body['email']));
        $password = (string) $body['password'];
        $deviceName = !empty($body['deviceName']) ? trim((string) $body['deviceName']) : 'Browser';
        $emailHash = hash('sha256', $email);

        // Rate limit: max 5 failed attempts per 5 minutes (§8.1)
        $ipBucket = "login:ip:{$ip}";
        $emailBucket = "login:email:{$emailHash}";

        $ipCheck = $this->rateLimiter->check($ipBucket, 5, 300);
        $emailCheck = $this->rateLimiter->check($emailBucket, 5, 300);

        if (!$ipCheck['allowed'] || !$emailCheck['allowed']) {
            $retryAfter = max($ipCheck['retryAfter'], $emailCheck['retryAfter']);
            $response = $response->withHeader('Retry-After', (string) $retryAfter);
            return ApiResponse::error(
                $response,
                'RATE_LIMITED',
                sprintf('Too many failed login attempts. Please try again in %d seconds.', $retryAfter),
                429
            );
        }

        $stmt = $this->pdo->prepare("
            SELECT id, email, password_hash, display_name, email_verified_at, created_at
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->rateLimiter->attempt($ipBucket, 5, 300);
            $this->rateLimiter->attempt($emailBucket, 5, 300);

            return ApiResponse::error(
                $response,
                'INVALID_CREDENTIALS',
                'Invalid email or password.',
                401
            );
        }

        // Email verification check (§8.1 - 403 EMAIL_NOT_VERIFIED)
        if ($user['email_verified_at'] === null) {
            return ApiResponse::error(
                $response,
                'EMAIL_NOT_VERIFIED',
                'Your email address has not been verified. Please check your inbox or request a new verification link.',
                403
            );
        }

        // Reset rate limiter on successful password verification
        $this->rateLimiter->reset($ipBucket);
        $this->rateLimiter->reset($emailBucket);

        $userId = (int) $user['id'];
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $ttlDays = (int) ($this->settings['token_ttl_days'] ?? 30);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400));

        $this->pdo->beginTransaction();
        try {
            $tokenStmt = $this->pdo->prepare("
                INSERT INTO auth_tokens (user_id, token_hash, device_name, expires_at, created_at)
                VALUES (:user_id, :token_hash, :device_name, :expires_at, UTC_TIMESTAMP())
            ");
            $tokenStmt->execute([
                ':user_id' => $userId,
                ':token_hash' => $tokenHash,
                ':device_name' => $deviceName,
                ':expires_at' => $expiresAt,
            ]);

            $updateLogin = $this->pdo->prepare("
                UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id
            ");
            $updateLogin->execute([':id' => $userId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $utcCreated = new DateTimeImmutable($user['created_at'], new DateTimeZone('UTC'));
        $utcExpires = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));

        return ApiResponse::success($response, [
            'token' => $plainToken,
            'expiresAt' => $utcExpires->format('Y-m-d\TH:i:s\Z'),
            'user' => [
                'id' => $userId,
                'email' => $user['email'],
                'displayName' => $user['display_name'],
                'emailVerified' => true,
                'createdAt' => $utcCreated->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);
    }

    /**
     * POST /auth/logout
     */
    public function logout(Request $request, Response $response): Response
    {
        $token = $request->getAttribute('token');
        $userId = (int) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);

        if ($token && isset($token['id'])) {
            $stmt = $this->pdo->prepare("
                UPDATE auth_tokens
                SET revoked_at = UTC_TIMESTAMP()
                WHERE id = :token_id
            ");
            $stmt->execute([':token_id' => $token['id']]);
        }

        if (!empty($body['pushEndpoint'])) {
            $endpointHash = hash('sha256', (string) $body['pushEndpoint']);
            $pushStmt = $this->pdo->prepare("
                DELETE FROM push_subscriptions
                WHERE user_id = :user_id AND endpoint_hash = :hash
            ");
            $pushStmt->execute([
                ':user_id' => $userId,
                ':hash' => $endpointHash,
            ]);
        }

        return $response->withStatus(204);
    }

    /**
     * GET /auth/me
     */
    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $utcCreated = new DateTimeImmutable($user['createdAt'], new DateTimeZone('UTC'));

        return ApiResponse::success($response, [
            'id' => $user['id'],
            'email' => $user['email'],
            'displayName' => $user['displayName'],
            'emailVerified' => $user['emailVerified'],
            'createdAt' => $utcCreated->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * POST /auth/forgot-password
     */
    public function forgotPassword(Request $request, Response $response): Response
    {
        $ip = $this->getClientIp($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (!empty($email)) {
            $emailHash = hash('sha256', $email);
            // Rate limit: max 5 forgot-password per hour (§10)
            $checkIp = $this->rateLimiter->attempt("forgot-password:ip:{$ip}", 5, 3600);
            $checkEmail = $this->rateLimiter->attempt("forgot-password:email:{$emailHash}", 5, 3600);

            if (!$checkIp['allowed'] || !$checkEmail['allowed']) {
                $retryAfter = max($checkIp['retryAfter'], $checkEmail['retryAfter']);
                $response = $response->withHeader('Retry-After', (string) $retryAfter);
                return ApiResponse::error(
                    $response,
                    'RATE_LIMITED',
                    sprintf('Too many requests. Please try again in %d seconds.', $retryAfter),
                    429
                );
            }
        }

        // Always return 202 to prevent account enumeration
        $standardResponse = ApiResponse::success($response, [
            'message' => 'If the account exists, a password reset link has been sent.',
        ], 202);

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $standardResponse;
        }

        $stmt = $this->pdo->prepare("SELECT id, email, display_name FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            return $standardResponse;
        }

        $userId = (int) $user['id'];
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $this->pdo->beginTransaction();
        try {
            // Invalidate unused prior tokens
            $invalidate = $this->pdo->prepare("
                UPDATE password_resets
                SET used_at = UTC_TIMESTAMP()
                WHERE user_id = :user_id AND used_at IS NULL
            ");
            $invalidate->execute([':user_id' => $userId]);

            // Insert new reset token (60 minute TTL)
            $insert = $this->pdo->prepare("
                INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
                VALUES (:user_id, :token_hash, UTC_TIMESTAMP() + INTERVAL 60 MINUTE, UTC_TIMESTAMP())
            ");
            $insert->execute([
                ':user_id' => $userId,
                ':token_hash' => $tokenHash,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->mailService->sendPasswordResetEmail($email, $user['display_name'], $plainToken);

        return $standardResponse;
    }

    /**
     * POST /auth/reset-password
     */
    public function resetPassword(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $token = trim((string) ($body['token'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $errors = [];
        if (empty($token)) {
            $errors['token'] = 'Reset token is required.';
        }
        $pwdErr = Validator::validatePassword($password);
        if ($pwdErr !== null) {
            $errors['password'] = $pwdErr;
        }

        if (!empty($errors)) {
            return ApiResponse::error($response, 'VALIDATION_FAILED', 'Some fields are invalid.', 422, $errors);
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $this->pdo->prepare("
            SELECT id, user_id, expires_at, used_at
            FROM password_resets
            WHERE token_hash = :hash
            LIMIT 1
        ");
        $stmt->execute([':hash' => $tokenHash]);
        $reset = $stmt->fetch();

        if (!$reset || $reset['used_at'] !== null || strtotime($reset['expires_at']) <= time()) {
            return ApiResponse::error(
                $response,
                'TOKEN_EXPIRED',
                'Password reset link has expired or has already been used.',
                410
            );
        }

        $userId = (int) $reset['user_id'];
        $newHash = password_hash($password, PASSWORD_BCRYPT);

        $this->pdo->beginTransaction();
        try {
            // Mark reset token used
            $markUsed = $this->pdo->prepare("
                UPDATE password_resets
                SET used_at = UTC_TIMESTAMP()
                WHERE id = :id
            ");
            $markUsed->execute([':id' => $reset['id']]);

            // Update user password
            $updateUser = $this->pdo->prepare("
                UPDATE users
                SET password_hash = :hash, updated_at = UTC_TIMESTAMP()
                WHERE id = :user_id
            ");
            $updateUser->execute([
                ':hash' => $newHash,
                ':user_id' => $userId,
            ]);

            // Revoke all of the user's active auth tokens (§8.1)
            $revokeTokens = $this->pdo->prepare("
                UPDATE auth_tokens
                SET revoked_at = UTC_TIMESTAMP()
                WHERE user_id = :user_id AND revoked_at IS NULL
            ");
            $revokeTokens->execute([':user_id' => $userId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $response->withStatus(204);
    }

    /**
     * PUT /auth/password
     */
    public function changePassword(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $token = $request->getAttribute('token');
        $body = (array) ($request->getParsedBody() ?? []);

        $currentPassword = (string) ($body['currentPassword'] ?? '');
        $newPassword = (string) ($body['newPassword'] ?? '');

        $errors = [];
        if (empty($currentPassword)) {
            $errors['currentPassword'] = 'Please enter your current password.';
        }
        $pwdErr = Validator::validatePassword($newPassword);
        if ($pwdErr !== null) {
            $errors['newPassword'] = $pwdErr;
        }

        if (!empty($errors)) {
            return ApiResponse::error($response, 'VALIDATION_FAILED', 'Some fields are invalid.', 422, $errors);
        }

        $stmt = $this->pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return ApiResponse::error($response, 'INVALID_CREDENTIALS', 'Current password is incorrect.', 401);
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

        $this->pdo->beginTransaction();
        try {
            $updateUser = $this->pdo->prepare("
                UPDATE users
                SET password_hash = :hash, updated_at = UTC_TIMESTAMP()
                WHERE id = :id
            ");
            $updateUser->execute([
                ':hash' => $newHash,
                ':id' => $userId,
            ]);

            // Revoke all other tokens except current
            $revokeOthers = $this->pdo->prepare("
                UPDATE auth_tokens
                SET revoked_at = UTC_TIMESTAMP()
                WHERE user_id = :user_id AND id != :token_id AND revoked_at IS NULL
            ");
            $revokeOthers->execute([
                ':user_id' => $userId,
                ':token_id' => $token['id'],
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $response->withStatus(204);
    }

    /**
     * DELETE /auth/account
     */
    public function deleteAccount(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);
        $password = (string) ($body['password'] ?? '');

        if (empty($password)) {
            return ApiResponse::error($response, 'VALIDATION_FAILED', 'Password is required to delete account.', 422, [
                'password' => 'Please enter your password to confirm account deletion.',
            ]);
        }

        $stmt = $this->pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ApiResponse::error($response, 'INVALID_CREDENTIALS', 'Password is incorrect.', 401);
        }

        // POPIA deletion: Cascades to user_profiles, auth_tokens, subscriptions, payments, etc.
        $deleteStmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
        $deleteStmt->execute([':id' => $userId]);

        return $response->withStatus(204);
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();

        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $serverParams['HTTP_X_FORWARDED_FOR']);
            $first = trim($parts[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        if (!empty($serverParams['HTTP_CLIENT_IP']) && filter_var($serverParams['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
            return $serverParams['HTTP_CLIENT_IP'];
        }

        if (!empty($serverParams['REMOTE_ADDR']) && filter_var($serverParams['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return $serverParams['REMOTE_ADDR'];
        }

        return '127.0.0.1';
    }
}
