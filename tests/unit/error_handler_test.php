<?php
// =====================================================
// tests/unit/error_handler_test.php — core/ErrorHandler.php
//
// The handler installs global state (set_exception_handler and friends), so
// each case exercises one piece directly rather than registering it.
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/ErrorHandler.php';

/**
 * Runs $fn with error_log redirected to a file, and returns what was written.
 */
function captureErrorLog(callable $fn): string
{
    $file = tempnam(sys_get_temp_dir(), 'dayflow-log-');
    $previous = ini_get('error_log');
    ini_set('error_log', $file);

    try {
        $fn();
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
    }

    $contents = (string)file_get_contents($file);
    @unlink($file);
    return $contents;
}

test('a warning is logged and execution continues', function (): void {
    $reached = false;

    $log = captureErrorLog(function () use (&$reached): void {
        // A notice must not stop the page: several screens in this codebase
        // run with them today.
        $handled = ErrorHandler::handleError(E_WARNING, 'Undefined array key "x"', '/app/file.php', 42);
        assertTrue($handled, 'the handler takes responsibility so PHP does not print it');
        $reached = true;
    });

    assertTrue($reached, 'control returned to the caller');
    assertStringContains('PHP Warning: Undefined array key "x"', $log);
    assertStringContains('/app/file.php:42', $log);
});

test('each severity is named in the log', function (): void {
    foreach ([E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated'] as $severity => $name) {
        $log = captureErrorLog(static function () use ($severity): void {
            ErrorHandler::handleError($severity, 'something', '/app/x.php', 1);
        });
        assertStringContains('PHP ' . $name . ':', $log);
    }
});

test('a diagnostic outside error_reporting is left to PHP', function (): void {
    $previous = error_reporting(E_ALL & ~E_DEPRECATED);

    try {
        // Returning false hands it back to PHP's own handling, which is what
        // an @-suppressed or excluded diagnostic should get.
        assertFalse(ErrorHandler::handleError(E_DEPRECATED, 'old thing', '/app/x.php', 1));
    } finally {
        error_reporting($previous);
    }
});

test('a logged failure carries the request line and account', function (): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI']    = '/api/files/upload';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.9';

    $log = captureErrorLog(static function (): void {
        ErrorHandler::handleError(E_WARNING, 'context probe', '/app/x.php', 1);
    });

    // Without these a logged failure cannot be traced back to a request.
    assertStringContains('POST /api/files/upload', $log);
    assertStringContains('ip=203.0.113.9', $log);
    assertStringContains('user=', $log);
});

test('an unhandled throwable is logged with its class, message and trace', function (): void {
    $log = captureErrorLog(static function (): void {
        // respond() writes headers, which CLI has none of; only the logging
        // half is exercised here. The HTTP behaviour is covered by the
        // integration suite.
        $method = new ReflectionMethod(ErrorHandler::class, 'log');
        $method->setAccessible(true);
        $method->invoke(null, new PDOException('SQLSTATE[22007]: bad value'));
    });

    assertStringContains('Unhandled PDOException', $log);
    assertStringContains('SQLSTATE[22007]: bad value', $log);
    assertStringContains('#0', $log, 'the stack trace should be recorded');
});
