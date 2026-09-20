<?php
// =====================================================
// core/Autoloader.php — class loader
//
// The app has no Composer setup, so classes were pulled in with require_once
// at the top of every file that might need them. That loads code a request
// never touches (a page view still compiled the rate limiter, the remember-me
// tokens and every model its controller might use).
//
// Registering this resolver lets a class load the moment it is first named.
// The existing require_once calls stay valid — they are idempotent — so this is
// a safety net rather than a rewrite.
// =====================================================

final class Autoloader
{
    /** Directories searched, in order. Class name maps directly to <dir>/<Class>.php */
    private const PATHS = ['core', 'models', 'controllers'];

    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            // Namespaced or otherwise unusual names are not ours to resolve.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) return;

            foreach (self::PATHS as $dir) {
                $file = ROOT . '/' . $dir . '/' . $class . '.php';
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        });
    }
}
