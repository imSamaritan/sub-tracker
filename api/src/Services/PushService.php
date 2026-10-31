<?php
declare(strict_types=1);

namespace SubTracker\Services;

use Base64Url\Base64Url;
use DateTimeImmutable;
use DateTimeZone;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use PDO;
use Psr\Http\Client\ClientInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use SubTracker\Repositories\PushSubscriptionRepository;
use SubTracker\Support\CurlHttpClient;

class PushService
{
    /** @var array<int, array{userId: int, subscriptionId: int, endpoint: string, payload: array<string, mixed>, statusCode: int, success: bool, expired: bool}> */
    private static array $testSpool = [];

    /**
     * Delivery options for bill reminders.
     *
     * - Urgency "high": push services (FCM on Android in particular) hold "normal" messages
     *   while the device is idle / in Doze and only hand them over when the user wakes the
     *   phone or opens the app. "high" wakes the device and the service worker immediately,
     *   so the notification is displayed even when the PWA is closed.
     * - TTL 3 days: if the phone is offline, the push service stores the message and delivers
     *   it as soon as the device reconnects (a "due in 3 days" alert is stale after that).
     */
    private const REMINDER_PUSH_OPTIONS = [
        'TTL' => 259200,
        'urgency' => 'high',
    ];

    private ClientInterface $httpClient;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PushSubscriptionRepository $pushRepo,
        private readonly RateLimiter $rateLimiter,
        private readonly array $settings,
        ?ClientInterface $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * Get the configured VAPID public key (§8.9).
     */
    public function getPublicKey(): string
    {
        return (string) ($this->settings['vapid']['public_key'] ?? '');
    }

    /**
     * Register or update a Web Push subscription for a user's device (§8.9).
     *
     * @param int $userId
     * @param array<string, mixed> $data
     * @param string|null $userAgent
     * @return array{id: int, endpoint: string, contentEncoding: string, systemNotificationsEnabled: bool}
     */
    public function subscribe(int $userId, array $data, ?string $userAgent = null): array
    {
        $endpoint = trim((string) $data['endpoint']);
        $keys = isset($data['keys']) && is_array($data['keys']) ? $data['keys'] : [];
        $p256dh = isset($keys['p256dh']) ? trim((string) $keys['p256dh']) : trim((string) ($data['p256dh'] ?? ''));
        $auth = isset($keys['auth']) ? trim((string) $keys['auth']) : trim((string) ($data['auth'] ?? ''));
        $contentEncoding = !empty($data['contentEncoding']) ? trim((string) $data['contentEncoding']) : 'aes128gcm';

        return $this->pushRepo->upsert($userId, $endpoint, $p256dh, $auth, $contentEncoding, $userAgent);
    }

    /**
     * Replace a rotated/expired browser push subscription with its new one.
     *
     * Called by the service worker's `pushsubscriptionchange` handler, which runs while the app
     * is closed and therefore has no bearer token. Knowledge of the previous (secret, unguessable)
     * endpoint URL is used as proof of ownership of the device row.
     *
     * @param string $oldEndpoint
     * @param array<string, mixed> $data New subscription (endpoint, keys.p256dh, keys.auth, contentEncoding)
     * @param string|null $userAgent
     * @return array{id: int, endpoint: string, contentEncoding: string, systemNotificationsEnabled: bool}|null Null when the old endpoint is unknown
     */
    public function renew(string $oldEndpoint, array $data, ?string $userAgent = null): ?array
    {
        $oldEndpoint = trim($oldEndpoint);
        $existing = $this->pushRepo->findByEndpoint($oldEndpoint);
        if ($existing === null) {
            return null;
        }

        $userId = (int) $existing['user_id'];
        $result = $this->subscribe($userId, $data, $userAgent);

        if (trim((string) $data['endpoint']) !== $oldEndpoint) {
            $this->pushRepo->deleteByUserAndEndpoint($userId, $oldEndpoint);
        }

        return $result;
    }

    /**
     * Unregister a Web Push subscription for a user's device (§8.9).
     */
    public function unsubscribe(int $userId, string $endpoint): int
    {
        return $this->pushRepo->deleteByUserAndEndpoint($userId, trim($endpoint));
    }

    /**
     * Send a test notification to all of the user's registered devices (§8.9).
     * Rate-limited to 5 per hour per user.
     *
     * @param int $userId
     * @return array{allowed: bool, retryAfter?: int, result?: array<string, mixed>}
     */
    public function sendTestNotification(int $userId): array
    {
        $rateCheck = $this->rateLimiter->attempt("push-test:user:{$userId}", 5, 3600);
        if (!$rateCheck['allowed']) {
            return [
                'allowed' => false,
                'retryAfter' => $rateCheck['retryAfter'],
            ];
        }

        $payload = [
            'title' => 'SubTracker Test Alert',
            'body' => 'Your South African subscription alerts are active and working.',
            'url' => './index.html#calendar',
            'tag' => 'subtracker-alert',
        ];

        $delivery = $this->sendToUserDevices($userId, $payload, self::REMINDER_PUSH_OPTIONS);

        return [
            'allowed' => true,
            'result' => [
                'message' => 'SubTracker Test Alert dispatched to registered devices.',
                'devicesTargeted' => $delivery['targeted'],
                'sent' => $delivery['sent'],
                'failed' => $delivery['failed'],
            ],
        ];
    }

    /**
     * Encrypt and send a Web Push payload to all devices belonging to a user (§9 steps 5 & 6).
     *
     * @param int $userId
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options Per-message WebPush options (TTL, urgency, topic)
     * @return array{targeted: int, sent: int, failed: int, expiredDeleted: int}
     */
    public function sendToUserDevices(int $userId, array $payload, array $options = []): array
    {
        $devices = $this->pushRepo->findByUserId($userId);
        if (empty($devices)) {
            return [
                'targeted' => 0,
                'sent' => 0,
                'failed' => 0,
                'expiredDeleted' => 0,
            ];
        }

        $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $webPush = $this->createWebPushInstance();
        if ($webPush === null) {
            error_log('[SubTracker Push] WebPush/VAPID is unavailable - real push services will reject unsigned requests. Check VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY.');
        }
        $requestFactory = new RequestFactory();
        $streamFactory = new StreamFactory();

        $targeted = count($devices);
        $sent = 0;
        $failed = 0;
        $expiredDeleted = 0;

        foreach ($devices as $device) {
            $subId = (int) $device['id'];
            $endpoint = (string) $device['endpoint'];
            $p256dh = (string) $device['p256dh_key'];
            $authKey = (string) $device['auth_key'];
            $encoding = !empty($device['content_encoding']) ? (string) $device['content_encoding'] : 'aes128gcm';

            $statusCode = 0;
            $isSuccess = false;
            $isExpired = false;

            try {
                $decodedKey = '';
                try {
                    $decodedKey = Base64Url::decode($p256dh);
                } catch (\Throwable) {
                    $decodedKey = '';
                }

                // If p256dh is a standard 65-byte uncompressed P-256 public key, use full RFC 8291 WebPush encryption
                if ($webPush !== null && strlen($decodedKey) === 65 && $decodedKey[0] === "\x04") {
                    $subscription = Subscription::create([
                        'endpoint' => $endpoint,
                        'publicKey' => $p256dh,
                        'authToken' => $authKey,
                        'contentEncoding' => $encoding,
                    ]);

                    $report = $webPush->sendOneNotification($subscription, $payloadJson, $options);
                    $response = $report->getResponse();
                    $statusCode = $response !== null ? $response->getStatusCode() : ($report->isSuccess() ? 201 : 500);
                    $isSuccess = $report->isSuccess();
                    $isExpired = $report->isSubscriptionExpired() || in_array($statusCode, [404, 410], true);

                    if (!$isSuccess) {
                        // Surface the push service's reason (e.g. 403 VAPID key mismatch, 400 bad payload) in the PHP error log
                        $responseBody = $response !== null ? substr((string) $response->getBody(), 0, 300) : '';
                        error_log(sprintf(
                            '[SubTracker Push] Delivery to subscription %d (%s) failed: HTTP %d - %s %s',
                            $subId,
                            (string) parse_url($endpoint, PHP_URL_HOST),
                            $statusCode,
                            $report->getReason(),
                            $responseBody
                        ));
                    }
                } else {
                    // Fallback for synthetic/short test keys so HTTP status handling can still be exercised
                    $req = $requestFactory->createRequest('POST', $endpoint)
                        ->withHeader('Content-Type', 'application/octet-stream')
                        ->withHeader('Content-Encoding', $encoding)
                        ->withHeader('TTL', (string) ($options['TTL'] ?? 2419200))
                        ->withBody($streamFactory->createStream($payloadJson));

                    $res = $this->httpClient->sendRequest($req);
                    $statusCode = $res->getStatusCode();
                    $isSuccess = $statusCode >= 200 && $statusCode < 300;
                    $isExpired = in_array($statusCode, [404, 410], true);
                }
            } catch (\Throwable $e) {
                error_log("WebPush delivery error for subscription {$subId}: " . $e->getMessage());
                $statusCode = 500;
                $isSuccess = false;
                $isExpired = false;
            }

            // Handle outcome per §9 step 6
            if ($isSuccess) {
                $this->pushRepo->markSuccess($subId);
                $sent++;
            } elseif ($isExpired) {
                $this->pushRepo->deleteById($subId);
                $failed++;
                $expiredDeleted++;
            } else {
                $deletedAfterMaxFailures = $this->pushRepo->recordFailure($subId, 10);
                $failed++;
                if ($deletedAfterMaxFailures) {
                    $expiredDeleted++;
                }
            }

            self::$testSpool[] = [
                'userId' => $userId,
                'subscriptionId' => $subId,
                'endpoint' => $endpoint,
                'payload' => $payload,
                'statusCode' => $statusCode,
                'success' => $isSuccess,
                'expired' => $isExpired,
            ];
        }

        return [
            'targeted' => $targeted,
            'sent' => $sent,
            'failed' => $failed,
            'expiredDeleted' => $expiredDeleted,
        ];
    }

    /**
     * Execute the scheduled reminder digest workflow (§9).
     * Idempotent via INSERT IGNORE INTO notification_log.
     *
     * @param DateTimeImmutable|null $referenceUtcNow Optional reference UTC time for deterministic testing
     * @param bool $ignoreHourCheck If true, bypasses the local hour >= 08:00 guard
     * @return array{usersChecked: int, usersSkippedBefore8am: int, digestsSent: int, notificationsLogged: int, devicesSent: int, devicesFailed: int, expiredDeleted: int, digestsRetryPending: int}
     */
    public function processScheduledReminders(?DateTimeImmutable $referenceUtcNow = null, bool $ignoreHourCheck = false): array
    {
        $utcNow = $referenceUtcNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // 1. Select verified users with system_notifications_enabled = 1 and at least one push_subscriptions row
        $userStmt = $this->pdo->query("
            SELECT DISTINCT u.id AS user_id, COALESCE(up.timezone, 'Africa/Johannesburg') AS timezone
            FROM users u
            INNER JOIN user_profiles up ON up.user_id = u.id
            INNER JOIN push_subscriptions ps ON ps.user_id = u.id
            WHERE u.email_verified_at IS NOT NULL
              AND up.system_notifications_enabled = 1
            ORDER BY u.id ASC
        ");
        $users = $userStmt ? $userStmt->fetchAll() : [];

        $usersChecked = count($users);
        $usersSkippedBefore8am = 0;
        $digestsSent = 0;
        $digestsRetryPending = 0;
        $notificationsLogged = 0;
        $devicesSent = 0;
        $devicesFailed = 0;
        $expiredDeleted = 0;

        $subStmt = $this->pdo->prepare("
            SELECT id, name, amount, due_date
            FROM subscriptions
            WHERE user_id = :user_id
              AND deleted_at IS NULL
              AND status = 'active'
              AND is_paid_this_cycle = 0
              AND due_date <= :max_due_date
            ORDER BY due_date ASC, id ASC
        ");

        $logStmt = $this->pdo->prepare("
            INSERT IGNORE INTO notification_log (
                user_id, subscription_id, cycle_due_date, alert_type, channel, sent_at
            ) VALUES (
                :user_id, :subscription_id, :cycle_due_date, :alert_type, 'push', UTC_TIMESTAMP()
            )
        ");

        // Used to release the dedup claim when no device received the digest, so the next cron run retries
        $unlogStmt = $this->pdo->prepare("
            DELETE FROM notification_log
            WHERE user_id = :user_id
              AND subscription_id = :subscription_id
              AND cycle_due_date = :cycle_due_date
              AND alert_type = :alert_type
              AND channel = 'push'
        ");

        foreach ($users as $user) {
            $userId = (int) $user['user_id'];
            $tzName = (string) ($user['timezone'] ?: 'Africa/Johannesburg');

            try {
                $tz = new DateTimeZone($tzName);
            } catch (\Throwable) {
                $tz = new DateTimeZone('Africa/Johannesburg');
            }

            $userLocalNow = $utcNow->setTimezone($tz);
            $localHour = (int) $userLocalNow->format('G');

            // Only send when the user's local hour is >= 08:00 (§9)
            if (!$ignoreHourCheck && $localHour < 8) {
                $usersSkippedBefore8am++;
                continue;
            }

            $userToday = $userLocalNow->setTime(0, 0, 0);
            $maxDueDate = $userToday->modify('+3 days')->format('Y-m-d');

            // 2. Select live, active, unpaid subscriptions with due_date <= userToday + 3
            $subStmt->execute([
                ':user_id' => $userId,
                ':max_due_date' => $maxDueDate,
            ]);
            $subs = $subStmt->fetchAll();

            if (empty($subs)) {
                continue;
            }

            // 3. Classify each subscription and log into notification_log idempotently
            $newItems = [];
            foreach ($subs as $sub) {
                $due = (new DateTimeImmutable($sub['due_date'], $tz))->setTime(0, 0, 0);
                $days = (int) $userToday->diff($due)->format('%r%a');

                if ($days > 3) {
                    continue;
                }

                if ($days < 0) {
                    $alertType = 'overdue';
                } elseif ($days === 0) {
                    $alertType = 'due_today';
                } elseif ($days === 1) {
                    $alertType = 'upcoming';
                } elseif ($days === 2) {
                    $alertType = 'upcoming_2d';
                } else {
                    $alertType = 'upcoming_3d';
                }

                $logStmt->execute([
                    ':user_id' => $userId,
                    ':subscription_id' => (int) $sub['id'],
                    ':cycle_due_date' => $sub['due_date'],
                    ':alert_type' => $alertType,
                ]);

                // 0 affected rows means this reminder was already sent for this cycle & alert_type
                if ($logStmt->rowCount() > 0) {
                    $notificationsLogged++;
                    $newItems[] = [
                        'id' => (int) $sub['id'],
                        'name' => (string) $sub['name'],
                        'amount' => (float) $sub['amount'],
                        'due_date' => (string) $sub['due_date'],
                        'days' => $days,
                        'alert_type' => $alertType,
                    ];
                }
            }

            if (empty($newItems)) {
                continue;
            }

            // 4. Build one digest per user from the newly logged items (§9 step 4)
            $payload = $this->buildDigestPayload($newItems, $tz);

            // 5 & 6. Send to each device and handle status codes
            $delivery = $this->sendToUserDevices($userId, $payload, self::REMINDER_PUSH_OPTIONS);
            $devicesSent += $delivery['sent'];
            $devicesFailed += $delivery['failed'];
            $expiredDeleted += $delivery['expiredDeleted'];

            if ($delivery['sent'] > 0) {
                $digestsSent++;
                continue;
            }

            // No device accepted the push: un-log the items so the next (hourly) run retries
            // instead of silently swallowing the reminder.
            foreach ($newItems as $item) {
                $unlogStmt->execute([
                    ':user_id' => $userId,
                    ':subscription_id' => $item['id'],
                    ':cycle_due_date' => $item['due_date'],
                    ':alert_type' => $item['alert_type'],
                ]);
                $notificationsLogged--;
            }
            $digestsRetryPending++;
        }

        return [
            'usersChecked' => $usersChecked,
            'usersSkippedBefore8am' => $usersSkippedBefore8am,
            'digestsSent' => $digestsSent,
            'notificationsLogged' => $notificationsLogged,
            'devicesSent' => $devicesSent,
            'devicesFailed' => $devicesFailed,
            'expiredDeleted' => $expiredDeleted,
            'digestsRetryPending' => $digestsRetryPending,
        ];
    }

    /**
     * Build the Web Push digest notification payload (§9 steps 4 & 5).
     *
     * @param array<int, array{id: int, name: string, amount: float, due_date: string, days: int, alert_type: string}> $items
     * @param DateTimeZone $tz
     * @return array{title: string, body: string, url: string, tag: string}
     */
    private function buildDigestPayload(array $items, DateTimeZone $tz): array
    {
        $count = count($items);

        if ($count === 1) {
            $item = $items[0];
            $formattedAmount = 'R ' . number_format($item['amount'], 2, ',', ' ');
            $formattedDate = (new DateTimeImmutable($item['due_date'], $tz))->format('j M Y');

            if ($item['alert_type'] === 'overdue') {
                $title = "Overdue: {$item['name']}";
                $absDays = abs($item['days']);
                $body = "{$item['name']} payment of {$formattedAmount} was due on {$formattedDate} ({$absDays}d ago).";
            } elseif ($item['alert_type'] === 'due_today') {
                $title = "Due Today: {$item['name']}";
                $body = "{$item['name']} payment of {$formattedAmount} is due today ({$formattedDate}).";
            } elseif ($item['days'] === 1) {
                $title = "Upcoming Bill: {$item['name']}";
                $body = "{$item['name']} payment of {$formattedAmount} is due tomorrow ({$formattedDate}).";
            } else {
                $title = "Upcoming Bill: {$item['name']}";
                $body = "{$item['name']} payment of {$formattedAmount} is due in {$item['days']} days ({$formattedDate}).";
            }
        } else {
            $title = "{$count} Subscriptions Due Soon!";
            $hasMultipleDaysAhead = false;
            foreach ($items as $it) {
                if (($it['days'] ?? 0) > 1) {
                    $hasMultipleDaysAhead = true;
                    break;
                }
            }
            if ($hasMultipleDaysAhead) {
                $body = "You have {$count} bills due in the next 3 days. Open SubTracker to review.";
            } else {
                $body = "You have {$count} bills due today or tomorrow. Open SubTracker to review.";
            }
        }

        return [
            'title' => $title,
            'body' => $body,
            'url' => './index.html#calendar',
            'tag' => 'subtracker-alert',
        ];
    }

    /**
     * Instantiate Minishlink\WebPush\WebPush with VAPID credentials if valid.
     */
    private function createWebPushInstance(): ?WebPush
    {
        $vapid = $this->settings['vapid'] ?? [];
        $subject = (string) ($vapid['subject'] ?? 'mailto:admin@subtracker.local');
        $publicKey = (string) ($vapid['public_key'] ?? '');
        $privateKey = (string) ($vapid['private_key'] ?? '');

        if ($publicKey === '' || $privateKey === '') {
            return null;
        }

        try {
            $auth = [
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ];

            $defaultOptions = [
                'TTL' => self::REMINDER_PUSH_OPTIONS['TTL'],
                'urgency' => self::REMINDER_PUSH_OPTIONS['urgency'],
                'topic' => 'subtracker-alert',
            ];

            return new WebPush(
                $auth,
                $defaultOptions,
                $this->httpClient,
                new RequestFactory(),
                new StreamFactory()
            );
        } catch (\Throwable $e) {
            error_log('Failed to initialize WebPush VAPID instance: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve dispatched push notifications recorded in the test spool.
     *
     * @return array<int, array{userId: int, subscriptionId: int, endpoint: string, payload: array<string, mixed>, statusCode: int, success: bool, expired: bool}>
     */
    public static function getTestSpool(): array
    {
        return self::$testSpool;
    }

    public static function clearTestSpool(): void
    {
        self::$testSpool = [];
    }
}
