<?php
// =====================================================
// tests/unit/rate_limiter_test.php — core/RateLimiter.php
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/RateLimiter.php';

test('attempts are allowed up to the limit and refused after it', function (): void {
    $key = 'test-limit-' . bin2hex(random_bytes(4));

    for ($i = 1; $i <= 3; $i++) {
        assertTrue(RateLimiter::hit($key, 3, 60), "attempt {$i} should be allowed");
    }
    assertFalse(RateLimiter::hit($key, 3, 60), 'the fourth attempt is over the limit');

    RateLimiter::clear($key);
});

test('clear() resets the window', function (): void {
    $key = 'test-clear-' . bin2hex(random_bytes(4));

    RateLimiter::hit($key, 1, 60);
    assertFalse(RateLimiter::hit($key, 1, 60), 'the limit is reached');

    RateLimiter::clear($key);
    assertTrue(RateLimiter::hit($key, 1, 60), 'a successful login clears the count');

    RateLimiter::clear($key);
});

test('separate keys are counted separately', function (): void {
    $a = 'test-a-' . bin2hex(random_bytes(4));
    $b = 'test-b-' . bin2hex(random_bytes(4));

    RateLimiter::hit($a, 1, 60);
    assertFalse(RateLimiter::hit($a, 1, 60));
    assertTrue(RateLimiter::hit($b, 1, 60), 'one address being limited must not limit another');

    RateLimiter::clear($a);
    RateLimiter::clear($b);
});

test('a state file older than a day is swept away', function (): void {
    $dir = ROOT . '/storage/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);

    // Nothing can still be inside a live window after a day, so this file is
    // exactly what the sweep exists to remove.
    $stale = $dir . '/' . hash('sha256', 'stale-' . bin2hex(random_bytes(4))) . '.json';
    file_put_contents($stale, json_encode(['started' => time() - 200000, 'attempts' => 1]));
    touch($stale, time() - 200000);

    assertTrue(is_file($stale), 'the fixture should exist before the sweep');

    // The sweep is probabilistic, so drive enough calls to make a miss
    // vanishingly unlikely (1 in 200 per call).
    $key = 'sweep-driver-' . bin2hex(random_bytes(4));
    for ($i = 0; $i < 2000 && is_file($stale); $i++) {
        RateLimiter::hit($key, PHP_INT_MAX, 60);
    }
    RateLimiter::clear($key);

    assertFalse(is_file($stale), 'a day-old state file should have been removed');
});

test('a fresh state file survives the sweep', function (): void {
    $dir = ROOT . '/storage/ratelimit';
    $key = 'fresh-' . bin2hex(random_bytes(4));

    RateLimiter::hit($key, 5, 3600);
    $file = $dir . '/' . hash('sha256', $key) . '.json';
    assertTrue(is_file($file));

    $driver = 'sweep-driver2-' . bin2hex(random_bytes(4));
    for ($i = 0; $i < 1000; $i++) {
        RateLimiter::hit($driver, PHP_INT_MAX, 60);
    }

    assertTrue(is_file($file), 'an in-window file must not be swept');

    RateLimiter::clear($key);
    RateLimiter::clear($driver);
});
