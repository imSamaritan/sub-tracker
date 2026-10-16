<?php
declare(strict_types=1);

namespace SubTracker\Services;

use PDO;

class BudgetService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AlertService $alertService
    ) {}

    /**
     * Compute dashboard budget summary for the user using vw_user_budget_summary.
     *
     * @param int $userId
     * @return array{monthlySalary: float, activeSubscriptions: int, totalCommittedMonthly: float, totalPaidThisCycle: float, totalDueThisCycle: float, remainingBalance: float, budgetUtilizationPercent: float, alertCount: int}
     */
    public function getDashboardSummary(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                user_id,
                monthly_salary,
                active_subscriptions,
                total_committed_monthly,
                total_paid_this_cycle,
                total_due_this_cycle,
                remaining_balance
            FROM vw_user_budget_summary
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            // Fallback if view has no matching row
            $profStmt = $this->pdo->prepare("SELECT monthly_salary FROM user_profiles WHERE user_id = :user_id LIMIT 1");
            $profStmt->execute([':user_id' => $userId]);
            $salary = (float) ($profStmt->fetchColumn() ?: 0.0);

            $row = [
                'monthly_salary' => $salary,
                'active_subscriptions' => 0,
                'total_committed_monthly' => 0.0,
                'total_paid_this_cycle' => 0.0,
                'total_due_this_cycle' => 0.0,
                'remaining_balance' => $salary,
            ];
        }

        $salary = (float) $row['monthly_salary'];
        $committed = (float) $row['total_committed_monthly'];

        // §6.1: salary <= 0 ? 0 : min(round(committed / salary * 100, 1), 100)
        $utilizationPercent = ($salary <= 0)
            ? 0.0
            : min(round(($committed / $salary) * 100, 1), 100.0);

        $alerts = $this->alertService->getAlertsForUser($userId);
        $alertCount = count($alerts);

        return [
            'monthlySalary' => $salary,
            'activeSubscriptions' => (int) $row['active_subscriptions'],
            'totalCommittedMonthly' => (float) $row['total_committed_monthly'],
            'totalPaidThisCycle' => (float) $row['total_paid_this_cycle'],
            'totalDueThisCycle' => (float) $row['total_due_this_cycle'],
            'remainingBalance' => (float) $row['remaining_balance'],
            'budgetUtilizationPercent' => $utilizationPercent,
            'alertCount' => $alertCount,
        ];
    }
}
