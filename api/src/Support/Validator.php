<?php
declare(strict_types=1);

namespace SubTracker\Support;

use DateTimeZone;

class Validator
{
    /**
     * Validate registration payload.
     *
     * @param array $data
     * @return array<string, string> Field-level error messages
     */
    public static function validateRegistration(array $data): array
    {
        $errors = [];

        $emailError = self::validateEmail($data['email'] ?? null);
        if ($emailError !== null) {
            $errors['email'] = $emailError;
        }

        $passwordError = self::validatePassword($data['password'] ?? null);
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        }

        if (!empty($data['displayName'])) {
            if (mb_strlen((string) $data['displayName']) > 100) {
                $errors['displayName'] = 'Display name cannot exceed 100 characters.';
            }
        }

        if (!empty($data['timezone'])) {
            $tzError = self::validateTimezone((string) $data['timezone']);
            if ($tzError !== null) {
                $errors['timezone'] = $tzError;
            }
        }

        return $errors;
    }

    /**
     * Validate login payload.
     */
    public static function validateLogin(array $data): array
    {
        $errors = [];

        if (empty($data['email'])) {
            $errors['email'] = 'Please enter your email address.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        if (empty($data['password'])) {
            $errors['password'] = 'Please enter your password.';
        }

        return $errors;
    }

    /**
     * Validate email format and max length.
     */
    public static function validateEmail(mixed $email): ?string
    {
        if ($email === null || trim((string) $email) === '') {
            return 'Please enter your email address.';
        }

        $emailStr = trim((string) $email);
        if (mb_strlen($emailStr) > 191) {
            return 'Email address cannot exceed 191 characters.';
        }

        if (!filter_var($emailStr, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }

        return null;
    }

    /**
     * Validate password complexity: >= 8 characters, at least 1 letter and 1 digit.
     */
    public static function validatePassword(mixed $password): ?string
    {
        if ($password === null || (string) $password === '') {
            return 'Please enter a password.';
        }

        $pwd = (string) $password;
        if (mb_strlen($pwd) < 8) {
            return 'Password must be at least 8 characters long.';
        }

        if (!preg_match('/[A-Za-z]/', $pwd) || !preg_match('/[0-9]/', $pwd)) {
            return 'Password must contain at least one letter and one number.';
        }

        return null;
    }

    /**
     * Validate profile update payload.
     *
     * @param array $data
     * @return array<string, string> Field-level error messages
     */
    public static function validateProfileUpdate(array $data): array
    {
        $errors = [];

        if (array_key_exists('monthlySalary', $data)) {
            $salary = $data['monthlySalary'];
            if (!is_numeric($salary) || (float) $salary < 0 || (float) $salary > 9999999999.99) {
                $errors['monthlySalary'] = 'Please enter a valid salary amount.';
            }
        }

        if (array_key_exists('salaryDay', $data)) {
            $salaryDay = $data['salaryDay'];
            if ($salaryDay !== null) {
                if (!is_numeric($salaryDay) || (int) $salaryDay < 1 || (int) $salaryDay > 31 || (int) $salaryDay != $salaryDay) {
                    $errors['salaryDay'] = 'Salary day must be between 1 and 31.';
                }
            }
        }

        if (array_key_exists('theme', $data)) {
            $theme = $data['theme'];
            if ($theme !== 'dark' && $theme !== 'light') {
                $errors['theme'] = 'Theme must be either "dark" or "light".';
            }
        }

        if (array_key_exists('systemNotificationsEnabled', $data)) {
            $val = $data['systemNotificationsEnabled'];
            if (!is_bool($val) && $val !== 0 && $val !== 1 && $val !== '0' && $val !== '1' && $val !== 'true' && $val !== 'false') {
                $errors['systemNotificationsEnabled'] = 'System notifications enabled must be a boolean.';
            }
        }

        if (array_key_exists('timezone', $data)) {
            $tz = (string) $data['timezone'];
            $tzErr = self::validateTimezone($tz);
            if ($tzErr !== null) {
                $errors['timezone'] = $tzErr;
            }
        }

        return $errors;
    }

    /**
     * Validate timezone against IANA timezone database.
     */
    public static function validateTimezone(string $timezone): ?string
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return 'Invalid timezone provided.';
        }

        return null;
    }

    /**
     * Validate Web Push subscription payload (§8.9).
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function validatePushSubscription(array $data): array
    {
        $errors = [];

        $endpoint = isset($data['endpoint']) ? trim((string) $data['endpoint']) : '';
        if ($endpoint === '') {
            $errors['endpoint'] = 'Push subscription endpoint URL is required.';
        } elseif (!filter_var($endpoint, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $endpoint)) {
            $errors['endpoint'] = 'Push subscription endpoint must be a valid HTTP/HTTPS URL.';
        }

        $keys = isset($data['keys']) && is_array($data['keys']) ? $data['keys'] : [];
        $p256dh = isset($keys['p256dh']) ? trim((string) $keys['p256dh']) : (isset($data['p256dh']) ? trim((string) $data['p256dh']) : '');
        $auth = isset($keys['auth']) ? trim((string) $keys['auth']) : (isset($data['auth']) ? trim((string) $data['auth']) : '');

        if ($p256dh === '') {
            $errors['keys.p256dh'] = 'Push subscription p256dh public key is required.';
        } elseif (mb_strlen($p256dh) > 255) {
            $errors['keys.p256dh'] = 'Push subscription p256dh key cannot exceed 255 characters.';
        }

        if ($auth === '') {
            $errors['keys.auth'] = 'Push subscription auth secret is required.';
        } elseif (mb_strlen($auth) > 255) {
            $errors['keys.auth'] = 'Push subscription auth secret cannot exceed 255 characters.';
        }

        if (isset($data['contentEncoding']) && $data['contentEncoding'] !== '') {
            $enc = trim((string) $data['contentEncoding']);
            if (!in_array($enc, ['aes128gcm', 'aesgcm'], true)) {
                $errors['contentEncoding'] = 'Unsupported content encoding. Must be "aes128gcm" or "aesgcm".';
            }
        }

        return $errors;
    }
}

