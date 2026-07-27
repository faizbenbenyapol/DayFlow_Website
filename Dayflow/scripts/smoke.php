<?php
// Lightweight production-safe smoke test. Run with: php scripts/smoke.php

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';

$requiredTables = [
    'users', 'tasks', 'notes', 'calendar_events', 'finances', 'files',
    'focus_sessions', 'projects', 'project_tasks', 'project_members',
    'file_shares', 'file_transfers', 'app_shares', 'skills',
    'telegram_cron_logs', 'remember_tokens',
];

try {
    DB::run('SELECT 1')->fetchColumn();
    $missing = [];
    foreach ($requiredTables as $table) {
        $exists = DB::run(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->fetchColumn();
        if (!$exists) $missing[] = $table;
    }

    if ($missing) {
        fwrite(STDERR, 'Missing tables: ' . implode(', ', $missing) . "\n");
        exit(1);
    }

    echo 'Smoke test passed: database connection and core tables are healthy.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Smoke test failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
