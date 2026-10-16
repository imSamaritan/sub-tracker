<?php
declare(strict_types=1);

namespace SubTracker\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SubTracker\Repositories\ProfileRepository;
use SubTracker\Repositories\SubscriptionRepository;

class StateController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProfileRepository $profileRepository,
        private readonly SubscriptionRepository $subscriptionRepository
    ) {}

    /**
     * GET /state (§8.2 bootstrap)
     */
    public function state(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('userId');

        // 1. Profile
        $profile = $this->profileRepository->findByUserId($userId);

        // 2. Subscriptions (live, default sort)
        $subscriptions = $this->subscriptionRepository->listByUser($userId);

        // 3. User local today
        $userToday = $this->subscriptionRepository->getUserToday($userId);

        // 4. Payment history (latest first, max 500 rows)
        $pmtStmt = $this->pdo->prepare("
            SELECT id, subscription_id, subscription_name, amount, cycle_due_date, paid_date, created_at
            FROM payments
            WHERE user_id = :user_id
            ORDER BY paid_date DESC, id DESC
            LIMIT 500
        ");
        $pmtStmt->execute([':user_id' => $userId]);
        $payments = $pmtStmt->fetchAll(PDO::FETCH_ASSOC);

        $paymentHistory = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'subscriptionId' => $row['subscription_id'] !== null ? (int) $row['subscription_id'] : null,
                'name' => (string) $row['subscription_name'],
                'amount' => (float) $row['amount'],
                'cycleDueDate' => (string) $row['cycle_due_date'],
                'paidDate' => (string) $row['paid_date'],
                'createdAt' => (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            ];
        }, $payments);

        $payload = [
            'data' => [
                'profile' => $profile,
                'subscriptions' => $subscriptions,
                'paymentHistory' => $paymentHistory,
                'today' => $userToday,
            ]
        ];

        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
