<?php
declare(strict_types=1);

namespace SubTracker\Repositories;

use PDO;

class PushSubscriptionRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    /**
     * Upsert a push subscription by endpoint hash (re-assigning to current user if shared browser)
     * and enable system_notifications_enabled on user_profiles (§8.9).
     *
     * @param int $userId
     * @param string $endpoint
     * @param string $p256dh
     * @param string $auth
     * @param string $contentEncoding
     * @param string|null $userAgent
     * @return array{id: int, endpoint: string, contentEncoding: string, systemNotificationsEnabled: bool}
     */
    public function upsert(
        int $userId,
        string $endpoint,
        string $p256dh,
        string $auth,
        string $contentEncoding = 'aes128gcm',
        ?string $userAgent = null
    ): array {
        $uaTrimmed = $userAgent !== null ? mb_substr(trim($userAgent), 0, 255) : null;
        $endpointHash = hash('sha256', $endpoint);

        $inTransaction = $this->pdo->inTransaction();
        if (!$inTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO push_subscriptions (
                    user_id, endpoint, p256dh_key, auth_key, content_encoding, user_agent, created_at, updated_at
                ) VALUES (
                    :uid, :endpoint, :p256dh, :auth, :enc, :ua, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                )
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    p256dh_key = VALUES(p256dh_key),
                    auth_key = VALUES(auth_key),
                    content_encoding = VALUES(content_encoding),
                    user_agent = VALUES(user_agent),
                    failure_count = 0,
                    updated_at = UTC_TIMESTAMP()
            ");

            $stmt->execute([
                ':uid' => $userId,
                ':endpoint' => $endpoint,
                ':p256dh' => $p256dh,
                ':auth' => $auth,
                ':enc' => $contentEncoding,
                ':ua' => $uaTrimmed,
            ]);

            // Also enable system_notifications_enabled on user_profiles (§8.9)
            $profStmt = $this->pdo->prepare("
                UPDATE user_profiles
                SET system_notifications_enabled = 1, updated_at = UTC_TIMESTAMP()
                WHERE user_id = :uid
            ");
            $profStmt->execute([':uid' => $userId]);

            // Fetch the upserted subscription ID
            $idStmt = $this->pdo->prepare("
                SELECT id FROM push_subscriptions WHERE endpoint_hash = :hash LIMIT 1
            ");
            $idStmt->execute([':hash' => $endpointHash]);
            $subId = (int) $idStmt->fetchColumn();

            if (!$inTransaction) {
                $this->pdo->commit();
            }

            return [
                'id' => $subId,
                'endpoint' => $endpoint,
                'contentEncoding' => $contentEncoding,
                'systemNotificationsEnabled' => true,
            ];
        } catch (\Throwable $e) {
            if (!$inTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete a push subscription belonging to the specified user (§8.9).
     *
     * @param int $userId
     * @param string $endpoint
     * @return int Number of deleted rows
     */
    public function deleteByUserAndEndpoint(int $userId, string $endpoint): int
    {
        $endpointHash = hash('sha256', $endpoint);
        $stmt = $this->pdo->prepare("
            DELETE FROM push_subscriptions
            WHERE user_id = :uid AND endpoint_hash = :hash
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':hash' => $endpointHash,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Find all registered push subscriptions for a user.
     *
     * @param int $userId
     * @return array<int, array<string, mixed>>
     */
    public function findByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, user_id, endpoint, endpoint_hash, p256dh_key, auth_key, content_encoding, failure_count, last_success_at
            FROM push_subscriptions
            WHERE user_id = :uid
            ORDER BY id ASC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Delete a push subscription row by primary key (e.g., on 404/410 from push service).
     */
    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM push_subscriptions WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Record a successful push delivery on a subscription row (§9 step 6).
     */
    public function markSuccess(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE push_subscriptions
            SET failure_count = 0, last_success_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Increment failure_count on a push subscription, deleting it if it reaches maxFailures (§9 step 6).
     *
     * @param int $id
     * @param int $maxFailures Default 10
     * @return bool True if the subscription row was deleted after reaching the threshold
     */
    public function recordFailure(int $id, int $maxFailures = 10): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE push_subscriptions
            SET failure_count = failure_count + 1, updated_at = UTC_TIMESTAMP()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);

        $checkStmt = $this->pdo->prepare("SELECT failure_count FROM push_subscriptions WHERE id = :id");
        $checkStmt->execute([':id' => $id]);
        $count = $checkStmt->fetchColumn();

        if ($count !== false && (int) $count >= $maxFailures) {
            $this->deleteById($id);
            return true;
        }

        return false;
    }
}
