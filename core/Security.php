<?php
// =====================================================
// core/Security.php - HTTP security defaults
// =====================================================

class Security
{
    /** One nonce per request, shared by every inline <script> the page emits. */
    private static ?string $nonce = null;

    /**
     * The nonce this page's inline scripts must carry.
     *
     * With a nonce present, browsers ignore 'unsafe-inline' entirely — which
     * is the point: an injected <script> has no way to guess this value, so it
     * does not run, while the page's own scripts do.
     */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    public static function headers(): void
    {
        if (headers_sent()) return;

        // These are emitted here only. .htaccess must not duplicate them.
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        // Google Identity Services uses a cross-origin popup. Strict same-origin
        // severs the popup opener channel and can leave accounts.google.com/gsi
        // blank after the account is selected.
        header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow, noarchive');

        // Enforced, not report-only. Every inline event handler in the app was
        // replaced by a delegated data-act listener and every inline <script>
        // carries the nonce below, so script-src needs no 'unsafe-inline' —
        // which is what stops an injected script or attribute from running.
        //
        // style-src still allows inline: the markup carries a lot of style=""
        // attributes, and those are a far smaller risk than script.
        $nonce = self::nonce();
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'nonce-{$nonce}' https://accounts.google.com https://cdn.jsdelivr.net; "
            . "style-src 'self' 'unsafe-inline' https://accounts.google.com/gsi https://cdn.jsdelivr.net; "
            . "font-src 'self'; worker-src 'self'; img-src 'self' data: blob:; "
            . "connect-src 'self' https://accounts.google.com https://accounts.google.com/gsi/ "
            . "https://oauth2.googleapis.com https://generativelanguage.googleapis.com; "
            . "frame-src https://accounts.google.com https://accounts.google.com/gsi/; "
            . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; "
            . "object-src 'none'"
        );

        // HSTS is only safe when the request is already HTTPS.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
