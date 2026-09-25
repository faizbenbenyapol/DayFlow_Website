<?php
// =====================================================
// tests/integration/migrations_test.php
//
// The schema is owned by sql/migrations/ and scripts/migrate.php alone. These
// run next to the database, inside the app container.
// =====================================================

declare(strict_types=1);

function runMigrate(string $args = ''): string
{
    return (string)shell_exec(PHP_BINARY . ' ' . escapeshellarg(ROOT . '/scripts/migrate.php') . ' ' . $args . ' 2>&1');
}

function migrationsDb(): void
{
    require_once ROOT . '/config/database.php';
}

test('migrations are numbered, and none share a number', function (TestClient $_c): void {
    $names = array_map('basename', glob(ROOT . '/sql/migrations/*.sql'));
    assertTrue(count($names) > 20, 'the migrations folder should not be empty');

    $numbers = [];
    foreach ($names as $name) {
        assertTrue((bool)preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $name, $m), "{$name} must be NNN_name.sql");
        $numbers[] = $m[1];
    }
    assertSame(count($numbers), count(array_unique($numbers)), 'two migrations share a number');
});

test('running the migrations twice changes nothing the second time', function (TestClient $_c): void {
    runMigrate();
    $second = runMigrate();

    assertStringContains('Database is already up to date.', $second, $second);
    assertFalse(str_contains($second, 'Re-applying'), 'nothing should look changed: ' . $second);
});

test('a migration recorded under its old name is not run again', function (TestClient $_c): void {
    migrationsDb();
    runMigrate();

    // Pretend 026 ran back when files were named sql/migrate_<name>.sql.
    $row = DB::run(
        "SELECT checksum FROM schema_migrations WHERE filename = 'sql/migrations/026_user_settings_telegram.sql'"
    )->fetch();
    assertTrue($row !== false, '026 should be recorded after a run');

    DB::run("DELETE FROM schema_migrations WHERE filename = 'sql/migrations/026_user_settings_telegram.sql'");
    DB::run(
        "INSERT INTO schema_migrations (filename, checksum) VALUES ('sql/migrate_user_settings_telegram.sql', ?)",
        [$row['checksum']]
    );

    try {
        $output = runMigrate();
        assertStringContains('Recorded sql/migrations/026_user_settings_telegram.sql (applied earlier as sql/migrate_user_settings_telegram.sql)', $output, $output);
        assertFalse(str_contains($output, 'Applying sql/migrations/026'), 'it must not run again');
    } finally {
        DB::run("DELETE FROM schema_migrations WHERE filename = 'sql/migrate_user_settings_telegram.sql'");
    }
});

test('the application code never changes the schema itself', function (TestClient $_c): void {
    // Tables and columns used to be created on the fly by whichever request got
    // there first. That is migrate.php's job now.
    $sources = array_merge(
        glob(ROOT . '/models/*.php'), glob(ROOT . '/controllers/*.php'),
        glob(ROOT . '/core/*.php'), [ROOT . '/cron.php', ROOT . '/public/index.php']
    );
    $offenders = [];
    foreach ($sources as $file) {
        if (preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b|sql\/(migrations\/|migrate_|schema\.sql)/i', file_get_contents($file))) {
            $offenders[] = basename($file);
        }
    }
    assertSame([], $offenders);
});
