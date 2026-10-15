<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateBudgetSummaryView extends AbstractMigration
{
    public function up(): void
    {
        $sql = <<<SQL
CREATE OR REPLACE VIEW vw_user_budget_summary AS
SELECT 
    u.id AS user_id,
    p.monthly_salary,
    COUNT(s.id) AS active_subscriptions,
    COALESCE(SUM(
        CASE 
            WHEN s.billing_cycle = 'weekly' THEN ROUND(s.amount * 4.333, 2)
            WHEN s.billing_cycle = 'yearly' THEN ROUND(s.amount / 12.0, 2)
            ELSE s.amount
        END
    ), 0.00) AS total_committed_monthly,
    COALESCE(SUM(
        CASE WHEN s.is_paid_this_cycle = 1 THEN s.amount ELSE 0.00 END
    ), 0.00) AS total_paid_this_cycle,
    COALESCE(SUM(
        CASE WHEN s.is_paid_this_cycle = 0 THEN s.amount ELSE 0.00 END
    ), 0.00) AS total_due_this_cycle,
    ROUND(p.monthly_salary - COALESCE(SUM(
        CASE 
            WHEN s.billing_cycle = 'weekly' THEN ROUND(s.amount * 4.333, 2)
            WHEN s.billing_cycle = 'yearly' THEN ROUND(s.amount / 12.0, 2)
            ELSE s.amount
        END
    ), 0.00), 2) AS remaining_balance
FROM users u
JOIN user_profiles p ON p.user_id = u.id
LEFT JOIN subscriptions s ON s.user_id = u.id 
    AND s.deleted_at IS NULL 
    AND s.status = 'active'
GROUP BY u.id, p.monthly_salary;
SQL;

        $this->execute($sql);
    }

    public function down(): void
    {
        $this->execute('DROP VIEW IF EXISTS vw_user_budget_summary;');
    }
}
