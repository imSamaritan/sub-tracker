<?php
declare(strict_types=1);

namespace SubTracker\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

class PaymentRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    /**
     * List payments for user with filtering, pagination, and totals (§8.6).
     *
     * @param int $userId
     * @param array<string, mixed> $params
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, perPage: int, total: int, totalAmount: float}}
     */
    public function listByUser(int $userId, array $params = []): array
    {
        $where = ["user_id = :user_id"];
        $bindings = [':user_id' => $userId];

        // 1. Subscription ID filter
        if (!empty($params['subscriptionId'])) {
            $where[] = "subscription_id = :sub_id";
            $bindings[':sub_id'] = (int) $params['subscriptionId'];
        }

        // 2. Date range filters
        if (!empty($params['from'])) {
            $where[] = "paid_date >= :from_date";
            $bindings[':from_date'] = (string) $params['from'];
        }
        if (!empty($params['to'])) {
            $where[] = "paid_date <= :to_date";
            $bindings[':to_date'] = (string) $params['to'];
        }

        $whereSql = implode(' AND ', $where);

        // Compute count and aggregate total sum of filtered payments
        $countSql = "SELECT COUNT(*) AS total_count, COALESCE(SUM(amount), 0.0) AS total_amount FROM payments WHERE $whereSql";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($bindings);
        $agg = $countStmt->fetch(PDO::FETCH_ASSOC);

        $total = (int) ($agg['total_count'] ?? 0);
        $totalAmount = round((float) ($agg['total_amount'] ?? 0.0), 2);

        // Pagination parameters
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($params['perPage'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        // Fetch page rows
        $sql = "
            SELECT id, subscription_id, subscription_name, amount, cycle_due_date, paid_date, created_at
            FROM payments
            WHERE $whereSql
            ORDER BY paid_date DESC, id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($bindings as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = array_map([$this, 'formatPayment'], $rows);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalAmount' => $totalAmount,
            ],
        ];
    }

    /**
     * Format raw payment row into API contract (§8.6).
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
