<?php
// =====================================================
// core/Csrf.php — CSRF Token Management
// =====================================================

class Csrf
{
    private const KEY = 'csrf_token';

    /**
     * Get (or generate) the current CSRF token
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    /**
     * Verify a submitted token using constant-time comparison
     */
    public static function verify(string $token): bool
    {
        if (empty($_SESSION[self::KEY])) {
            return false;
        }
        return hash_equals($_SESSION[self::KEY], $token);
    }

    /**
     * Get token from request (header or POST field)
     */
    public static function fromRequest(): string
    {
        // Try X-CSRF-Token header first (AJAX)
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        // Fall back to POST field
        return $_POST['_csrf'] ?? '';
    }
}
