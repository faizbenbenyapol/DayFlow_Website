<?php
// =====================================================
// scripts/migrate-daily-loop.php
//
// คัดลอกข้อมูลจาก daily_todos และ quick_items ไปยัง tasks
// ใน step 1 ของ daily-loop refactor โดยไม่แตะต้องตารางเก่า
//
//   php scripts/migrate-daily-loop.php           แสดงจำนวน (--dry-run)
//   php scripts/migrate-daily-loop.php --apply   คัดลอกจริง
//   php scripts/migrate-daily-loop.php --rollback [--yes]  ย้อนกลับ
// =====================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/models/LegacyTaskMapper.php';

const BATCH_SIZE = 500;

$args = array_slice($_SERVER['argv'] ?? [], 1);

$dryRun   = in_array('--dry-run', $args, true);
$apply    = in_array('--apply', $args, true);
$rollback = in_array('--rollback', $args, true);
$yes      = in_array('--yes', $args, true);

$known = ['--dry-run', '--apply', '--rollback', '--yes'];
foreach ($args as $arg) {
    if (!in_array($arg, $known, true)) {
        usage();
    }
}

$modeCount = (int)$dryRun + (int)$apply + (int)$rollback;
if ($modeCount > 1 || ($yes && !$rollback)) {
    usage();
}

if ($modeCount === 0) {
    $dryRun = true;
}

if ($dryRun) {
    dryRun();
} elseif ($apply) {
    apply();
} else {
    rollback($yes);
}

/**
 * แสดงวิธีใช้งาน script แล้วออกด้วยรหัส 2
 */
function usage(): void
{
    fwrite(STDERR, "Usage: php scripts/migrate-daily-loop.php [--dry-run|--apply|--rollback] [--yes]\n");
    exit(2);
}

/**
 * แสดงสรุปจำนวนแถวที่จะย้าย โดยไม่เขียนฐานข้อมูล
 */
function dryRun(): void
{
    $daily = dryRunCounts(
        'daily_todos',
        'daily_todo',
        "SUM(CASE WHEN lc.legacy_id IS NULL AND TRIM(COALESCE(dt.title, '')) = '' THEN 1 ELSE 0 END) AS empty_title",
        ''
    );

    $quick = dryRunCounts(
        'quick_items',
        'quick_item',
        "SUM(CASE WHEN lc.legacy_id IS NULL AND TRIM(COALESCE(qi.content, '')) = '' THEN 1 ELSE 0 END) AS empty_title",
        "SUM(CASE WHEN lc.legacy_id IS NULL AND (CHAR_LENGTH(TRIM(COALESCE(qi.content, ''))) > 255 OR TRIM(COALESCE(qi.content, '')) REGEXP '[\r\n]') THEN 1 ELSE 0 END) AS in_description"
    );

    echo "Daily todos\n";
    echo "  total rows: {$daily['total']}\n";
    echo "  not yet copied: {$daily['not_copied']}\n";
    echo "  empty titles: {$daily['empty_title']}\n";
    echo "  distinct users: {$daily['users']}\n";

    echo "\nQuick items\n";
    echo "  total rows: {$quick['total']}\n";
    echo "  not yet copied: {$quick['not_copied']}\n";
    echo "  empty titles: {$quick['empty_title']}\n";
    echo "  full text in description: {$quick['in_description']}\n";
    echo "  distinct users: {$quick['users']}\n";
}

/**
 * นับจำนวนแถวสำหรับ dry-run ด้วย aggregate query หนึ่งคำสั่งต่อตาราง
 *
 * @param string $sourceTable  ชื่อตารางต้นทาง
 * @param string $legacySource ค่า legacy_source ที่ใช้เชื่อม
 * @param string $emptySql     นิพจน์ SQL สำหรับนับแถวที่ชื่อว่าง
 * @param string $extraSql     นิพจน์ SQL เพิ่มเติมสำหรับ quick_items
 * @return array<string, int>  จำนวน total, not_copied, empty_title, in_description, users
 */
function dryRunCounts(string $sourceTable, string $legacySource, string $emptySql, string $extraSql): array
{
    $alias = $sourceTable === 'daily_todos' ? 'dt' : 'qi';
    $extra = $extraSql !== '' ? ", {$extraSql}" : '';

    $sql = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN lc.legacy_id IS NULL THEN 1 ELSE 0 END) AS not_copied,
        {$emptySql}{$extra},
        COUNT(DISTINCT CASE WHEN lc.legacy_id IS NULL THEN {$alias}.user_id END) AS users
    FROM {$sourceTable} {$alias}
    LEFT JOIN legacy_task_copies lc ON lc.legacy_source = '{$legacySource}' AND lc.legacy_id = {$alias}.id";

    $row = DB::run($sql)->fetch() ?: [];

    return [
        'total'          => (int)($row['total'] ?? 0),
        'not_copied'     => (int)($row['not_copied'] ?? 0),
        'empty_title'    => (int)($row['empty_title'] ?? 0),
        'in_description' => (int)($row['in_description'] ?? 0),
        'users'          => (int)($row['users'] ?? 0),
    ];
}

/**
 * คัดลอกแถวที่ยังไม่ถูกคัดลอกจาก daily_todos และ quick_items ไปยัง tasks
 * พร้อมบันทึกลง legacy_task_copies ใช้ transaction เดียว และตรวจสอบความถูกต้องก่อน commit
 */
function apply(): void
{
    $db = DB::conn();
    $db->beginTransaction();

    try {
        copySource('daily_todos', 'daily_todo', LegacyTaskMapper::fromDailyTodo(...));
        copySource('quick_items', 'quick_item', LegacyTaskMapper::fromQuickItem(...));

        $dailySource = (int)DB::run('SELECT COUNT(*) FROM daily_todos')->fetchColumn();
        $dailyCopied = (int)DB::run("SELECT COUNT(*) FROM legacy_task_copies WHERE legacy_source = 'daily_todo'")->fetchColumn();

        $quickSource = (int)DB::run('SELECT COUNT(*) FROM quick_items')->fetchColumn();
        $quickCopied = (int)DB::run("SELECT COUNT(*) FROM legacy_task_copies WHERE legacy_source = 'quick_item'")->fetchColumn();

        if ($dailySource !== $dailyCopied) {
            throw new RuntimeException("daily_todos mismatch: source {$dailySource} rows, copy log {$dailyCopied}");
        }
        if ($quickSource !== $quickCopied) {
            throw new RuntimeException("quick_items mismatch: source {$quickSource} rows, copy log {$quickCopied}");
        }

        $db->commit();

        echo "Copied daily_todo: {$dailyCopied} / {$dailySource}\n";
        echo "Copied quick_item: {$quickCopied} / {$quickSource}\n";
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "Apply failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

/**
 * คัดลอกแถวจากตารางต้นทางหนึ่งตาราง โดยแบ่ง insert เป็นชุดละ ~500 แถว
 * พร้อมบันทึกลง legacy_task_copies ว่าแถวต้นทางถูกคัดลอกแล้ว
 *
 * @param string $sourceTable ชื่อตารางต้นทาง
 * @param string $legacySource ค่า legacy_source ที่ใช้เชื่อมและบันทึก
 * @param callable(array<string, mixed>): array<string, mixed> $mapper ฟังก์ชันแปลงแถว
 * @return int จำนวนแถวที่ insert ลง tasks
 */
function copySource(string $sourceTable, string $legacySource, callable $mapper): int
{
    $alias = $sourceTable === 'daily_todos' ? 'dt' : 'qi';
    $rows = DB::run(
        "SELECT {$alias}.* FROM {$sourceTable} {$alias}
         LEFT JOIN legacy_task_copies lc ON lc.legacy_source = '{$legacySource}' AND lc.legacy_id = {$alias}.id
         WHERE lc.legacy_id IS NULL"
    )->fetchAll();

    if ($rows === []) {
        return 0;
    }

    $mapped = array_map($mapper, $rows);
    $columns = array_keys($mapped[0]);
    $total = 0;

    foreach (array_chunk($mapped, BATCH_SIZE) as $chunk) {
        $total += insertBatch('tasks', $columns, $chunk);
        insertCopyLogBatch($legacySource, $chunk);
    }

    return $total;
}

/**
 * สร้างและรัน multi-row INSERT พร้อม ON DUPLICATE KEY UPDATE id = id
 *
 * @param string $table ชื่อตารางปลายทาง
 * @param array<int, string> $columns รายชื่อคอลัมน์
 * @param array<int, array<string, mixed>> $rows แถวที่จะ insert
 * @return int จำนวนแถวที่ insert สำเร็จ
 */
function insertBatch(string $table, array $columns, array $rows): int
{
    $placeholders = [];
    $params = [];

    foreach ($rows as $row) {
        $rowPlaceholders = [];
        foreach ($columns as $col) {
            $params[] = $row[$col] ?? null;
            $rowPlaceholders[] = '?';
        }
        $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
    }

    $colList = '`' . implode('`, `', $columns) . '`';
    $values = implode(', ', $placeholders);
    $updates = array_map(static fn(string $c): string => "`{$c}` = `{$c}`", $columns);
    $updateSql = implode(', ', $updates);

    $sql = "INSERT INTO `{$table}` ({$colList}) VALUES {$values} ON DUPLICATE KEY UPDATE {$updateSql}";

    return DB::run($sql, $params)->rowCount();
}

/**
 * บันทึกลง legacy_task_copies ว่าแถวต้นทางถูกคัดลอกแล้ว
 *
 * @param string $legacySource ค่า legacy_source
 * @param array<int, array<string, mixed>> $rows แถว tasks ที่เพิ่ง insert
 */
function insertCopyLogBatch(string $legacySource, array $rows): void
{
    $placeholders = [];
    $params = [];

    foreach ($rows as $row) {
        $placeholders[] = '(?, ?)';
        $params[] = $legacySource;
        $params[] = (int)$row['legacy_id'];
    }

    $sql = 'INSERT INTO `legacy_task_copies` (`legacy_source`, `legacy_id`) VALUES '
         . implode(', ', $placeholders)
         . ' ON DUPLICATE KEY UPDATE `legacy_id` = `legacy_id`';

    DB::run($sql, $params);
}

/**
 * ย้อนกลับการย้ายข้อมูล โดยลบ tasks ที่มี legacy_source, ลบบันทึกใน legacy_task_copies
 * และปรับ quadrant เป็น 2 ถ้าไม่มี --yes จะแสดงจำนวนแล้วหยุด
 *
 * @param bool $confirmed true เมื่อมี --yes
 */
function rollback(bool $confirmed): void
{
    $toDelete = (int)DB::run('SELECT COUNT(*) FROM tasks WHERE legacy_source IS NOT NULL')->fetchColumn();
    $nullQuadrant = (int)DB::run('SELECT COUNT(*) FROM tasks WHERE quadrant IS NULL')->fetchColumn();

    if (!$confirmed) {
        echo "Would delete copied tasks: {$toDelete}\n";
        echo "Tasks with NULL quadrant: {$nullQuadrant}\n";
        echo "Run with --yes to perform rollback.\n";
        return;
    }

    $db = DB::conn();
    $db->beginTransaction();

    try {
        $deleted = DB::run('DELETE FROM tasks WHERE legacy_source IS NOT NULL')->rowCount();
        $logDeleted = DB::run('DELETE FROM legacy_task_copies')->rowCount();
        $updated = DB::run('UPDATE tasks SET quadrant = 2 WHERE quadrant IS NULL')->rowCount();
        $db->commit();

        echo "Deleted copied tasks: {$deleted}\n";
        echo "Deleted copy-log rows: {$logDeleted}\n";
        echo "Set NULL quadrant to 2: {$updated}\n";
        echo "Warning: edits made to copied tasks are lost.\n";
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "Rollback failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}
