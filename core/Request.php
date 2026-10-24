<?php
// =====================================================
// core/Request.php — HTTP Request Helpers
// =====================================================

class Request
{
    /**
     * HTTP method
     */
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Check if this is an API/AJAX request
     */
    public static function isApi(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
        $path = substr($uri, strlen($base));
        $path = strtok($path, '?');
        return strpos(ltrim($path, '/'), 'api/') === 0;
    }

    /**
     * Get JSON body as array (cached — php://input can only be read once on some SAPIs)
     */
    public static function json(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $raw = file_get_contents('php://input');
        if (!$raw) { $cache = []; return $cache; }
        $data = json_decode($raw, true);
        $cache = is_array($data) ? $data : [];
        return $cache;
    }

    /**
     * Get a value from JSON body, POST, or GET (in that order), trimmed.
     *
     * Text is kept exactly as typed apart from the surrounding whitespace.
     * This used to run strip_tags(), which is not an escaper: it silently cut
     * "a<b" down to "a" and "<3" to nothing, yet left quotes alone, so it
     * neither kept data intact nor made it safe inside an attribute. Output
     * is escaped where it is rendered — h() in PHP, escHtml() in JS.
     */
    public static function input(string $key, mixed $default = null): mixed
    {
        $value = self::rawInput($key, $default);
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Get raw input: not even trimmed (passwords, content whose whitespace matters)
     *
     * JSON lets any field arrive as a list. Where the caller's default says a
     * single value is expected, a list is treated as not sent: trim() and
     * (string) on it threw, and twenty endpoints answered with a 500. A field
     * that really is a list asks with an array default, or with list().
     */
    public static function rawInput(string $key, mixed $default = null): mixed
    {
        [$found, $value] = self::find($key);
        if (!$found) return $default;
        if (is_array($value) && !is_array($default)) return $default;
        return $value;
    }

    /**
     * A field that is a list: the list as sent, or null when it was left out
     * or is not a list (a caller tells "not sent" from "sent empty").
     */
    public static function list(string $key): ?array
    {
        [$found, $value] = self::find($key);
        return $found && is_array($value) ? $value : null;
    }

    /**
     * Looks a field up in the JSON body, then the form, then the query string.
     *
     * @return array{bool, mixed} whether it was sent, and its value
     */
    private static function find(string $key): array
    {
        $json = self::json();
        if (array_key_exists($key, $json)) return [true, $json[$key]];
        foreach ([$_POST, $_GET] as $source) {
            if (isset($source[$key])) return [true, $source[$key]];
        }
        return [false, null];
    }

    /**
     * Get query string param
     */
    public static function query(string $key, mixed $default = null): mixed
    {
        // ?month[]=1 makes the value an array; trim() then threw and the
        // request ended in a 500. A query word is a string or it is absent.
        return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : $default;
    }

    /**
     * Current URI path (relative to APP base)
     */
    public static function path(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = substr($path, strlen($base));
        return '/' . ltrim($path ?: '/', '/');
    }
}
