<?php
declare(strict_types=1);

/**
 * SubTracker Scheduled Job: Web Push Reminder Sender (§9 & §15.8)
 *
 * Usage:
 *   php api/bin/send-reminders.php [--ignore-hour] [--now=2026-10-17T08:00:00Z] [--json]
 *
 * Safe to run hourly via cPanel Cron Jobs or Windows Task Scheduler.
 * Deduplicates notifications via the `notification_log` table.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SubTracker\App;
use SubTracker\Services\PushService;

try {
    $app = App::create(dirname(__DIR__));
    $container = $app->getContainer();

    /** @var PushService $pushService */
    $pushService = $container->get(PushService::class);

    $args = $argv ?? [];
    $ignoreHour = in_array('--ignore-hour', $args, true);
    $jsonOutput = in_array('--json', $args, true);
    $referenceNow = null;

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--now=')) {
            $iso = substr($arg, 6);
            $referenceNow = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
        }
    }

    $stats = $pushService->processScheduledReminders($referenceNow, $ignoreHour);

    if ($jsonOutput) {
        echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        echo "[{$timestamp}] SubTracker Reminder Cron Completed:" . PHP_EOL;
        echo sprintf(
            "  Users checked: %d | Skipped (<08:00 local): %d | Digests sent: %d | Digests to retry: %d | Notifications logged: %d | Devices sent: %d | Devices failed: %d | Expired removed: %d",
            $stats['usersChecked'],
            $stats['usersSkippedBefore8am'],
            $stats['digestsSent'],
            $stats['digestsRetryPending'],
            $stats['notificationsLogged'],
            $stats['devicesSent'],
            $stats['devicesFailed'],
            $stats['expiredDeleted']
        ) . PHP_EOL;
    }

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] send-reminders.php failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
