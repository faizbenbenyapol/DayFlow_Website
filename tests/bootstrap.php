<?php
// =====================================================
// tests/bootstrap.php — minimal test framework
//
// The project has no Composer dependencies, so the runner is deliberately
// small: register cases with test(), assert with the helpers below, and let
// run.php collect the results.
// =====================================================

declare(strict_types=1);

final class TestFailure extends RuntimeException {}

final class TestRegistry
{
    /** @var array<string, array<string, callable>> suite => name => case */
    public static array $cases = [];
    public static string $currentSuite = '';

    public static function add(string $name, callable $fn): void
    {
        self::$cases[self::$currentSuite][$name] = $fn;
    }
}

function test(string $name, callable $fn): void
{
    TestRegistry::add($name, $fn);
}

/**
 * Renders any value in a short, readable form for failure messages.
 */
function describeValue(mixed $value): string
{
    if (is_string($value)) return "'" . (mb_strlen($value) > 80 ? mb_substr($value, 0, 77) . '...' : $value) . "'";
    if (is_bool($value))   return $value ? 'true' : 'false';
    if ($value === null)   return 'null';
    if (is_scalar($value)) return (string)$value;
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: gettype($value);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(
            ($message !== '' ? $message . ' — ' : '') .
            'expected ' . describeValue($expected) . ', got ' . describeValue($actual)
        );
    }
}

function assertTrue(mixed $actual, string $message = 'expected true'): void
{
    if ($actual !== true) throw new TestFailure($message . ' — got ' . describeValue($actual));
}

function assertFalse(mixed $actual, string $message = 'expected false'): void
{
    if ($actual !== false) throw new TestFailure($message . ' — got ' . describeValue($actual));
}

function assertContains(mixed $needle, array $haystack, string $message = ''): void
{
    if (!in_array($needle, $haystack, true)) {
        throw new TestFailure(
            ($message !== '' ? $message . ' — ' : '') .
            describeValue($needle) . ' not found in ' . describeValue($haystack)
        );
    }
}

function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
{
    if (in_array($needle, $haystack, true)) {
        throw new TestFailure(
            ($message !== '' ? $message . ' — ' : '') .
            describeValue($needle) . ' unexpectedly present in ' . describeValue($haystack)
        );
    }
}

function assertArrayHasKey(string $key, array $array, string $message = ''): void
{
    if (!array_key_exists($key, $array)) {
        throw new TestFailure(
            ($message !== '' ? $message . ' — ' : '') .
            "missing key '{$key}' in " . describeValue(array_keys($array))
        );
    }
}

function assertArrayNotHasKey(string $key, array $array, string $message = ''): void
{
    if (array_key_exists($key, $array)) {
        throw new TestFailure(
            ($message !== '' ? $message . ' — ' : '') .
            "key '{$key}' should not be present in " . describeValue(array_keys($array))
        );
    }
}

function assertStringContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new TestFailure(
            ($message !== '' ? $message . ' — ' : '') .
            describeValue($needle) . ' not found in ' . describeValue($haystack)
        );
    }
}

/**
 * A path outside public/ is either refused outright (dotfiles: 403) or falls
 * through to the router, which answers with the app's own 404 page. Either
 * way the file itself must not come back.
 */
function assertNotServed(TestClient $client, string $path): void
{
    $response = $client->get($path);
    if ($response['status'] === 403) return;

    assertSame(404, $response['status'], $path . ' must not be served');
    assertStringContains('ไม่พบหน้าที่ต้องการ', $response['body'], $path . ' should get the app 404 page, not the file');
}
