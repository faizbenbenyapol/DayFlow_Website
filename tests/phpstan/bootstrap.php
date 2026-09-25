<?php
// Loaded by PHPStan before analysis: the constants and helpers that
// public/index.php sets up at runtime (ROOT, APP_URL, h(), appEncrypt() ...).
define('ROOT', dirname(__DIR__, 2));
if (getenv('APP_ENV') === false) putenv('APP_ENV=development');
if (getenv('APP_KEY') === false) putenv('APP_KEY=' . str_repeat('ab', 32));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';
