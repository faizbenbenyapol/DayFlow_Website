<?php
// =====================================================
// config/config.php — Application Configuration
// =====================================================

// Environment variables are preferred on a VPS. A local .env file is also
// supported for simple Apache/PHP deployments without a process manager.
function loadDotEnv(string $file): void
{
    if (!is_file($file) || !is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if ($value !== '' && (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'")) {
            $value = trim($value, "\"'");
        }
        if ($key !== '' && getenv($key) === false) putenv($key . '=' . $value);
    }
}

loadDotEnv(dirname(__DIR__) . '/.env');

// The fallbacks keep the local development setup compatible with the original project.
function envValue(string $key, string $fallback = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $fallback : $value;
}

define('APP_ENV', envValue('APP_ENV', 'production'));
define('DB_HOST', envValue('DB_HOST', 'localhost'));
define('DB_NAME', envValue('DB_NAME', 'mylife_db'));
define('DB_USER', envValue('DB_USER', 'root'));
define('DB_PASS', envValue('DB_PASS', ''));

// --- Database (PRODUCTION - DirectAdmin) ---
// Uncomment these and comment out the LOCAL section above when deploying
// define('DB_HOST', 'localhost');
// define('DB_NAME', 'cpanelusername_mylife');
// define('DB_USER', 'cpanelusername_dbuser');
// define('DB_PASS', 'your_secure_db_password_here');

// --- Application URL ---
// LOCAL
define('APP_URL', rtrim(envValue('APP_URL', 'http://localhost/DayFlow'), '/'));

// PRODUCTION - DirectAdmin
// define('APP_URL', 'https://yourdomain.com');

// --- Application Settings ---
define('APP_NAME', 'DayFlow');
define('SESSION_NAME', 'mylife_sess');
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');
define('MAX_UPLOAD_BYTES', (int)envValue('MAX_UPLOAD_BYTES', (string)(20 * 1024 * 1024)));
define('TIMEZONE', envValue('TIMEZONE', 'Asia/Bangkok'));

// --- Google Sign-In ---
define('GOOGLE_CLIENT_ID', envValue('GOOGLE_CLIENT_ID', ''));
define('APP_KEY_ENV', envValue('APP_KEY', ''));
define('CRON_TOKEN', envValue('CRON_TOKEN', ''));

// --- Set timezone ---
date_default_timezone_set(TIMEZONE);

// Never expose application errors to visitors on a production VPS.
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', ROOT . '/storage/logs/php-error.log');
}

// =====================================================
// Global helper functions
// =====================================================

/**
 * Escape output for XSS prevention
 */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function downloadFilename(string $name, string $fallback = 'download'): string
{
    $name = preg_replace('/[\x00-\x1F\x7F"\\\/]+/u', '_', $name) ?? '';
    $name = trim($name, " .\t\r\n");
    return $name !== '' ? $name : $fallback;
}

/**
 * Generate a UUID v4
 */
function uuid4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Format bytes to human-readable
 */
function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * Get the application encryption key. Production requires a persistent APP_KEY;
 * development can fall back to a local ignored file.
 */
function appKey(): string
{
    static $key = null;
    if ($key !== null) return $key;

    // Production must use an externally managed, persistent secret. A key
    // generated inside a disposable container would make encrypted settings
    // unreadable after a rebuild.
    if (APP_ENV === 'production') {
        if (strlen(APP_KEY_ENV) < 64 || !ctype_xdigit(APP_KEY_ENV)) {
            throw new RuntimeException('APP_KEY must be configured in production');
        }
        return $key = APP_KEY_ENV;
    }

    if (APP_KEY_ENV !== '' && strlen(APP_KEY_ENV) >= 64 && ctype_xdigit(APP_KEY_ENV)) {
        return $key = APP_KEY_ENV;
    }

    $file = __DIR__ . '/.app_key';
    if (is_file($file)) {
        $key = trim(file_get_contents($file));
        if (strlen($key) >= 64) return $key;
    }
    $key = bin2hex(random_bytes(32));
    @file_put_contents($file, $key);
    @chmod($file, 0600);
    return $key;
}

/**
 * Encrypt a string using the app key (AES-256-CBC + HMAC)
 */
function appEncrypt(string $plain): string
{
    $key  = hex2bin(substr(appKey(), 0, 64));
    $iv   = random_bytes(16);
    $ct   = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    $mac  = hash_hmac('sha256', $iv . $ct, $key, true);
    return base64_encode($iv . $mac . $ct);
}

/**
 * Decrypt a string produced by appEncrypt(). Returns '' on failure.
 */
function appDecrypt(string $payload): string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 48) return '';
    $iv   = substr($raw, 0, 16);
    $mac  = substr($raw, 16, 32);
    $ct   = substr($raw, 48);
    $key  = hex2bin(substr(appKey(), 0, 64));
    $calc = hash_hmac('sha256', $iv . $ct, $key, true);
    if (!hash_equals($mac, $calc)) return '';
    $plain = openssl_decrypt($ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

/**
 * Format date in Thai Buddhist calendar style
 */
function thaiDate(string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
               'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $d = (int)date('j', $ts);
    $m = $months[(int)date('n', $ts) - 1];
    $y = (int)date('Y', $ts) + 543;
    return "$d $m $y";
}
