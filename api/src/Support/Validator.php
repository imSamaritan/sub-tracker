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
     * Validate timezone against IANA timezone database.
     */
    public static function validateTimezone(string $timezone): ?string
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return 'Invalid timezone provided.';
        }

        return null;
    }
}
