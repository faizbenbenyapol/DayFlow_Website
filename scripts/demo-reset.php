<?php
// CLI-only: the web server must never run this, even if it is reachable.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}
// Resets the shared demo account.
//
//   php scripts/demo-reset.php --snapshot   remember the demo account as it is now
//   php scripts/demo-reset.php              restore the remembered state
//
// The nightly cron job runs the restore on its own once a snapshot exists.

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/models/User.php';
require_once ROOT . '/models/AccountData.php';
require_once ROOT . '/core/DemoReset.php';

$demo = User::findDemo();
if (!$demo) {
    fwrite(STDERR, "ไม่พบบัญชี demo (users.is_demo = 1) — ดู sql/migrations/018_demo_account.sql\n");
    exit(1);
}

try {
    if (in_array('--snapshot', $_SERVER['argv'] ?? [], true)) {
        $counts = DemoReset::snapshot((int)$demo['id']);
        echo "บันทึก snapshot แล้ว: " . array_sum($counts) . " แถวใน " . count($counts) . " ตาราง\n";
    } else {
        $counts = DemoReset::restore((int)$demo['id']);
        echo "รีเซ็ตบัญชี demo แล้ว: " . array_sum($counts) . " แถวใน " . count($counts) . " ตาราง\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
