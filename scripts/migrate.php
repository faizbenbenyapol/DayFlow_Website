<?php
// DayFlow CLI migration runner.
//
//   php scripts/migrate.php            apply everything still pending
//   php scripts/migrate.php --status   list what has and has not been applied
//   php scripts/migrate.php --force    re-apply everything, tracking aside
//
// Every migration is written to be safe to run again, but until now nothing
// recorded what had already run, so a partially applied database looked exactly
// like a fresh one. Applied files are now tracked in schema_migrations together
// with a checksum, which also surfaces a migration that was edited after it
// shipped.

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
    'sql/migrate_theme_colors.sql',
    'sql/migrate_demo_account.sql',
    'sql/migrate_perf_indexes.sql',
    'sql/migrate_theme_auto.sql',
    'sql/migrate_recurrence.sql',
    'sql/migrate_two_factor.sql',
    'sql/migrate_web_push.sql',
];

$args     = array_slice($argv, 1);
$statusOnly = in_array('--status', $args, true);
$force      = in_array('--force', $args, true);

// The tracking table has to use the same charset as the rest of the schema;
// a mismatched collation here is what broke the demo account migration.
DB::conn()->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(191) NOT NULL,
        checksum   CHAR(64)     NOT NULL,
        applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (filename)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = [];
foreach (DB::run('SELECT filename, checksum, applied_at FROM schema_migrations')->fetchAll() as $row) {
    $applied[$row['filename']] = $row;
}

$missing = [];
foreach ($files as $relative) {
    if (!is_file(ROOT . '/' . $relative)) $missing[] = $relative;
}
if ($missing) {
    fwrite(STDERR, "Missing migration files:\n  " . implode("\n  ", $missing) . "\n");
    exit(1);
}

if ($statusOnly) {
    foreach ($files as $relative) {
        $checksum = hash_file('sha256', ROOT . '/' . $relative);
        if (!isset($applied[$relative])) {
            printf("%-40s %s\n", $relative, 'PENDING');
        } elseif ($applied[$relative]['checksum'] !== $checksum) {
            printf("%-40s %s\n", $relative, 'CHANGED since it was applied on ' . $applied[$relative]['applied_at']);
        } else {
            printf("%-40s %s\n", $relative, 'applied ' . $applied[$relative]['applied_at']);
        }
    }
    exit(0);
}

$record = DB::conn()->prepare(
    'INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = CURRENT_TIMESTAMP'
);

$ran = 0;
foreach ($files as $relative) {
    $file     = ROOT . '/' . $relative;
    $checksum = hash_file('sha256', $file);

    if (!$force && isset($applied[$relative])) {
        if ($applied[$relative]['checksum'] === $checksum) {
            echo "Skipping {$relative} (already applied)\n";
            continue;
        }
        echo "Re-applying {$relative} (file changed since it was applied)\n";
    } else {
        echo "Applying {$relative}\n";
    }

    try {
        DB::conn()->exec((string)file_get_contents($file));
        $record->execute([$relative, $checksum]);
        $ran++;
    } catch (Throwable $e) {
        fwrite(STDERR, "Migration failed: {$relative}\n{$e->getMessage()}\n");
        exit(1);
    }
}

echo $ran === 0
    ? "Database is already up to date.\n"
    : "Migrations completed successfully ({$ran} applied).\n";
