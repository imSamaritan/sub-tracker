<?php
declare(strict_types=1);

/**
 * SubTracker Scheduled Job: Housekeeping Cleanup (§15.8)
 *
 * Usage:
 *   php api/bin/cleanup.php [--json]
 *
 * Cleans up:
 *   - Unverified accounts older than 7 days
 *   - Expired or used email verification tokens
 *   - Expired or used password reset tokens
 *   - Rate-limit counter rows older than 24 hours
 *   - Expired or revoked (>30d) auth tokens
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SubTracker\App;
use SubTracker\Services\CleanupService;

try {
    $app = App::create(dirname(__DIR__));
    $container = $app->getContainer();

    /** @var CleanupService $cleanupService */
    $cleanupService = $container->get(CleanupService::class);

    $args = $argv ?? [];
    $jsonOutput = in_array('--json', $args, true);

    $stats = $cleanupService->runCleanup();

    if ($jsonOutput) {
        echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        echo "[{$timestamp}] SubTracker Housekeeping Cleanup Completed:" . PHP_EOL;
        echo sprintf(
            "  Unverified users deleted: %d | Email verifications deleted: %d | Password resets deleted: %d | Rate limits pruned: %d | Auth tokens deleted: %d",
            $stats['unverifiedUsersDeleted'],
            $stats['emailVerificationsDeleted'],
            $stats['passwordResetsDeleted'],
            $stats['rateLimitsDeleted'],
            $stats['authTokensDeleted']
        ) . PHP_EOL;
    }

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] cleanup.php failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
