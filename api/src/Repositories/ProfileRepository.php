<?php
declare(strict_types=1);

namespace SubTracker\Repositories;

use PDO;

class ProfileRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    /**
     * Retrieve user profile formatted for API JSON response.
     *
     * @param int $userId
     * @return array{monthlySalary: float, salaryDay: ?int, currency: string, currencySymbol: string, systemNotificationsEnabled: bool, theme: string, timezone: string}|null
     */
    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                user_id,
                monthly_salary,
                salary_day,
                currency,
                currency_symbol,
                system_notifications_enabled,
                theme,
                timezone
            FROM user_profiles
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->formatProfile($row);
    }

    /**
     * Update profile fields and return the updated profile.
     *
     * @param int $userId
     * @param array<string, mixed> $data
     * @return array
     */
    public function update(int $userId, array $data): array
    {
        $fields = [];
        $params = [':user_id' => $userId];

        if (array_key_exists('monthlySalary', $data)) {
            $fields[] = 'monthly_salary = :monthly_salary';
            $params[':monthly_salary'] = number_format((float) $data['monthlySalary'], 2, '.', '');
        }

        if (array_key_exists('salaryDay', $data)) {
            $fields[] = 'salary_day = :salary_day';
            $params[':salary_day'] = $data['salaryDay'] !== null ? (int) $data['salaryDay'] : null;
        }

        if (array_key_exists('theme', $data)) {
            $fields[] = 'theme = :theme';
            $params[':theme'] = (string) $data['theme'];
        }

        if (array_key_exists('systemNotificationsEnabled', $data)) {
            $fields[] = 'system_notifications_enabled = :system_notifications_enabled';
            $val = $data['systemNotificationsEnabled'];
            $boolVal = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $val;
            $params[':system_notifications_enabled'] = $boolVal ? 1 : 0;
        }

        if (array_key_exists('timezone', $data)) {
            $fields[] = 'timezone = :timezone';
            $params[':timezone'] = (string) $data['timezone'];
        }

        if (!empty($fields)) {
            $fields[] = 'updated_at = UTC_TIMESTAMP()';
            $sql = "UPDATE user_profiles SET " . implode(', ', $fields) . " WHERE user_id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        $profile = $this->findByUserId($userId);
        if ($profile === null) {
            // In case profile row did not exist yet, create it
            $this->createDefault($userId, $data);
            $profile = $this->findByUserId($userId);
        }

        return $profile ?? [
            'monthlySalary' => 0.0,
            'salaryDay' => null,
            'currency' => 'ZAR',
            'currencySymbol' => 'R',
            'systemNotificationsEnabled' => false,
            'theme' => 'dark',
            'timezone' => 'Africa/Johannesburg',
        ];
    }

    /**
     * Create default profile row if missing.
     */
    public function createDefault(int $userId, array $initial = []): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO user_profiles (
                user_id, monthly_salary, salary_day, currency, currency_symbol,
                system_notifications_enabled, theme, timezone, created_at, updated_at
            ) VALUES (
                :user_id, :monthly_salary, :salary_day, 'ZAR', 'R',
                :system_notifications_enabled, :theme, :timezone, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
            ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP()
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':monthly_salary' => isset($initial['monthlySalary']) ? number_format((float) $initial['monthlySalary'], 2, '.', '') : '0.00',
            ':salary_day' => $initial['salaryDay'] ?? null,
            ':system_notifications_enabled' => !empty($initial['systemNotificationsEnabled']) ? 1 : 0,
            ':theme' => $initial['theme'] ?? 'dark',
            ':timezone' => $initial['timezone'] ?? 'Africa/Johannesburg',
        ]);
    }

    /**
     * Format a raw DB row to API JSON structure.
     *
     * @param array $row
     * @return array
     */
    private function formatProfile(array $row): array
    {
        return [
            'monthlySalary' => (float) $row['monthly_salary'],
            'salaryDay' => $row['salary_day'] !== null ? (int) $row['salary_day'] : null,
            'currency' => $row['currency'],
            'currencySymbol' => $row['currency_symbol'],
            'systemNotificationsEnabled' => (bool) $row['system_notifications_enabled'],
            'theme' => $row['theme'],
            'timezone' => $row['timezone'] ?? 'Africa/Johannesburg',
        ];
    }
}
