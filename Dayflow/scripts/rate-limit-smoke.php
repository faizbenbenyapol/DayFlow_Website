<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/core/RateLimiter.php';

$key = 'smoke-' . bin2hex(random_bytes(8));
if (!RateLimiter::hit($key, 2, 60) || !RateLimiter::hit($key, 2, 60) || RateLimiter::hit($key, 2, 60)) {
    fwrite(STDERR, "Rate limiter behavior failed\n");
    exit(1);
}
RateLimiter::clear($key);
echo "Rate limit smoke passed.\n";
