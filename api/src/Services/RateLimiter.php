<?php
declare(strict_types=1);

namespace SubTracker\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    /**
     * Increment the counter for a given bucket and check if the limit has been reached.
     *
     * @param string $bucket Identifier (e.g. 'login:ip:127.0.0.1' or 'register:ip:...')
     * @param int $maxAttempts Maximum permitted requests within the window
     * @param int $windowSeconds Window duration in seconds
     * @return array{allowed: bool, hits: int, maxAttempts: int, remaining: int, retryAfter: int}
     */
    public function attempt(string $bucket, int $maxAttempts, int $windowSeconds): array
    {
        $now = time();
        $windowStartTimestamp = $now - ($now % $windowSeconds);
        $windowStart = gmdate('Y-m-d H:i:s', $windowStartTimestamp);
        $retryAfter = max(1, ($windowStartTimestamp + $windowSeconds) - $now);

        // Portable upsert supported across both MySQL 8.4 and MariaDB 10.6+
        $upsertStmt = $this->pdo->prepare("
            INSERT INTO rate_limits (bucket, window_start, hits)
            VALUES (:bucket, :window_start, 1)
            ON DUPLICATE KEY UPDATE hits = hits + 1
        ");
        $upsertStmt->execute([
            ':bucket' => $bucket,
            ':window_start' => $windowStart,
        ]);

        $selectStmt = $this->pdo->prepare("
            SELECT hits FROM rate_limits
            WHERE bucket = :bucket AND window_start = :window_start
        ");
        $selectStmt->execute([
            ':bucket' => $bucket,
            ':window_start' => $windowStart,
        ]);

        $hits = (int) $selectStmt->fetchColumn();
        $allowed = $hits <= $maxAttempts;
        $remaining = max(0, $maxAttempts - $hits);

        return [
            'allowed' => $allowed,
            'hits' => $hits,
            'maxAttempts' => $maxAttempts,
            'remaining' => $remaining,
            'retryAfter' => $retryAfter,
        ];
    }

    /**
     * Check current status of a bucket without incrementing.
     *
     * @param string $bucket
     * @param int $maxAttempts
     * @param int $windowSeconds
     * @return array{allowed: bool, hits: int, maxAttempts: int, remaining: int, retryAfter: int}
     */
    public function check(string $bucket, int $maxAttempts, int $windowSeconds): array
    {
        $now = time();
        $windowStartTimestamp = $now - ($now % $windowSeconds);
        $windowStart = gmdate('Y-m-d H:i:s', $windowStartTimestamp);
        $retryAfter = max(1, ($windowStartTimestamp + $windowSeconds) - $now);

        $stmt = $this->pdo->prepare("
            SELECT hits FROM rate_limits
            WHERE bucket = :bucket AND window_start = :window_start
        ");
        $stmt->execute([
            ':bucket' => $bucket,
            ':window_start' => $windowStart,
        ]);

        $hits = (int) ($stmt->fetchColumn() ?: 0);
        $allowed = $hits < $maxAttempts;
        $remaining = max(0, $maxAttempts - $hits);

        return [
            'allowed' => $allowed,
            'hits' => $hits,
            'maxAttempts' => $maxAttempts,
            'remaining' => $remaining,
            'retryAfter' => $retryAfter,
        ];
    }

    /**
     * Reset / clear hits for a bucket across all windows.
     *
     * @param string $bucket
     */
    public function reset(string $bucket): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM rate_limits WHERE bucket = :bucket");
        $stmt->execute([':bucket' => $bucket]);
    }

    /**
     * Delete expired rate-limit records older than 24 hours.
     * Used by bin/cleanup.php cron job.
     *
     * @return int Number of rows deleted
     */
    public function pruneExpired(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
