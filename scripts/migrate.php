<?php
// DayFlow CLI migration runner. Every migration is written to be safe to run again.

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';

$files = [
    'sql/schema.sql',
    'sql/migrate_ai.sql',
    'sql/migrate_app_shares.sql',
    'sql/migrate_file_transfer.sql',
    'sql/migrate_focus.sql',
    'sql/migrate_projects.sql',
    'sql/migrate_project_collab.sql',
    'sql/migrate_project_share.sql',
    'sql/migrate_shares.sql',
    'sql/migrate_skills.sql',
    'sql/migrate_stock_watchlists.sql',
    'sql/migrate_telegram_cron.sql',
    'sql/migrate_remember_tokens.sql',
    'sql/migrate_habits.sql',
    'sql/migrate_quick_capture.sql',
    'sql/migrate_menu_order.sql',
];

foreach ($files as $relative) {
    $file = ROOT . '/' . $relative;
    if (!is_file($file)) {
        fwrite(STDERR, "Missing migration: {$relative}\n");
        exit(1);
    }

    echo "Applying {$relative}\n";
    try {
        DB::conn()->exec((string)file_get_contents($file));
    } catch (Throwable $e) {
        fwrite(STDERR, "Migration failed: {$relative}\n{$e->getMessage()}\n");
        exit(1);
    }
}

echo "Migrations completed successfully.\n";
