<?php
// =====================================================
// config/config.php — Application Configuration
// =====================================================

// --- Database (LOCAL) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'mylife_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// --- Database (PRODUCTION - DirectAdmin) ---
// Uncomment these and comment out the LOCAL section above when deploying
// define('DB_HOST', 'localhost');
// define('DB_NAME', 'cpanelusername_mylife');
// define('DB_USER', 'cpanelusername_dbuser');
// define('DB_PASS', 'your_secure_db_password_here');

// --- Application URL ---
// LOCAL
define('APP_URL', 'http://localhost/my');

// PRODUCTION - DirectAdmin
// define('APP_URL', 'https://yourdomain.com');

// --- Application Settings ---
define('APP_NAME', 'ระบบจัดการชีวิต');
define('SESSION_NAME', 'mylife_sess');
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');
define('MAX_UPLOAD_BYTES', 20 * 1024 * 1024); // 20MB
define('TIMEZONE', 'Asia/Bangkok');

// --- Set timezone ---
date_default_timezone_set(TIMEZONE);

// --- Error reporting (set to 0 for production) ---
// LOCAL: show errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

// PRODUCTION: hide errors, log them
// error_reporting(0);
// ini_set('display_errors', 0);
// ini_set('log_errors', 1);

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
 * Get the application encryption key (auto-generated once, cached in config/.app_key)
 */
function appKey(): string
{
    static $key = null;
    if ($key !== null) return $key;
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
