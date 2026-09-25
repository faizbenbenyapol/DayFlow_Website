<?php
// CLI-only: the web server must never run this, even if it is reachable.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}
// DayFlow CLI migration runner.
//
//   php scripts/migrate.php            apply everything still pending
//   php scripts/migrate.php --status   list what has and has not been applied
//   php scripts/migrate.php --force    re-apply everything, tracking aside
//
// Migrations live in sql/migrations/ as NNN_name.sql and run in filename order,
// so adding one is dropping in the next number — there is no list to keep in
// sync. Every migration is written to be safe to run again. Applied files are
// tracked in schema_migrations with a checksum, which also surfaces a
// migration that was edited after it shipped.

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';

$files = array_map(
    fn(string $path): string => 'sql/migrations/' . basename($path),
    glob(ROOT . '/sql/migrations/*.sql') ?: []
);
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No migrations found in sql/migrations/.\n");
    exit(1);
}

/**
 * The checksum ignores line endings: the same file checked out on Windows
 * (CRLF) and on the server (LF) must not look "changed since it was applied".
 */
function migrationChecksum(string $relative): string
{
    return hash('sha256', str_replace("\r\n", "\n", (string)file_get_contents(ROOT . '/' . $relative)));
}

/** Whether a stored checksum describes this file, whichever way it was hashed. */
function sameMigration(string $stored, string $relative): bool
{
    return hash_equals($stored, migrationChecksum($relative))
        || hash_equals($stored, hash_file('sha256', ROOT . '/' . $relative));
}

/**
 * The name the file had before migrations were numbered (sql/schema.sql,
 * sql/migrate_<name>.sql). Databases migrated back then recorded those names.
 */
function legacyName(string $relative): string
{
    $name = preg_replace('/^\d+_/', '', basename($relative, '.sql'));
    return $name === 'schema' ? 'sql/schema.sql' : 'sql/migrate_' . $name . '.sql';
}

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

$record = DB::conn()->prepare(
    'INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = CURRENT_TIMESTAMP'
);

// Carry a record over from the old file name, so an unchanged migration that
// already ran under that name is not run again after the rename.
foreach ($files as $relative) {
    $legacy = legacyName($relative);
    if (isset($applied[$relative]) || !isset($applied[$legacy])) continue;
    if (!sameMigration($applied[$legacy]['checksum'], $relative)) continue;

    $record->execute([$relative, migrationChecksum($relative)]);
    $applied[$relative] = ['checksum' => migrationChecksum($relative), 'applied_at' => $applied[$legacy]['applied_at']];
    if (!$statusOnly) echo "Recorded {$relative} (applied earlier as {$legacy})\n";
}

if ($statusOnly) {
    foreach ($files as $relative) {
        if (!isset($applied[$relative])) {
            printf("%-45s %s\n", $relative, 'PENDING');
        } elseif (!sameMigration($applied[$relative]['checksum'], $relative)) {
            printf("%-45s %s\n", $relative, 'CHANGED since it was applied on ' . $applied[$relative]['applied_at']);
        } else {
            printf("%-45s %s\n", $relative, 'applied ' . $applied[$relative]['applied_at']);
        }
    }
    exit(0);
}

$ran = 0;
foreach ($files as $relative) {
    $file     = ROOT . '/' . $relative;
    $checksum = migrationChecksum($relative);

    if (!$force && isset($applied[$relative])) {
        if (sameMigration($applied[$relative]['checksum'], $relative)) {
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
