<?php
// =====================================================
// core/Security.php - HTTP security defaults
// =====================================================

class Security
{
    public static function headers(): void
    {
        if (headers_sent()) return;

        // These are emitted here only. .htaccess must not duplicate them.
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow, noarchive');

        // Report-only first: the current UI still contains inline handlers.
        // This gives us CSP violation telemetry without breaking the app.
        header("Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self' https://accounts.google.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self' https://oauth2.googleapis.com https://generativelanguage.googleapis.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

        // HSTS is only safe when the request is already HTTPS.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
