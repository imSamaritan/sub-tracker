<?php
declare(strict_types=1);

namespace SubTracker\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

class SubscriptionRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    /**
     * Get user's local timezone.
     */
    public function getUserTimezone(int $userId): DateTimeZone
    {
        $stmt = $this->pdo->prepare("SELECT timezone FROM user_profiles WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $tzName = $stmt->fetchColumn() ?: 'Africa/Johannesburg';

        try {
            return new DateTimeZone((string) $tzName);
        } catch (\Throwable) {
            return new DateTimeZone('Africa/Johannesburg');
        }
    }

    /**
     * Get user's local today date string ('YYYY-MM-DD').
     */
    public function getUserToday(int $userId): string
    {
        $tz = $this->getUserTimezone($userId);
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    /**
     * Resolve category ID by code, or null if not found.
     */
    public function findCategoryIdByCode(string $code): ?int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM categories WHERE code = :code LIMIT 1");
        $stmt->execute([':code' => $code]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /**
     * List subscriptions for user with optional filters and sorting (§6.3, §8.5).
     *
     * @param int $userId
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function listByUser(int $userId, array $params = []): array
    {
        $where = ["s.user_id = :user_id", "s.deleted_at IS NULL"];
        $bindings = [':user_id' => $userId];

        // 1. Status filter: 'active', 'paused', 'all' (default 'all')
        $status = strtolower((string) ($params['status'] ?? 'all'));
        if ($status === 'active') {
            $where[] = "s.status = 'active'";
        } elseif ($status === 'paused') {
            $where[] = "s.status = 'paused'";
        }

        // 2. Filter param: 'all', 'due', 'paid', 'urgent'
        $filter = strtolower((string) ($params['filter'] ?? 'all'));
        if ($filter === 'due') {
            $where[] = "s.is_paid_this_cycle = 0";
        } elseif ($filter === 'paid') {
            $where[] = "s.is_paid_this_cycle = 1";
        } elseif ($filter === 'urgent') {
            // unpaid AND dueDate <= today + 3 days in user timezone
            $userToday = $this->getUserToday($userId);
            $maxUrgentDate = (new DateTimeImmutable($userToday))->modify('+3 days')->format('Y-m-d');
            $where[] = "s.is_paid_this_cycle = 0";
            $where[] = "s.due_date <= :max_urgent_date";
            $bindings[':max_urgent_date'] = $maxUrgentDate;
        }

        // 3. Category code filter
        if (!empty($params['category'])) {
            $where[] = "c.code = :category_code";
            $bindings[':category_code'] = $params['category'];
        }

        // 4. Search query (q): substring in name, category code, or notes
        if (!empty($params['q'])) {
            $where[] = "(s.name LIKE :search_name OR c.code LIKE :search_cat OR s.notes LIKE :search_notes)";
            $qVal = '%' . $params['q'] . '%';
            $bindings[':search_name'] = $qVal;
            $bindings[':search_cat'] = $qVal;
            $bindings[':search_notes'] = $qVal;
        }

        $sql = "
            SELECT s.id, s.name, c.code AS category, c.label AS category_label,
                   s.amount, s.billing_cycle, s.due_date, s.billing_day,
                   s.start_date, s.icon, s.color, s.notes,
                   s.is_paid_this_cycle, s.status, s.created_at, s.updated_at
            FROM subscriptions s
            JOIN categories c ON s.category_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.is_paid_this_cycle ASC, s.due_date ASC, s.id ASC
            LIMIT 1000
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'formatSubscription'], $rows);
    }

    /**
     * Find single subscription by ID for user.
     */
    public function findById(int $userId, int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.id, s.name, c.code AS category, c.label AS category_label,
                   s.amount, s.billing_cycle, s.due_date, s.billing_day,
                   s.start_date, s.icon, s.color, s.notes,
                   s.is_paid_this_cycle, s.status, s.created_at, s.updated_at
            FROM subscriptions s
            JOIN categories c ON s.category_id = c.id
            WHERE s.id = :id
              AND s.user_id = :user_id
              AND s.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->formatSubscription($row) : null;
    }

    /**
     * Create a new subscription (§6.4, §8.5).
     */
    public function create(int $userId, array $data): array
    {
        $catCode = $data['category'] ?? 'Other';
        $categoryId = $this->findCategoryIdByCode($catCode) ?? $this->findCategoryIdByCode('Other') ?? 14;

        $dueDate = $data['dueDate'];
        $billingDay = (int) (new DateTimeImmutable($dueDate))->format('j');

        $stmt = $this->pdo->prepare("
            INSERT INTO subscriptions (
                user_id, category_id, name, amount, billing_cycle,
                due_date, billing_day, start_date, icon, color, notes,
                is_paid_this_cycle, status, created_at, updated_at
            ) VALUES (
                :user_id, :category_id, :name, :amount, :billing_cycle,
                :due_date, :billing_day, :start_date, :icon, :color, :notes,
                0, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':category_id' => $categoryId,
            ':name' => trim((string) $data['name']),
            ':amount' => (float) $data['amount'],
            ':billing_cycle' => $data['billingCycle'] ?? 'monthly',
            ':due_date' => $dueDate,
            ':billing_day' => $billingDay,
            ':start_date' => !empty($data['startDate']) ? $data['startDate'] : null,
            ':icon' => $data['icon'] ?? 'fa-receipt',
            ':color' => $data['color'] ?? '#6366f1',
            ':notes' => isset($data['notes']) && $data['notes'] !== '' ? trim((string) $data['notes']) : null,
            ':status' => $data['status'] ?? 'active',
        ]);

        $newId = (int) $this->pdo->lastInsertId();
        return (array) $this->findById($userId, $newId);
    }

    /**
     * Update an existing subscription (PUT or PATCH).
     */
    public function update(int $userId, int $id, array $data, bool $isPartial = false): ?array
    {
        $current = $this->findById($userId, $id);
        if (!$current) {
            return null;
        }

        $fields = [];
        $bindings = [':id' => $id, ':user_id' => $userId];

        // 1. Name
        if (array_key_exists('name', $data)) {
            $fields[] = "name = :name";
            $bindings[':name'] = trim((string) $data['name']);
        } elseif (!$isPartial) {
            $fields[] = "name = :name";
            $bindings[':name'] = $current['name'];
        }

        // 2. Category
        if (array_key_exists('category', $data)) {
            $catId = $this->findCategoryIdByCode((string) $data['category']) ?? $this->findCategoryIdByCode('Other') ?? 14;
            $fields[] = "category_id = :category_id";
            $bindings[':category_id'] = $catId;
        } elseif (!$isPartial) {
            $catId = $this->findCategoryIdByCode($current['category']) ?? 14;
            $fields[] = "category_id = :category_id";
            $bindings[':category_id'] = $catId;
        }

        // 3. Amount
        if (array_key_exists('amount', $data)) {
            $fields[] = "amount = :amount";
            $bindings[':amount'] = (float) $data['amount'];
        } elseif (!$isPartial) {
            $fields[] = "amount = :amount";
            $bindings[':amount'] = $current['amount'];
        }

        // 4. Billing Cycle
        if (array_key_exists('billingCycle', $data)) {
            $fields[] = "billing_cycle = :billing_cycle";
            $bindings[':billing_cycle'] = $data['billingCycle'];
        } elseif (!$isPartial) {
            $fields[] = "billing_cycle = :billing_cycle";
            $bindings[':billing_cycle'] = $current['billingCycle'];
        }

        // 5. Due Date & Billing Day
        if (array_key_exists('dueDate', $data)) {
            $newDue = $data['dueDate'];
            $newBillingDay = (int) (new DateTimeImmutable($newDue))->format('j');
            $fields[] = "due_date = :due_date";
            $fields[] = "billing_day = :billing_day";
            $bindings[':due_date'] = $newDue;
            $bindings[':billing_day'] = $newBillingDay;
        } elseif (!$isPartial) {
            $fields[] = "due_date = :due_date";
            $fields[] = "billing_day = :billing_day";
            $bindings[':due_date'] = $current['dueDate'];
            $bindings[':billing_day'] = $current['billingDay'];
        }

        // 6. Start Date
        if (array_key_exists('startDate', $data)) {
            $fields[] = "start_date = :start_date";
            $bindings[':start_date'] = !empty($data['startDate']) ? $data['startDate'] : null;
        } elseif (!$isPartial) {
            $fields[] = "start_date = :start_date";
            $bindings[':start_date'] = $current['startDate'];
        }

        // 7. Icon
        if (array_key_exists('icon', $data)) {
            $fields[] = "icon = :icon";
            $bindings[':icon'] = $data['icon'];
        } elseif (!$isPartial) {
            $fields[] = "icon = :icon";
            $bindings[':icon'] = $current['icon'];
        }

        // 8. Color
        if (array_key_exists('color', $data)) {
            $fields[] = "color = :color";
            $bindings[':color'] = $data['color'];
        } elseif (!$isPartial) {
            $fields[] = "color = :color";
            $bindings[':color'] = $current['color'];
        }

        // 9. Notes
        if (array_key_exists('notes', $data)) {
            $fields[] = "notes = :notes";
            $bindings[':notes'] = isset($data['notes']) && $data['notes'] !== '' ? trim((string) $data['notes']) : null;
        } elseif (!$isPartial) {
            $fields[] = "notes = :notes";
            $bindings[':notes'] = $current['notes'];
        }

        // 10. Status
        if (array_key_exists('status', $data)) {
            $fields[] = "status = :status";
            $bindings[':status'] = $data['status'];
        } elseif (!$isPartial) {
            $fields[] = "status = :status";
            $bindings[':status'] = $current['status'];
        }

        // Always update updated_at
        $fields[] = "updated_at = UTC_TIMESTAMP()";

        $sql = "UPDATE subscriptions SET " . implode(', ', $fields) . " WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $this->findById($userId, $id);
    }

    /**
     * Soft delete subscription (§6.4, §8.5).
     */
    public function softDelete(int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE subscriptions
            SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
            WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
        ");
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark Paid (POST /subscriptions/{id}/payment) in one transaction (§6.4, §8.5).
     */
    public function markPaid(int $userId, int $id, ?string $paidDate = null): ?array
    {
        $this->pdo->beginTransaction();
        try {
            // Lock the subscription row
            $stmt = $this->pdo->prepare("
                SELECT id, name, amount, due_date, is_paid_this_cycle
                FROM subscriptions
                WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
                FOR UPDATE
            ");
            $stmt->execute([':id' => $id, ':user_id' => $userId]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                $this->pdo->rollBack();
                return null;
            }

            $userToday = $this->getUserToday($userId);
            $effectivePaidDate = $paidDate ?? $userToday;

            // If already marked paid, return current state without creating duplicate payment
            if ((int) $sub['is_paid_this_cycle'] === 1) {
                $pmtStmt = $this->pdo->prepare("
                    SELECT id, subscription_id, subscription_name, amount, cycle_due_date, paid_date, created_at
                    FROM payments
                    WHERE subscription_id = :sub_id AND cycle_due_date = :cycle_due
                    ORDER BY id DESC LIMIT 1
                ");
                $pmtStmt->execute([':sub_id' => $id, ':cycle_due' => $sub['due_date']]);
                $pmtRow = $pmtStmt->fetch(PDO::FETCH_ASSOC);

                $this->pdo->commit();

                $currentSub = $this->findById($userId, $id);
                return [
                    'created' => false,
                    'subscription' => $currentSub,
                    'payment' => $pmtRow ? $this->formatPayment($pmtRow) : null,
                ];
            }

            // Set is_paid_this_cycle = 1
            $updStmt = $this->pdo->prepare("
                UPDATE subscriptions
                SET is_paid_this_cycle = 1, updated_at = UTC_TIMESTAMP()
                WHERE id = :id
            ");
            $updStmt->execute([':id' => $id]);

            // Insert snapshot into payments table
            $insStmt = $this->pdo->prepare("
                INSERT INTO payments (
                    user_id, subscription_id, subscription_name, amount,
                    cycle_due_date, paid_date, created_at
                ) VALUES (
                    :user_id, :sub_id, :sub_name, :amount,
                    :cycle_due, :paid_date, UTC_TIMESTAMP()
                )
            ");
            $insStmt->execute([
                ':user_id' => $userId,
                ':sub_id' => $id,
                ':sub_name' => $sub['name'],
                ':amount' => (float) $sub['amount'],
                ':cycle_due' => $sub['due_date'],
                ':paid_date' => $effectivePaidDate,
            ]);
            $paymentId = (int) $this->pdo->lastInsertId();

            $this->pdo->commit();

            $updatedSub = $this->findById($userId, $id);
            $paymentData = [
                'id' => $paymentId,
                'subscriptionId' => $id,
                'name' => $sub['name'],
                'amount' => (float) $sub['amount'],
                'cycleDueDate' => $sub['due_date'],
                'paidDate' => $effectivePaidDate,
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ];

            return [
                'created' => true,
                'subscription' => $updatedSub,
                'payment' => $paymentData,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Un-mark Paid (DELETE /subscriptions/{id}/payment) in one transaction (§6.4, §8.5).
     */
    public function unmarkPaid(int $userId, int $id): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, due_date, is_paid_this_cycle
                FROM subscriptions
                WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
                FOR UPDATE
            ");
            $stmt->execute([':id' => $id, ':user_id' => $userId]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                $this->pdo->rollBack();
                return null;
            }

            // If not paid, no-op
            if ((int) $sub['is_paid_this_cycle'] === 0) {
                $this->pdo->commit();
                $currentSub = $this->findById($userId, $id);
                return [
                    'subscription' => $currentSub,
                    'removedPaymentId' => null,
                ];
            }

            // Find latest payment for this cycle
            $pmtStmt = $this->pdo->prepare("
                SELECT id FROM payments
                WHERE subscription_id = :sub_id AND cycle_due_date = :cycle_due
                ORDER BY id DESC LIMIT 1
            ");
            $pmtStmt->execute([':sub_id' => $id, ':cycle_due' => $sub['due_date']]);
            $paymentId = $pmtStmt->fetchColumn();

            if ($paymentId !== false) {
                $delStmt = $this->pdo->prepare("DELETE FROM payments WHERE id = :id");
                $delStmt->execute([':id' => $paymentId]);
            }

            // Set is_paid_this_cycle = 0
            $updStmt = $this->pdo->prepare("
                UPDATE subscriptions
                SET is_paid_this_cycle = 0, updated_at = UTC_TIMESTAMP()
                WHERE id = :id
            ");
            $updStmt->execute([':id' => $id]);

            $this->pdo->commit();

            $updatedSub = $this->findById($userId, $id);
            return [
                'subscription' => $updatedSub,
                'removedPaymentId' => $paymentId !== false ? (int) $paymentId : null,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Advance dueDate and reset is_paid_this_cycle (POST /subscriptions/{id}/renew) (§6.4, §8.5).
     */
    public function renew(int $userId, int $id): ?array
    {
        $current = $this->findById($userId, $id);
        if (!$current) {
            return null;
        }

        $due = new DateTimeImmutable($current['dueDate']);
        $cycle = $current['billingCycle'];
        $billingDay = $current['billingDay'];

        // §6.4: Month-clamped renewal algorithm
        $newDue = $this->advanceDueDate($due, $cycle, $billingDay);
        $anchor = $billingDay ?? (int) $due->format('j');

        $stmt = $this->pdo->prepare("
            UPDATE subscriptions
            SET due_date = :due_date,
                is_paid_this_cycle = 0,
                billing_day = :billing_day,
                updated_at = UTC_TIMESTAMP()
            WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':due_date' => $newDue->format('Y-m-d'),
            ':billing_day' => $anchor,
            ':id' => $id,
            ':user_id' => $userId,
        ]);

        return $this->findById($userId, $id);
    }

    /**
     * Advance due date algorithm from §6.4.
     */
    public function advanceDueDate(DateTimeImmutable $due, string $cycle, ?int $billingDay): DateTimeImmutable
    {
        if ($cycle === 'weekly') {
            return $due->modify('+7 days');
        }

        $anchor = $billingDay ?? (int) $due->format('j');
        $first = $due->modify('first day of this month')
                     ->modify($cycle === 'yearly' ? '+1 year' : '+1 month');
        $day = min($anchor, (int) $first->format('t')); // clamp to month length
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);
    }

    /**
     * Reset is_paid_this_cycle for all active user subscriptions (§6.4, §8.5).
     */
    public function resetPaid(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE subscriptions
            SET is_paid_this_cycle = 0, updated_at = UTC_TIMESTAMP()
            WHERE user_id = :user_id
              AND deleted_at IS NULL
              AND is_paid_this_cycle = 1
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * Format a raw subscription row into the API contract format (§8.5).
     */
    public function formatSubscription(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'categoryLabel' => (string) ($row['category_label'] ?? $row['category']),
            'amount' => (float) $row['amount'],
            'billingCycle' => (string) $row['billing_cycle'],
            'dueDate' => (string) $row['due_date'],
            'billingDay' => $row['billing_day'] !== null ? (int) $row['billing_day'] : (int) (new DateTimeImmutable($row['due_date']))->format('j'),
            'startDate' => $row['start_date'] !== null ? (string) $row['start_date'] : null,
            'icon' => (string) ($row['icon'] ?? 'fa-receipt'),
            'color' => (string) ($row['color'] ?? '#6366f1'),
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'isPaidThisCycle' => (bool) $row['is_paid_this_cycle'],
            'status' => (string) ($row['status'] ?? 'active'),
            'createdAt' => (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'updatedAt' => (new DateTimeImmutable($row['updated_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Format payment row into API contract.
     */
    public function formatPayment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'subscriptionId' => $row['subscription_id'] !== null ? (int) $row['subscription_id'] : null,
            'name' => (string) ($row['subscription_name'] ?? ''),
            'amount' => (float) $row['amount'],
            'cycleDueDate' => (string) $row['cycle_due_date'],
            'paidDate' => (string) $row['paid_date'],
            'createdAt' => (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
