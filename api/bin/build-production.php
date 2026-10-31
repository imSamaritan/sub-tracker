<?php
declare(strict_types=1);

/**
 * SubTracker Production Deployment Builder for Absolute Hosting cPanel NVMe Palladium (§15)
 *
 * Usage:
 *   php api/bin/build-production.php [--json]
 *
 * Generates:
 *   - dist/cpanel-release/sub-tracker-backend/   (private backend code, placed above public_html)
 *   - dist/cpanel-release/public_html/           (PWA frontend + public_html/api/ entry point)
 *   - dist/cpanel-release/.env.production.example (template for /home/<username>/.env)
 *   - dist/cpanel-release/cpanel-database-install.sql (MariaDB 10.6/10.11 compatible SQL + 14 categories)
 *   - dist/subtracker-cpanel-release.zip         (ready-to-upload cPanel archive)
 */

$rootDir = dirname(__DIR__, 2);
$apiDir = dirname(__DIR__);
$distDir = $rootDir . '/dist';
$releaseDir = $distDir . '/cpanel-release';
$backendTarget = $releaseDir . '/sub-tracker-backend';
$publicTarget = $releaseDir . '/public_html';

$args = $argv ?? [];
$jsonOutput = in_array('--json', $args, true);
$noVendor = in_array('--no-vendor', $args, true);

/**
 * Recursively delete a directory.
 */
function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            removeDir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Recursively copy a directory.
 */
function copyDir(string $src, string $dst): int
{
    $count = 0;
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    $items = scandir($src);
    if ($items === false) {
        return 0;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            $count += copyDir($s, $d);
        } else {
            copy($s, $d);
            $count++;
        }
    }
    return $count;
}

// 1. Clean and initialize dist/cpanel-release/
removeDir($releaseDir);
mkdir($backendTarget, 0755, true);
mkdir($publicTarget . '/api', 0755, true);

$filesCopied = 0;

// 2. Populate sub-tracker-backend/ (excluding tests and local .env)
$backendDirs = ['src', 'db', 'templates', 'bin'];
if (!$noVendor && is_dir($apiDir . '/vendor')) {
    $backendDirs[] = 'vendor';
}
foreach ($backendDirs as $dirName) {
    $filesCopied += copyDir($apiDir . '/' . $dirName, $backendTarget . '/' . $dirName);
}
foreach (['composer.json', 'composer.lock', 'phinx.php'] as $fileName) {
    if (is_file($apiDir . '/' . $fileName)) {
        copy($apiDir . '/' . $fileName, $backendTarget . '/' . $fileName);
        $filesCopied++;
    }
}

// 3. Populate public_html/ with PWA assets
foreach (['css', 'js', 'assets'] as $dirName) {
    $filesCopied += copyDir($rootDir . '/' . $dirName, $publicTarget . '/' . $dirName);
}
foreach (['index.html', 'manifest.webmanifest', 'sw.js', '.htaccess'] as $fileName) {
    if (is_file($rootDir . '/' . $fileName)) {
        copy($rootDir . '/' . $fileName, $publicTarget . '/' . $fileName);
        $filesCopied++;
    }
}

// 4. Write public_html/api/index.php (§15.1 specification)
$cpanelApiIndex = <<<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../sub-tracker-backend/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

$app = require __DIR__ . '/../../sub-tracker-backend/src/bootstrap.php';
$basePath = rtrim((string) ($_ENV['APP_BASE_PATH'] ?? '/api'), '/');
$app->setBasePath($basePath !== '' ? $basePath : '/api');
$app->run();

PHP;
file_put_contents($publicTarget . '/api/index.php', $cpanelApiIndex);
$filesCopied++;

// 5. Write public_html/api/.htaccess (§15.4 LiteSpeed Web Server configuration)
copy($apiDir . '/public/.htaccess', $publicTarget . '/api/.htaccess');
$filesCopied++;

// 6. Write .env.production.example (and copy dist/.env.production -> dist/cpanel-release/.env if present)
$prodEnvExample = <<<'ENV'
APP_ENV=production
APP_DEBUG=false
APP_URL=https://imsamaritan.co.za
APP_BASE_PATH=/api
CORS_ALLOWED_ORIGIN=
DB_HOST=localhost
DB_PORT=3306
DB_NAME=imsamari_subtracker
DB_USER=imsamari_root
DB_PASS='change-me-strong-password'
TOKEN_TTL_DAYS=30
PASSWORD_RESET_TTL_MINUTES=60
EMAIL_VERIFY_TTL_HOURS=24
UNVERIFIED_ACCOUNT_TTL_DAYS=7
VAPID_SUBJECT=mailto:no-reply@imsamaritan.co.za
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
MAIL_HOST=mail.imsamaritan.co.za
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=no-reply@imsamaritan.co.za
MAIL_PASSWORD='change-me-mailbox-password'
MAIL_FROM=no-reply@imsamaritan.co.za
MAIL_FROM_NAME=SubTracker
ENV;
file_put_contents($releaseDir . '/.env.production.example', $prodEnvExample . "\n");
$filesCopied++;

if (is_file($distDir . '/.env.production')) {
    copy($distDir . '/.env.production', $releaseDir . '/.env');
    $filesCopied++;
}

// 7. Generate MariaDB 10.6/10.11 & MySQL 8.4 compatible cpanel-database-install.sql (§15.2 & §15.3)
$sqlInstall = <<<'SQL'
-- =============================================================================
-- SubTracker — Production Database Schema & System Categories (§15.2 & §15.3)
-- Target: Absolute Hosting cPanel MariaDB 10.6 / 10.11 & MySQL 8.4
-- Instructions:
--   1. In cPanel MySQL Database Wizard, create database `<cpaneluser>_subtracker`.
--   2. Open phpMyAdmin, select `<cpaneluser>_subtracker`, and import this file
--      (OR run `vendor/bin/phinx migrate -e production` & `phinx seed:run -e production -s CategorySeeder`).
--   3. Note: DemoUserSeeder is intentionally omitted in production (§5 / §15.3).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- 1. users
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    email_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User accounts';

-- 2. user_profiles
CREATE TABLE IF NOT EXISTS user_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    salary_day TINYINT UNSIGNED NULL,
    currency CHAR(3) NOT NULL DEFAULT 'ZAR',
    currency_symbol VARCHAR(5) NOT NULL DEFAULT 'R',
    theme ENUM('dark', 'light') NOT NULL DEFAULT 'dark',
    system_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Johannesburg',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='1:1 user budget & UI preferences';

-- 3. auth_tokens
CREATE TABLE IF NOT EXISTS auth_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of opaque bearer token',
    device_name VARCHAR(100) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user (user_id),
    KEY idx_auth_tokens_expires (expires_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Opaque session bearer tokens';

-- 4. password_resets
CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of single-use reset token',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_hash (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Single-use password reset tokens';

-- 5. categories
CREATE TABLE IF NOT EXISTS categories (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    label VARCHAR(64) NOT NULL,
    default_icon VARCHAR(40) NOT NULL DEFAULT 'fa-tags',
    default_color CHAR(7) NOT NULL DEFAULT '#6b7280',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 99,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System subscription categories';

-- 6. subscriptions
CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    category_id SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    billing_cycle ENUM('weekly', 'monthly', 'yearly') NOT NULL DEFAULT 'monthly',
    due_date DATE NOT NULL,
    billing_day TINYINT UNSIGNED NULL,
    start_date DATE NULL,
    icon VARCHAR(40) NOT NULL DEFAULT 'fa-receipt',
    color CHAR(7) NOT NULL DEFAULT '#6366f1',
    notes VARCHAR(255) NULL,
    is_paid_this_cycle TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'paused') NOT NULL DEFAULT 'active',
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subscriptions_user_live (user_id, deleted_at, is_paid_this_cycle, due_date),
    KEY idx_subscriptions_category (category_id),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Recurring user subscriptions';

-- 7. payments
CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    subscription_name VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    cycle_due_date DATE NOT NULL,
    paid_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payments_user_date (user_id, paid_date DESC, id DESC),
    KEY idx_payments_sub_cycle (subscription_id, cycle_due_date),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historical payment ledger';

-- 8. push_subscriptions
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) GENERATED ALWAYS AS (SHA2(endpoint, 256)) STORED NOT NULL COMMENT 'SHA-256 generated hash for unique index on text endpoint',
    p256dh_key VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    content_encoding VARCHAR(30) NOT NULL DEFAULT 'aes128gcm',
    user_agent VARCHAR(255) NULL,
    failure_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_success_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_subscriptions_endpoint_hash (endpoint_hash),
    KEY idx_push_subscriptions_user (user_id),
    CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Web Push subscription endpoints';

-- 9. notification_log
CREATE TABLE IF NOT EXISTS notification_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    cycle_due_date DATE NOT NULL,
    alert_type ENUM('upcoming', 'upcoming_3d', 'upcoming_2d', 'due_today', 'overdue') NOT NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'push',
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_log_dedup (user_id, subscription_id, cycle_due_date, alert_type, channel),
    KEY idx_notification_log_sub (subscription_id),
    CONSTRAINT fk_notification_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_log_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Idempotent reminder deduplication log';

-- 10. vw_user_budget_summary
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

-- 11. email_verifications
CREATE TABLE IF NOT EXISTS email_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(191) NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of the plain token',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verifications_hash (token_hash),
    KEY idx_email_verifications_user (user_id, created_at),
    CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email verification tokens (hashed)';

-- 12. rate_limits
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(100) NOT NULL,
    window_start DATETIME NOT NULL COMMENT 'UTC start of the fixed window',
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, window_start),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API rate-limit counters';

-- 13. Seed 14 mandatory system categories (MariaDB & MySQL portable upsert)
INSERT INTO categories (code, label, default_icon, default_color, sort_order) VALUES
    ('Entertainment', 'Entertainment', 'fa-tv',            '#0084f7', 1),
    ('Music',         'Music',         'fa-music',         '#1db954', 2),
    ('Internet',      'Internet',      'fa-wifi',          '#0ea5e9', 3),
    ('Telco',         'Telco',         'fa-mobile-screen', '#f59e0b', 4),
    ('Fitness',       'Fitness',       'fa-dumbbell',      '#ef4444', 5),
    ('Health',        'Health',        'fa-heart-pulse',   '#10b981', 6),
    ('Cloud',         'Cloud',         'fa-cloud',         '#38bdf8', 7),
    ('Software',      'Software',      'fa-laptop-code',   '#8b5cf6', 8),
    ('Insurance',     'Insurance',     'fa-shield-halved', '#6366f1', 9),
    ('Housing',       'Housing',       'fa-house',         '#f97316', 10),
    ('Food',          'Food',          'fa-utensils',      '#84cc16', 11),
    ('Family',        'Family',        'fa-people-roof',   '#ec4899', 12),
    ('Savings',       'Savings',       'fa-piggy-bank',    '#14b8a6', 13),
    ('Other',         'Other',         'fa-tags',          '#6b7280', 14)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    default_icon = VALUES(default_icon),
    default_color = VALUES(default_color),
    sort_order = VALUES(sort_order);

SET FOREIGN_KEY_CHECKS = 1;
SQL;
file_put_contents($releaseDir . '/cpanel-database-install.sql', $sqlInstall . "\n");
$filesCopied++;

// 8. Package zip archive via ZipArchive or system tar fallback
$zipPath = $distDir . '/subtracker-cpanel-release.zip';
$zipCreated = false;
if (is_file($zipPath)) {
    @unlink($zipPath);
}

if (class_exists(ZipArchive::class)) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($releaseDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $itemPath = str_replace('\\', '/', $item->getPathname());
            $relPath = substr($itemPath, strlen(str_replace('\\', '/', $releaseDir)) + 1);
            if ($item->isDir()) {
                $zip->addEmptyDir($relPath);
            } else {
                $zip->addFile($item->getPathname(), $relPath);
            }
        }
        $zip->close();
        $zipCreated = is_file($zipPath);
    }
}

if (!$zipCreated) {
    $tarCmd = sprintf('tar -a -c -f "%s" -C "%s" .', $zipPath, $releaseDir);
    exec($tarCmd . ' 2>&1', $tarOut, $tarCode);
    $zipCreated = ($tarCode === 0 && is_file($zipPath));
}

$result = [
    'status' => 'ok',
    'releaseDir' => $releaseDir,
    'filesPackaged' => $filesCopied,
    'zipCreated' => $zipCreated,
    'zipPath' => $zipCreated ? $zipPath : null,
    'artifacts' => [
        'backendDir' => 'dist/cpanel-release/sub-tracker-backend',
        'publicHtmlDir' => 'dist/cpanel-release/public_html',
        'publicApiEntry' => 'dist/cpanel-release/public_html/api/index.php',
        'publicApiHtaccess' => 'dist/cpanel-release/public_html/api/.htaccess',
        'envTemplate' => 'dist/cpanel-release/.env.production.example',
        'sqlInstall' => 'dist/cpanel-release/cpanel-database-install.sql',
    ],
];

if ($jsonOutput) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    echo "[{$timestamp}] SubTracker cPanel Production Release Built:" . PHP_EOL;
    echo "  Release Directory : {$releaseDir}" . PHP_EOL;
    echo "  Files Packaged    : {$filesCopied}" . PHP_EOL;
    echo "  SQL Install Dump  : dist/cpanel-release/cpanel-database-install.sql" . PHP_EOL;
    echo "  Zip Archive       : " . ($zipCreated ? $zipPath : 'Skipped (ext-zip not enabled)') . PHP_EOL;
}

exit(0);
