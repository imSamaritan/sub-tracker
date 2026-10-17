<?php
declare(strict_types=1);

namespace SubTracker\Services;

use PDO;

class CleanupService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $rateLimiter,
        private readonly array $settings = []
    ) {}

    /**
     * Execute daily housekeeping cleanup (§15.8).
     *
     * @return array{
     *   unverifiedUsersDeleted: int,
     *   emailVerificationsDeleted: int,
     *   passwordResetsDeleted: int,
     *   rateLimitsDeleted: int,
     *   authTokensDeleted: int
     * }
     */
    public function runCleanup(): array
    {
        $unverifiedTtlDays = max(1, (int) ($this->settings['unverified_account_ttl_days'] ?? 7));

        // 1. Unverified accounts older than UNVERIFIED_ACCOUNT_TTL_DAYS (default 7 days)
        $userStmt = $this->pdo->prepare("
            DELETE FROM users
            WHERE email_verified_at IS NULL
              AND created_at < UTC_TIMESTAMP() - INTERVAL :days DAY
        ");
        $userStmt->execute([':days' => $unverifiedTtlDays]);
        $unverifiedUsersDeleted = $userStmt->rowCount();

        // 2. Expired or already-used email verification tokens
        $verifyStmt = $this->pdo->prepare("
            DELETE FROM email_verifications
            WHERE expires_at < UTC_TIMESTAMP()
               OR (used_at IS NOT NULL AND used_at < UTC_TIMESTAMP() - INTERVAL 1 DAY)
        ");
        $verifyStmt->execute();
        $emailVerificationsDeleted = $verifyStmt->rowCount();

        // 3. Expired or already-used password reset tokens
        $resetStmt = $this->pdo->prepare("
            DELETE FROM password_resets
            WHERE expires_at < UTC_TIMESTAMP()
               OR (used_at IS NOT NULL AND used_at < UTC_TIMESTAMP() - INTERVAL 1 DAY)
        ");
        $resetStmt->execute();
        $passwordResetsDeleted = $resetStmt->rowCount();

        // 4. Rate-limit counter rows older than 24 hours
        $rateLimitsDeleted = $this->rateLimiter->pruneExpired();

        // 5. Expired auth tokens and revoked auth tokens older than 30 days
        $tokenStmt = $this->pdo->prepare("
            DELETE FROM auth_tokens
            WHERE expires_at < UTC_TIMESTAMP()
               OR (revoked_at IS NOT NULL AND revoked_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)
        ");
        $tokenStmt->execute();
        $authTokensDeleted = $tokenStmt->rowCount();

        return [
            'unverifiedUsersDeleted' => $unverifiedUsersDeleted,
            'emailVerificationsDeleted' => $emailVerificationsDeleted,
            'passwordResetsDeleted' => $passwordResetsDeleted,
            'rateLimitsDeleted' => $rateLimitsDeleted,
            'authTokensDeleted' => $authTokensDeleted,
        ];
    }
}
