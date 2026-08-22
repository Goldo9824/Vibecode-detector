<?php
declare(strict_types=1);

/**
 * Single shared-password auth for admin/. There is exactly one admin, no
 * accounts, no roles — the same posture as the rest of this project, just
 * applied to the one part of it that now needs a gate at all.
 *
 * The password hash lives in data/admin-password.php: set up by hand,
 * gitignored, never committed — same pattern as data/secret.key and
 * data/api-keys.txt. If that file is missing, every login attempt fails;
 * there is no default password.
 */
final class AdminAuth
{
    const SESSION_KEY = 'vcd_admin';
    const CSRF_KEY    = 'vcd_admin_csrf';
    const FLASH_KEY   = 'vcd_admin_flash';

    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        session_start();
    }

    private static function passwordHash(): ?string
    {
        $file = VCD_DATA . '/admin-password.php';
        if (!is_readable($file)) {
            return null;
        }
        $config = require $file;
        return is_array($config) && !empty($config['hash']) ? (string) $config['hash'] : null;
    }

    public static function isLoggedIn(): bool
    {
        self::ensureSession();
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    /** True on success, having started the session. False leaves no session state changed. */
    public static function attempt(string $password): bool
    {
        $hash = self::passwordHash();
        if ($hash === null || $password === '' || !password_verify($password, $hash)) {
            return false;
        }
        self::ensureSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
        return true;
    }

    public static function logout(): void
    {
        self::ensureSession();
        $_SESSION = array();
        session_destroy();
    }

    /** Redirects to the login page and exits if there is no active admin session. */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function csrfToken(): string
    {
        self::ensureSession();
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(16));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    public static function checkCsrf(string $token): bool
    {
        self::ensureSession();
        return $token !== '' && !empty($_SESSION[self::CSRF_KEY]) && hash_equals($_SESSION[self::CSRF_KEY], $token);
    }

    /**
     * One-shot message carried across the redirect after a POST, so a newly
     * created key's plaintext can be shown once without ever appearing in a
     * URL, a referer header, or surviving a page refresh.
     *
     * @param array<string,mixed> $data
     */
    public static function setFlash(array $data): void
    {
        self::ensureSession();
        $_SESSION[self::FLASH_KEY] = $data;
    }

    /** @return array<string,mixed>|null */
    public static function takeFlash(): ?array
    {
        self::ensureSession();
        $data = isset($_SESSION[self::FLASH_KEY]) ? $_SESSION[self::FLASH_KEY] : null;
        unset($_SESSION[self::FLASH_KEY]);
        return $data;
    }
}
