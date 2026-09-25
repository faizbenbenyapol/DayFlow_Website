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
    public static function input(string $key, $default = null)
    {
        $json = self::json();
        if (array_key_exists($key, $json)) {
            return is_string($json[$key]) ? trim($json[$key]) : $json[$key];
        }
        foreach ([$_POST, $_GET] as $source) {
            if (isset($source[$key])) {
                return is_string($source[$key]) ? trim($source[$key]) : $source[$key];
            }
        }
        return $default;
    }

    /**
     * Get raw input: not even trimmed (passwords, content whose whitespace matters)
     */
    public static function rawInput(string $key, $default = null)
    {
        $json = self::json();
        if (array_key_exists($key, $json)) return $json[$key];
        if (isset($_POST[$key])) return $_POST[$key];
        if (isset($_GET[$key])) return $_GET[$key];
        return $default;
    }

    /**
     * Get query string param
     */
    public static function query(string $key, $default = null)
    {
        return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
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
