<?php
// =====================================================
// config/session.php — Secure Session Initialization
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);

    // Secure session cookie settings
    $cookieParams = [
        'lifetime' => 0,           // Until browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ];

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($cookieParams);
    } else {
        session_set_cookie_params(
            $cookieParams['lifetime'],
            $cookieParams['path'] . '; SameSite=' . $cookieParams['samesite'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_trans_sid', 0);

    session_start();
}
