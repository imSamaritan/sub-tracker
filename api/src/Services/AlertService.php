<?php
declare(strict_types=1);

namespace SubTracker\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

class AlertService
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    /**
     * Compute upcoming and overdue alerts for the given user according to §6.2.
     *
     * @param int $userId
     * @return array<int, array{id: string, subscriptionId: int, subName: string, amount: float, dueDate: string, days: int, type: string}>
     */
    public function getAlertsForUser(int $userId): array
    {
        // 1. Get user timezone
        $tzStmt = $this->pdo->prepare("SELECT timezone FROM user_profiles WHERE user_id = :user_id LIMIT 1");
        $tzStmt->execute([':user_id' => $userId]);
        $tzName = $tzStmt->fetchColumn() ?: 'Africa/Johannesburg';

        try {
            $tz = new DateTimeZone((string) $tzName);
        } catch (\Throwable) {
            $tz = new DateTimeZone('Africa/Johannesburg');
        }

        // User-local today at midnight
        $userToday = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);

        // 2. Fetch live, active, unpaid subscriptions
        $subStmt = $this->pdo->prepare("
            SELECT id, name, amount, due_date
            FROM subscriptions
            WHERE user_id = :user_id
              AND deleted_at IS NULL
              AND status = 'active'
              AND is_paid_this_cycle = 0
            ORDER BY due_date ASC
        ");
        $subStmt->execute([':user_id' => $userId]);
        $subs = $subStmt->fetchAll();

        $alerts = [];
        foreach ($subs as $sub) {
            $due = (new DateTimeImmutable($sub['due_date'], $tz))->setTime(0, 0, 0);
            $days = (int) $userToday->diff($due)->format('%r%a');

            // §6.2: Only overdue (< 0), today (= 0), and urgent (1..3 days) appear in alerts drawer
            if ($days > 3) {
                continue;
            }

            if ($days < 0) {
                $type = 'overdue';
            } elseif ($days === 0) {
                $type = 'today';
            } else {
                $type = 'urgent';
            }

            $alerts[] = [
                'id' => "alert_{$sub['id']}_{$type}",
                'subscriptionId' => (int) $sub['id'],
                'subName' => $sub['name'],
                'amount' => (float) $sub['amount'],
                'dueDate' => $sub['due_date'],
                'days' => $days,
                'type' => $type,
            ];
        }

        // Sort by days ascending (overdue first, then today, then upcoming)
        usort($alerts, fn(array $a, array $b): int => $a['days'] <=> $b['days']);

        return $alerts;
    }
}
