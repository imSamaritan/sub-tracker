<?php
declare(strict_types=1);

namespace SubTracker\Services;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

class MailService
{
    /** @var array<int, array{to: string, subject: string, html: string, text: string, token: string}> */
    private static array $testSpool = [];

    private string $templateDir;

    public function __construct(
        private readonly array $settings,
        ?string $templateDir = null
    ) {
        $this->templateDir = $templateDir ?? dirname(__DIR__, 2) . '/templates/email';
    }

    /**
     * Dispatch an account verification email.
     */
    public function sendVerificationEmail(string $toEmail, string $displayName, string $plainToken): bool
    {
        $appUrl = rtrim($this->settings['app_url'] ?? 'http://localhost/projects/sub-tracker', '/');
        $verifyUrl = $appUrl . '/#verify?token=' . urlencode($plainToken);

        $html = $this->renderTemplate('verify-email.html', [
            'displayName' => htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
            'verifyUrl' => $verifyUrl,
        ]);

        $text = $this->renderTemplate('verify-email.txt', [
            'displayName' => $displayName,
            'verifyUrl' => $verifyUrl,
        ]);

        return $this->send($toEmail, $displayName, 'Verify your SubTracker account', $html, $text, $plainToken);
    }

    /**
     * Dispatch a password reset email.
     */
    public function sendPasswordResetEmail(string $toEmail, string $displayName, string $plainToken): bool
    {
        $appUrl = rtrim($this->settings['app_url'] ?? 'http://localhost/projects/sub-tracker', '/');
        $resetUrl = $appUrl . '/#reset?token=' . urlencode($plainToken);

        $html = $this->renderTemplate('reset-password.html', [
            'displayName' => htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
            'resetUrl' => $resetUrl,
        ]);

        $text = $this->renderTemplate('reset-password.txt', [
            'displayName' => $displayName,
            'resetUrl' => $resetUrl,
        ]);

        return $this->send($toEmail, $displayName, 'Reset your SubTracker password', $html, $text, $plainToken);
    }

    /**
     * Internal mail dispatcher using PHPMailer or recording into test spool.
     */
    protected function send(string $toEmail, string $displayName, string $subject, string $html, string $text, string $token = ''): bool
    {
        // Record into test spool for verification testing and offline local dev
        self::$testSpool[] = [
            'to' => $toEmail,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'token' => $token,
        ];

        $mailConfig = $this->settings['mail'] ?? [];
        $host = $mailConfig['host'] ?? '';
        $pass = $mailConfig['password'] ?? '';

        // If in test environment, or if credentials are dummy, skip live network SMTP
        if (
            ($this->settings['app_env'] ?? 'local') === 'test'
            || empty($host)
            || $host === 'mail.yourdomain.co.za'
            || $pass === 'change-me'
        ) {
            return true;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int) ($mailConfig['port'] ?? 465);
            $mail->SMTPAuth = true;
            $mail->Username = $mailConfig['username'] ?? '';
            $mail->Password = $pass;

            $encryption = strtolower($mailConfig['encryption'] ?? 'ssl');
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls' || $encryption === 'starttls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->CharSet = 'UTF-8';
            $mail->setFrom(
                $mailConfig['from'] ?? 'no-reply@subtracker.local',
                $mailConfig['from_name'] ?? 'SubTracker'
            );
            $mail->addAddress($toEmail, $displayName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;

            return $mail->send();
        } catch (Throwable $e) {
            error_log('Failed to dispatch email via SMTP: ' . $e->getMessage());
            return false;
        }
    }

    private function renderTemplate(string $filename, array $params): string
    {
        $path = $this->templateDir . '/' . $filename;
        if (!file_exists($path)) {
            return '';
        }

        $content = (string) file_get_contents($path);
        foreach ($params as $key => $val) {
            $content = str_replace('{{' . $key . '}}', (string) $val, $content);
        }

        return $content;
    }

    /**
     * Retrieve dispatched emails recorded in the spool.
     * @return array<int, array{to: string, subject: string, html: string, text: string, token: string}>
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
