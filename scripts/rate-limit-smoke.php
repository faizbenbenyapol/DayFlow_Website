<?php
// CLI-only: the web server must never run this, even if it is reachable.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/core/RateLimiter.php';

$key = 'smoke-' . bin2hex(random_bytes(8));
if (!RateLimiter::hit($key, 2, 60) || !RateLimiter::hit($key, 2, 60) || RateLimiter::hit($key, 2, 60)) {
    fwrite(STDERR, "Rate limiter behavior failed\n");
    exit(1);
}
RateLimiter::clear($key);
echo "Rate limit smoke passed.\n";
