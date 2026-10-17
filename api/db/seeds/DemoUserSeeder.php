<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class DemoUserSeeder extends AbstractSeed
{
    public function getDependencies(): array
    {
        return ['CategorySeeder'];
    }

    public function run(): void
    {
        $envName = method_exists($this, 'getEnvironment') ? (string) $this->getEnvironment() : '';
        $appEnv = (string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '');

        if ($envName === 'production' || $appEnv === 'production') {
            if ($this->getOutput()) {
                $this->getOutput()->writeln('<comment>Skipping DemoUserSeeder in production environment (§5 / §15.3).</comment>');
            }
            return;
        }

        $conn = $this->getAdapter()->getConnection();

        // 1. Check or insert demo user
        $email = 'demo@subtracker.local';
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        $passwordHash = password_hash('Demo@1234', PASSWORD_BCRYPT, ['cost' => 12]);
        $now = gmdate('Y-m-d H:i:s');
        $today = date('Y-m-d');

        if ($existing) {
            $userId = (int) $existing['id'];
            $updateUser = $conn->prepare('UPDATE users SET password_hash = :hash, email_verified_at = :verified WHERE id = :id');
            $updateUser->execute(['hash' => $passwordHash, 'verified' => $now, 'id' => $userId]);

            // Clear old payments & subscriptions for clean reset
            $conn->prepare('DELETE FROM payments WHERE user_id = :uid')->execute(['uid' => $userId]);
            $conn->prepare('DELETE FROM subscriptions WHERE user_id = :uid')->execute(['uid' => $userId]);
        } else {
            $insertUser = $conn->prepare(
                'INSERT INTO users (email, password_hash, display_name, email_verified_at, created_at, updated_at)
                 VALUES (:email, :hash, :name, :verified, :now, :now)'
            );
            $insertUser->execute([
                'email' => $email,
                'hash' => $passwordHash,
                'name' => 'Demo User',
                'verified' => $now,
                'now' => $now,
            ]);
            $userId = (int) $conn->lastInsertId();
        }

        // 2. User Profile
        $profileStmt = $conn->prepare(
            'INSERT INTO user_profiles (user_id, monthly_salary, salary_day, currency, currency_symbol, theme, system_notifications_enabled, timezone)
             VALUES (:uid, 28500.00, 25, "ZAR", "R", "dark", 0, "Africa/Johannesburg")
             ON DUPLICATE KEY UPDATE
                monthly_salary = VALUES(monthly_salary),
                salary_day = VALUES(salary_day),
                currency = VALUES(currency),
                currency_symbol = VALUES(currency_symbol),
                theme = VALUES(theme),
                system_notifications_enabled = VALUES(system_notifications_enabled),
                timezone = VALUES(timezone)'
        );
        $profileStmt->execute(['uid' => $userId]);

        // 3. Category lookup map
        $catStmt = $conn->query('SELECT id, code FROM categories');
        $catMap = [];
        while ($row = $catStmt->fetch(\PDO::FETCH_ASSOC)) {
            $catMap[$row['code']] = (int) $row['id'];
        }

        // 4. Demo Subscriptions
        $demoSubs = [
            [
                'name' => 'Showmax Entertainment',
                'category' => 'Entertainment',
                'amount' => 99.00,
                'billing_cycle' => 'monthly',
                'due_date' => date('Y-m-d', strtotime('+2 days')),
                'icon' => 'fa-tv',
                'color' => '#0084f7',
                'is_paid_this_cycle' => 0,
                'notes' => 'Standard plan',
            ],
            [
                'name' => 'DSTV Stream Compact',
                'category' => 'Entertainment',
                'amount' => 799.00,
                'billing_cycle' => 'monthly',
                'due_date' => date('Y-m-d', strtotime('+5 days')),
                'icon' => 'fa-tv',
                'color' => '#0084f7',
                'is_paid_this_cycle' => 0,
                'notes' => 'Compact stream',
            ],
            [
                'name' => 'Vodacom Home Fibre',
                'category' => 'Internet',
                'amount' => 849.00,
                'billing_cycle' => 'monthly',
                'due_date' => date('Y-m-d', strtotime('+18 days')),
                'icon' => 'fa-wifi',
                'color' => '#0ea5e9',
                'is_paid_this_cycle' => 0,
                'notes' => '50Mbps uncapped',
            ],
            [
                'name' => 'Spotify Premium',
                'category' => 'Music',
                'amount' => 64.99,
                'billing_cycle' => 'monthly',
                'due_date' => date('Y-m-d', strtotime('-1 days')),
                'icon' => 'fa-music',
                'color' => '#1db954',
                'is_paid_this_cycle' => 1,
                'notes' => 'Individual plan',
            ],
            [
                'name' => 'Virgin Active Classic',
                'category' => 'Fitness',
                'amount' => 450.00,
                'billing_cycle' => 'monthly',
                'due_date' => date('Y-m-d', strtotime('+1 days')),
                'icon' => 'fa-dumbbell',
                'color' => '#ef4444',
                'is_paid_this_cycle' => 0,
                'notes' => 'Off-peak access',
            ],
        ];

        $subInsert = $conn->prepare(
            'INSERT INTO subscriptions (user_id, category_id, name, amount, billing_cycle, due_date, billing_day, icon, color, notes, is_paid_this_cycle, status, created_at, updated_at)
             VALUES (:uid, :cat_id, :name, :amount, :cycle, :due_date, :billing_day, :icon, :color, :notes, :is_paid, "active", :now, :now)'
        );

        $spotifySubId = null;
        $spotifyDueDate = null;

        foreach ($demoSubs as $sub) {
            $catId = $catMap[$sub['category']] ?? $catMap['Other'];
            $billingDay = (int) date('j', strtotime($sub['due_date']));

            $subInsert->execute([
                'uid' => $userId,
                'cat_id' => $catId,
                'name' => $sub['name'],
                'amount' => $sub['amount'],
                'cycle' => $sub['billing_cycle'],
                'due_date' => $sub['due_date'],
                'billing_day' => $billingDay,
                'icon' => $sub['icon'],
                'color' => $sub['color'],
                'notes' => $sub['notes'],
                'is_paid' => $sub['is_paid_this_cycle'],
                'now' => $now,
            ]);

            $subId = (int) $conn->lastInsertId();
            if ($sub['name'] === 'Spotify Premium') {
                $spotifySubId = $subId;
                $spotifyDueDate = $sub['due_date'];
            }
        }

        // 5. Payment ledger entry for Spotify Premium
        if ($spotifySubId !== null) {
            $payInsert = $conn->prepare(
                'INSERT INTO payments (user_id, subscription_id, subscription_name, amount, cycle_due_date, paid_date, created_at)
                 VALUES (:uid, :sub_id, "Spotify Premium", 64.99, :cycle_due, :paid_date, :now)'
            );
            $payInsert->execute([
                'uid' => $userId,
                'sub_id' => $spotifySubId,
                'cycle_due' => $spotifyDueDate,
                'paid_date' => $today,
                'now' => $now,
            ]);
        }
    }
}
