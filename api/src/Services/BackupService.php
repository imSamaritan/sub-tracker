<?php
declare(strict_types=1);

namespace SubTracker\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use SubTracker\Repositories\CategoryRepository;
use SubTracker\Repositories\PaymentRepository;
use SubTracker\Repositories\ProfileRepository;
use SubTracker\Repositories\SubscriptionRepository;

class BackupService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProfileRepository $profileRepository,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly RateLimiter $rateLimiter
    ) {}

    /**
     * Export user data matching frontend export format (§8.8).
     *
     * @param int $userId
     * @return array{profile: array<string, mixed>, subscriptions: array<int, mixed>, paymentHistory: array<int, mixed>}
     */
    public function export(int $userId): array
    {
        $profile = $this->profileRepository->findByUserId($userId);
        $subscriptions = $this->subscriptionRepository->listByUser($userId);
        $payments = $this->paymentRepository->listByUser($userId, ['perPage' => 10000]);

        return [
            'profile' => $profile,
            'subscriptions' => $subscriptions,
            'paymentHistory' => $payments['data'] ?? [],
        ];
    }

    /**
     * Restore and replace user data from backup (§8.8).
     *
     * @param int $userId
     * @param array<string, mixed> $data
     * @return array{success: bool, errors?: array<string, string>, data?: array{subscriptions: int, payments: int}}
     */
    public function restore(int $userId, array $data): array
    {
        $errors = [];

        // Validate top-level keys
        if (!isset($data['profile']) || !is_array($data['profile'])) {
            $errors['profile'] = 'Backup payload must contain a profile object.';
        }
        if (!isset($data['subscriptions']) || !is_array($data['subscriptions'])) {
            $errors['subscriptions'] = 'Backup payload must contain a subscriptions array.';
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $subscriptions = (array) $data['subscriptions'];
        $payments = isset($data['paymentHistory']) && is_array($data['paymentHistory']) ? $data['paymentHistory'] : [];

        if (count($subscriptions) > 1000) {
            return ['success' => false, 'errors' => ['subscriptions' => 'Subscriptions count exceeds 1,000 row limit.']];
        }
        if (count($payments) > 10000) {
            return ['success' => false, 'errors' => ['paymentHistory' => 'Payment history exceeds 10,000 row limit.']];
        }

        // Validate each subscription with §6.5 rules
        foreach ($subscriptions as $idx => $sub) {
            if (!is_array($sub)) {
                $errors["subscriptions[$idx]"] = 'Subscription item must be an object.';
                continue;
            }

            // Name
            $name = trim((string) ($sub['name'] ?? ''));
            if ($name === '') {
                $errors["subscriptions[$idx].name"] = 'Please enter a subscription name.';
            } elseif (mb_strlen($name) > 100) {
                $errors["subscriptions[$idx].name"] = 'Subscription name must not exceed 100 characters.';
            }

            // Amount
            $rawAmount = $sub['amount'] ?? null;
            if ($rawAmount === null || !is_numeric($rawAmount) || (float) $rawAmount <= 0) {
                $errors["subscriptions[$idx].amount"] = 'Please enter a valid amount in Rands.';
            } elseif ((float) $rawAmount > 99999999.99) {
                $errors["subscriptions[$idx].amount"] = 'Amount exceeds maximum limit.';
            }

            // Billing Cycle
            $cycle = (string) ($sub['billingCycle'] ?? 'monthly');
            if (!in_array($cycle, ['weekly', 'monthly', 'yearly'], true)) {
                $errors["subscriptions[$idx].billingCycle"] = 'Billing cycle must be weekly, monthly, or yearly.';
            }

            // Due Date
            $due = (string) ($sub['dueDate'] ?? '');
            $parsedDue = DateTimeImmutable::createFromFormat('Y-m-d', $due);
            if (!$parsedDue || $parsedDue->format('Y-m-d') !== $due) {
                $errors["subscriptions[$idx].dueDate"] = 'Please enter a valid due date (YYYY-MM-DD).';
            }

            // Start Date (optional)
            if (!empty($sub['startDate'])) {
                $start = (string) $sub['startDate'];
                $parsedStart = DateTimeImmutable::createFromFormat('Y-m-d', $start);
                if (!$parsedStart || $parsedStart->format('Y-m-d') !== $start) {
                    $errors["subscriptions[$idx].startDate"] = 'Invalid start date format (YYYY-MM-DD).';
                }
            }

            // Notes (optional)
            if (!empty($sub['notes']) && mb_strlen((string) $sub['notes']) > 255) {
                $errors["subscriptions[$idx].notes"] = 'Notes must not exceed 255 characters.';
            }

            // Status (optional)
            if (isset($sub['status']) && !in_array($sub['status'], ['active', 'paused'], true)) {
                $errors["subscriptions[$idx].status"] = 'Status must be active or paused.';
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Execute atomic replacement in one transaction
        $this->pdo->beginTransaction();
        try {
            // 1. Update user profile
            $profileData = $data['profile'];
            $profileFields = [];
            $profileBindings = [':uid' => $userId];

            if (array_key_exists('monthlySalary', $profileData) && is_numeric($profileData['monthlySalary'])) {
                $profileFields[] = "monthly_salary = :salary";
                $profileBindings[':salary'] = max(0.0, (float) $profileData['monthlySalary']);
            }
            if (array_key_exists('salaryDay', $profileData)) {
                $sDay = $profileData['salaryDay'] !== null ? max(1, min(31, (int) $profileData['salaryDay'])) : null;
                $profileFields[] = "salary_day = :sday";
                $profileBindings[':sday'] = $sDay;
            }
            if (!empty($profileData['theme']) && in_array($profileData['theme'], ['dark', 'light'], true)) {
                $profileFields[] = "theme = :theme";
                $profileBindings[':theme'] = $profileData['theme'];
            }
            if (array_key_exists('systemNotificationsEnabled', $profileData)) {
                $profileFields[] = "system_notifications_enabled = :notif";
                $profileBindings[':notif'] = filter_var($profileData['systemNotificationsEnabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
            if (!empty($profileData['timezone']) && in_array($profileData['timezone'], DateTimeZone::listIdentifiers(), true)) {
                $profileFields[] = "timezone = :tz";
                $profileBindings[':tz'] = $profileData['timezone'];
            }

            if (!empty($profileFields)) {
                $profileFields[] = "updated_at = UTC_TIMESTAMP()";
                $sqlProf = "UPDATE user_profiles SET " . implode(', ', $profileFields) . " WHERE user_id = :uid";
                $stmtProf = $this->pdo->prepare($sqlProf);
                $stmtProf->execute($profileBindings);
            }

            // 2. Hard delete existing payments and subscriptions for this user
            $delPmt = $this->pdo->prepare("DELETE FROM payments WHERE user_id = :uid");
            $delPmt->execute([':uid' => $userId]);

            $delSub = $this->pdo->prepare("DELETE FROM subscriptions WHERE user_id = :uid");
            $delSub->execute([':uid' => $userId]);

            // 3. Insert subscriptions and construct legacy ID mapping table
            $idMap = [];
            $insSub = $this->pdo->prepare("
                INSERT INTO subscriptions (
                    user_id, category_id, name, amount, billing_cycle,
                    due_date, billing_day, start_date, icon, color, notes,
                    is_paid_this_cycle, status, created_at, updated_at
                ) VALUES (
                    :uid, :cat_id, :name, :amount, :cycle,
                    :due, :billing_day, :start, :icon, :color, :notes,
                    :is_paid, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                )
            ");

            foreach ($subscriptions as $sub) {
                $catCode = (string) ($sub['category'] ?? 'Other');
                $catId = $this->subscriptionRepository->findCategoryIdByCode($catCode)
                    ?? $this->subscriptionRepository->findCategoryIdByCode('Other')
                    ?? 14;

                $dueDate = (string) $sub['dueDate'];
                $billingDay = (int) (new DateTimeImmutable($dueDate))->format('j');
                $isPaid = filter_var($sub['isPaidThisCycle'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

                $insSub->execute([
                    ':uid' => $userId,
                    ':cat_id' => $catId,
                    ':name' => trim((string) $sub['name']),
                    ':amount' => (float) $sub['amount'],
                    ':cycle' => $sub['billingCycle'] ?? 'monthly',
                    ':due' => $dueDate,
                    ':billing_day' => $billingDay,
                    ':start' => !empty($sub['startDate']) ? (string) $sub['startDate'] : null,
                    ':icon' => !empty($sub['icon']) ? (string) $sub['icon'] : 'fa-receipt',
                    ':color' => !empty($sub['color']) ? (string) $sub['color'] : '#6366f1',
                    ':notes' => isset($sub['notes']) && $sub['notes'] !== '' ? (string) $sub['notes'] : null,
                    ':is_paid' => $isPaid,
                    ':status' => (isset($sub['status']) && in_array($sub['status'], ['active', 'paused'], true)) ? $sub['status'] : 'active',
                ]);

                $newId = (int) $this->pdo->lastInsertId();

                if (isset($sub['id'])) {
                    $idMap[(string) $sub['id']] = $newId;
                }
            }

            // 4. Insert payments history
            $userToday = $this->subscriptionRepository->getUserToday($userId);
            $insPmt = $this->pdo->prepare("
                INSERT INTO payments (
                    user_id, subscription_id, subscription_name, amount,
                    cycle_due_date, paid_date, created_at
                ) VALUES (
                    :uid, :sid, :name, :amount,
                    :cycle_due, :paid_date, UTC_TIMESTAMP()
                )
            ");

            foreach ($payments as $pmt) {
                if (!is_array($pmt)) {
                    continue;
                }

                $oldSubId = isset($pmt['subscriptionId']) ? (string) $pmt['subscriptionId'] : null;
                $newSubId = ($oldSubId !== null && isset($idMap[$oldSubId])) ? $idMap[$oldSubId] : null;

                $paidDate = !empty($pmt['paidDate']) ? (string) $pmt['paidDate'] : $userToday;
                $cycleDue = !empty($pmt['cycleDueDate']) ? (string) $pmt['cycleDueDate'] : $paidDate;

                $subName = (string) ($pmt['name'] ?? $pmt['subscriptionName'] ?? 'Subscription Payment');
                $amount = (float) ($pmt['amount'] ?? 0.0);

                $insPmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                $insPmt->bindValue(':sid', $newSubId, $newSubId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $insPmt->bindValue(':name', $subName, PDO::PARAM_STR);
                $insPmt->bindValue(':amount', $amount);
                $insPmt->bindValue(':cycle_due', $cycleDue, PDO::PARAM_STR);
                $insPmt->bindValue(':paid_date', $paidDate, PDO::PARAM_STR);
                $insPmt->execute();
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'data' => [
                    'subscriptions' => count($subscriptions),
                    'payments' => count($payments),
                ],
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reset user to South African demo dataset (§8.10).
     *
     * @param int $userId
     * @return array{success: bool, error?: string, retryAfter?: int, data?: array{message: string, subscriptions: int, payments: int}}
     */
    public function resetDemo(int $userId): array
    {
        // Rate-limit: max 3 resets per hour per user
        $rateCheck = $this->rateLimiter->attempt("demo-reset:user:$userId", 3, 3600);
        if (!$rateCheck['allowed']) {
            return [
                'success' => false,
                'error' => 'Too many demo resets. Please try again later.',
                'retryAfter' => $rateCheck['retryAfter'],
            ];
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Delete existing payments and subscriptions
            $this->pdo->prepare("DELETE FROM payments WHERE user_id = :uid")->execute([':uid' => $userId]);
            $this->pdo->prepare("DELETE FROM subscriptions WHERE user_id = :uid")->execute([':uid' => $userId]);

            // 2. Reset user_profiles to standard demo defaults
            $stmtProf = $this->pdo->prepare("
                UPDATE user_profiles
                SET monthly_salary = 28500.00,
                    salary_day = 25,
                    currency = 'ZAR',
                    currency_symbol = 'R',
                    theme = 'dark',
                    updated_at = UTC_TIMESTAMP()
                WHERE user_id = :uid
            ");
            $stmtProf->execute([':uid' => $userId]);

            // 3. User local today and dates
            $tz = $this->subscriptionRepository->getUserTimezone($userId);
            $userTodayDt = new DateTimeImmutable('now', $tz);
            $userToday = $userTodayDt->format('Y-m-d');

            // 4. Five demo subscriptions (§8.10)
            $demoSubs = [
                [
                    'name' => 'Showmax Entertainment',
                    'category' => 'Entertainment',
                    'amount' => 99.00,
                    'billing_cycle' => 'monthly',
                    'due_date' => $userTodayDt->modify('+2 days')->format('Y-m-d'),
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
                    'due_date' => $userTodayDt->modify('+5 days')->format('Y-m-d'),
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
                    'due_date' => $userTodayDt->modify('+18 days')->format('Y-m-d'),
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
                    'due_date' => $userTodayDt->modify('-1 days')->format('Y-m-d'),
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
                    'due_date' => $userTodayDt->modify('+1 days')->format('Y-m-d'),
                    'icon' => 'fa-dumbbell',
                    'color' => '#ef4444',
                    'is_paid_this_cycle' => 0,
                    'notes' => 'Off-peak access',
                ],
            ];

            $insSub = $this->pdo->prepare("
                INSERT INTO subscriptions (
                    user_id, category_id, name, amount, billing_cycle,
                    due_date, billing_day, icon, color, notes,
                    is_paid_this_cycle, status, created_at, updated_at
                ) VALUES (
                    :uid, :cat_id, :name, :amount, :cycle,
                    :due, :billing_day, :icon, :color, :notes,
                    :is_paid, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()
                )
            ");

            $spotifySubId = null;
            $spotifyDueDate = null;

            foreach ($demoSubs as $sub) {
                $catId = $this->subscriptionRepository->findCategoryIdByCode($sub['category'])
                    ?? $this->subscriptionRepository->findCategoryIdByCode('Other')
                    ?? 14;

                $billingDay = (int) (new DateTimeImmutable($sub['due_date']))->format('j');

                $insSub->execute([
                    ':uid' => $userId,
                    ':cat_id' => $catId,
                    ':name' => $sub['name'],
                    ':amount' => $sub['amount'],
                    ':cycle' => $sub['billing_cycle'],
                    ':due' => $sub['due_date'],
                    ':billing_day' => $billingDay,
                    ':icon' => $sub['icon'],
                    ':color' => $sub['color'],
                    ':notes' => $sub['notes'],
                    ':is_paid' => $sub['is_paid_this_cycle'],
                ]);

                $subId = (int) $this->pdo->lastInsertId();
                if ($sub['name'] === 'Spotify Premium') {
                    $spotifySubId = $subId;
                    $spotifyDueDate = $sub['due_date'];
                }
            }

            // 5. Payment ledger entry for Spotify Premium
            if ($spotifySubId !== null) {
                $insPmt = $this->pdo->prepare("
                    INSERT INTO payments (
                        user_id, subscription_id, subscription_name, amount,
                        cycle_due_date, paid_date, created_at
                    ) VALUES (
                        :uid, :sid, 'Spotify Premium', 64.99,
                        :cycle_due, :paid_date, UTC_TIMESTAMP()
                    )
                ");
                $insPmt->execute([
                    ':uid' => $userId,
                    ':sid' => $spotifySubId,
                    ':cycle_due' => $spotifyDueDate,
                    ':paid_date' => $userToday,
                ]);
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'data' => [
                    'message' => 'Account reset to default South African demo subscriptions.',
                    'subscriptions' => 5,
                    'payments' => 1,
                ],
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
