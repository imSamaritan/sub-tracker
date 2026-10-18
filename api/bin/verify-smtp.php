<?php
declare(strict_types=1);

/**
 * SubTracker Production Utility: SMTP & Email Template Verification (§8.1 & §15.7)
 *
 * Usage:
 *   php api/bin/verify-smtp.php [--to=user@example.co.za] [--live] [--json]
 *
 * Verifies:
 *   1. Email templates (verify-email.html/.txt, reset-password.html/.txt) exist and render cleanly.
 *   2. Deep-link URLs (#verify?token=... and #reset?token=...) match PWA hash routing.
 *   3. PHPMailer class availability, OpenSSL extension, and cPanel SMTP configuration.
 *   4. Optional live SMTP delivery when --live is passed with configured SMTP credentials.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use SubTracker\App;
use SubTracker\Services\MailService;

try {
    $app = App::create(dirname(__DIR__));
    $container = $app->getContainer();

    /** @var array<string, mixed> $settings */
    $settings = $container->get('settings');
    /** @var MailService $mailService */
    $mailService = $container->get(MailService::class);

    $args = $argv ?? [];
    $jsonOutput = in_array('--json', $args, true);
    $liveMode = in_array('--live', $args, true);
    $recipient = 'thabo.verify@example.co.za';

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--to=')) {
            $recipient = substr($arg, 5);
        }
    }

    $templateDir = dirname(__DIR__) . '/templates/email';
    $requiredTemplates = [
        'verify-email.html',
        'verify-email.txt',
        'reset-password.html',
        'reset-password.txt',
    ];

    $templateStatus = [];
    $allTemplatesPresent = true;
    foreach ($requiredTemplates as $tpl) {
        $exists = is_file($templateDir . '/' . $tpl) && is_readable($templateDir . '/' . $tpl);
        $templateStatus[$tpl] = $exists;
        if (!$exists) {
            $allTemplatesPresent = false;
        }
    }

    MailService::clearTestSpool();

    if (!$liveMode) {
        $_ENV['APP_ENV'] = 'test';
        $app = App::create(dirname(__DIR__));
        $container = $app->getContainer();
        $mailService = $container->get(MailService::class);
    }

    $sampleVerifyToken = bin2hex(random_bytes(16));
    $sampleResetToken = bin2hex(random_bytes(16));

    $verifyOk = $mailService->sendVerificationEmail($recipient, 'Thabo Mbeki', $sampleVerifyToken);
    $resetOk = $mailService->sendPasswordResetEmail($recipient, 'Thabo Mbeki', $sampleResetToken);

    $spool = MailService::getTestSpool();
    $verifyMsg = $spool[0] ?? null;
    $resetMsg = $spool[1] ?? null;

    $verifyLinkValid = $verifyMsg !== null
        && str_contains($verifyMsg['html'], '/#verify?token=' . urlencode($sampleVerifyToken))
        && str_contains($verifyMsg['html'], '/#copy-token?type=verify&token=' . urlencode($sampleVerifyToken))
        && str_contains($verifyMsg['html'], '<div id="verify-token-val" class="token-value">' . $sampleVerifyToken . '</div>')
        && str_contains($verifyMsg['html'], 'id="copy-verify-token-btn"')
        && str_contains($verifyMsg['text'], '/#verify?token=' . urlencode($sampleVerifyToken))
        && str_contains($verifyMsg['text'], $sampleVerifyToken)
        && !str_contains($verifyMsg['html'], '{{')
        && !str_contains($verifyMsg['text'], '{{');

    $resetLinkValid = $resetMsg !== null
        && str_contains($resetMsg['html'], '/#reset?token=' . urlencode($sampleResetToken))
        && str_contains($resetMsg['html'], '/#copy-token?type=reset&token=' . urlencode($sampleResetToken))
        && str_contains($resetMsg['html'], '<div id="reset-token-val" class="token-value">' . $sampleResetToken . '</div>')
        && str_contains($resetMsg['html'], 'id="copy-reset-token-btn"')
        && str_contains($resetMsg['text'], '/#reset?token=' . urlencode($sampleResetToken))
        && str_contains($resetMsg['text'], $sampleResetToken)
        && !str_contains($resetMsg['html'], '{{')
        && !str_contains($resetMsg['text'], '{{');

    $mailCfg = $settings['mail'] ?? [];
    $isDummyConfig = empty($mailCfg['host'])
        || $mailCfg['host'] === 'mail.yourdomain.co.za'
        || ($mailCfg['password'] ?? '') === 'change-me';

    $liveResult = null;
    if ($liveMode) {
        if ($isDummyConfig) {
            $liveResult = [
                'attempted' => false,
                'reason' => 'MAIL_HOST or MAIL_PASSWORD in .env is still set to placeholder values.',
            ];
        } else {
            $liveResult = [
                'attempted' => true,
                'verifySent' => $verifyOk,
                'resetSent' => $resetOk,
            ];
        }
    }

    $report = [
        'status' => ($allTemplatesPresent && $verifyOk && $resetOk && $verifyLinkValid && $resetLinkValid) ? 'ok' : 'error',
        'phpMailerAvailable' => class_exists(PHPMailer::class),
        'opensslLoaded' => extension_loaded('openssl'),
        'appUrl' => $settings['app_url'] ?? '',
        'smtpConfig' => [
            'host' => $mailCfg['host'] ?? '',
            'port' => $mailCfg['port'] ?? 465,
            'encryption' => $mailCfg['encryption'] ?? 'ssl',
            'from' => $mailCfg['from'] ?? '',
            'fromName' => $mailCfg['from_name'] ?? 'SubTracker',
            'mode' => $isDummyConfig ? 'spool-simulation (placeholder credentials)' : 'live-smtp-configured',
        ],
        'templates' => $templateStatus,
        'verificationEmail' => [
            'dispatched' => $verifyOk,
            'subject' => $verifyMsg['subject'] ?? '',
            'deepLinkValid' => $verifyLinkValid,
        ],
        'passwordResetEmail' => [
            'dispatched' => $resetOk,
            'subject' => $resetMsg['subject'] ?? '',
            'deepLinkValid' => $resetLinkValid,
        ],
        'liveTest' => $liveResult,
    ];

    if ($jsonOutput) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        echo "[{$timestamp}] SubTracker SMTP & Email Verification Report:" . PHP_EOL;
        echo sprintf(
            "  PHPMailer: %s | OpenSSL: %s | Templates (4/4): %s",
            $report['phpMailerAvailable'] ? 'OK' : 'MISSING',
            $report['opensslLoaded'] ? 'OK' : 'MISSING',
            $allTemplatesPresent ? 'OK' : 'MISSING'
        ) . PHP_EOL;
        echo sprintf(
            "  SMTP Host: %s:%d (%s) | Mode: %s",
            $report['smtpConfig']['host'],
            $report['smtpConfig']['port'],
            $report['smtpConfig']['encryption'],
            $report['smtpConfig']['mode']
        ) . PHP_EOL;
        echo sprintf(
            "  Verify Email Template & #verify Deep-Link: %s | Reset Password Template & #reset Deep-Link: %s",
            ($verifyOk && $verifyLinkValid) ? 'PASS' : 'FAIL',
            ($resetOk && $resetLinkValid) ? 'PASS' : 'FAIL'
        ) . PHP_EOL;
    }

    exit($report['status'] === 'ok' ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] verify-smtp.php failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
