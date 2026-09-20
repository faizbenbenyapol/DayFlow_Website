<?php
// =====================================================
// core/ErrorHandler.php — one place where unexpected failures end up
//
// Without this, anything the code did not anticipate reached the browser raw:
// a PDOException from a malformed filename produced a 500 with a stack trace
// naming server paths, and nothing recorded which request caused it.
//
// The handler does three things: log the failure with enough context to find
// it again, answer in the format the caller asked for, and never leak the
// details in production.
// =====================================================

final class ErrorHandler
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'handleThrowable']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Records a PHP notice/warning and lets execution continue.
     *
     * An undefined array key silently becoming null is how the password-change
     * crash hid for so long, so these are worth seeing. They are logged rather
     * than promoted to exceptions: this codebase has pages that run with
     * notices today, and turning those fatal would break working screens to
     * make a point. Fix what the log shows, then tighten.
     *
     * Suppressed diagnostics (@) and anything outside the current
     * error_reporting level are left alone.
     */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) return false;

        error_log(sprintf(
            'PHP %s: %s in %s:%d | %s',
            self::severityName($severity),
            $message,
            $file,
            $line,
            self::requestContext()
        ));

        return true; // handled; PHP's own printer stays out of the response
    }

    private static function severityName(int $severity): string
    {
        return [
            E_WARNING           => 'Warning',
            E_NOTICE            => 'Notice',
            E_DEPRECATED        => 'Deprecated',
            E_USER_WARNING      => 'User Warning',
            E_USER_NOTICE       => 'User Notice',
            E_USER_DEPRECATED   => 'User Deprecated',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
        ][$severity] ?? 'Error(' . $severity . ')';
    }

    public static function handleThrowable(Throwable $e): void
    {
        self::log($e);
        self::respond($e);
    }

    /**
     * A fatal error (memory exhaustion, a call to an undefined function)
     * never reaches the exception handler, so the last error is inspected as
     * the request dies.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) return;
        if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

        self::log(new ErrorException(
            $error['message'], 0, $error['type'], $error['file'], $error['line']
        ));

        if (!headers_sent()) self::respond(null);
    }

    /** The request line and account, so a logged failure can be reproduced. */
    private static function requestContext(): string
    {
        $user = 'guest';
        if (class_exists('Auth', false)) {
            // Auth reads the session, which may not be open on every path.
            try {
                $id = Auth::userId();
                if ($id) $user = (string)$id;
            } catch (Throwable $ignored) {
            }
        }

        return sprintf(
            '%s %s | user=%s | ip=%s',
            $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            $_SERVER['REQUEST_URI'] ?? '-',
            $user,
            $_SERVER['REMOTE_ADDR'] ?? '-'
        );
    }

    private static function log(Throwable $e): void
    {
        $context = self::requestContext();

        error_log(sprintf(
            "Unhandled %s: %s in %s:%d | %s\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $context,
            $e->getTraceAsString()
        ));
    }

    private static function respond(?Throwable $e): void
    {
        if (headers_sent()) return;

        http_response_code(500);

        $isDevelopment = defined('APP_ENV') && APP_ENV === 'development';
        $detail = ($isDevelopment && $e !== null)
            ? get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            : null;

        // An API caller wants JSON back even when things go wrong; a browser
        // wants a page it can read.
        if (class_exists('Request', false) && Request::isApi()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                array_filter([
                    'error'  => 'เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่อีกครั้ง',
                    'detail' => $detail,
                ]),
                JSON_UNESCAPED_UNICODE
            );
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>เกิดข้อผิดพลาด</title>'
           . '<style>body{font-family:system-ui,sans-serif;background:#eef2f7;color:#1e293b;'
           . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}'
           . 'h1{font-size:1.1rem;margin:0 0 8px}p{color:#64748b;font-size:.9rem;margin:0 0 20px}'
           . 'a{color:#0f172a;font-size:.875rem}pre{text-align:left;background:#e2e8f0;padding:12px;'
           . 'border-radius:8px;font-size:.75rem;overflow:auto;max-width:90vw}</style></head><body><div>'
           . '<h1>เกิดข้อผิดพลาดภายในระบบ</h1>'
           . '<p>ระบบบันทึกปัญหานี้ไว้แล้ว กรุณาลองใหม่อีกครั้ง</p>'
           . ($detail !== null ? '<pre>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>' : '')
           . '<a href="' . (defined('APP_URL') ? htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') : '/') . '/">กลับหน้าหลัก</a>'
           . '</div></body></html>';
    }
}
