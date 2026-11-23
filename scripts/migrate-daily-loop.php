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

    $assignees = projectTaskAssigneeCounts();
    echo "\nProject task assignees\n";
    echo "  matched: {$assignees['matched']}\n";
    echo "  ambiguous: {$assignees['ambiguous']}\n";
    echo "  guest: {$assignees['guest']}\n";
    echo "  unmatched: {$assignees['unmatched']}\n";
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
 * พร้อมบันทึกลง legacy_task_copies ใช้ transaction เดียว
 *
 * ตรวจสอบโดยนับแถวต้นทางที่ยังไม่มี copy-log (not_copied) แทนการเทียบ COUNT(*)
 * เพราะเมื่อผู้ใช้ถูกลบ แถว daily_todos/quick_items จะ cascade หายไป
 * แต่ legacy_task_copies ไม่มี foreign key ทำให้บันทึกการคัดลอกค้างอยู่
 *
 * จำนวนที่รายงานคือแถวต้นทางปัจจุบันที่มีแถว tasks ตรงกัน (JOIN) ไม่ใช่จำนวนที่ copy
 * เพราะผู้ใช้อาจลบงานที่คัดลอกไปแล้วภายหลัง ทำให้ตัวเลขต่ำกว่าจำนวนต้นทางปัจจุบัน
 */
function apply(): void
{
    $db = DB::conn();
    $db->beginTransaction();

    try {
        copySource('daily_todos', 'daily_todo', LegacyTaskMapper::fromDailyTodo(...));
        copySource('quick_items', 'quick_item', LegacyTaskMapper::fromQuickItem(...));

        $dailyNotCopied = dryRunCounts('daily_todos', 'daily_todo', '0', '');
        $quickNotCopied = dryRunCounts('quick_items', 'quick_item', '0', '');

        if ($dailyNotCopied['not_copied'] > 0) {
            throw new RuntimeException("daily_todos mismatch: {$dailyNotCopied['not_copied']} source rows without copy log");
        }
        if ($quickNotCopied['not_copied'] > 0) {
            throw new RuntimeException("quick_items mismatch: {$quickNotCopied['not_copied']} source rows without copy log");
        }

        $daily = copiedCounts('daily_todos', 'daily_todo');
        $quick = copiedCounts('quick_items', 'quick_item');

        $linked = linkProjectTaskAssignees();

        $db->commit();

        echo reportLine('daily_todo', $daily['copied'], $daily['total']) . "\n";
        echo reportLine('quick_item', $quick['copied'], $quick['total']) . "\n";
        echo "Linked project task assignees: {$linked}\n";
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "Apply failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

/**
 * นับแถวต้นทางปัจจุบันที่มีแถว tasks ตรงกัน
 *
 * @param string $sourceTable  ชื่อตารางต้นทาง
 * @param string $legacySource ค่า legacy_source ที่ใช้เชื่อม
 * @return array<string, int>  จำนวน total (แถวต้นทาง) และ copied (มีแถว tasks ตรงกัน)
 */
function copiedCounts(string $sourceTable, string $legacySource): array
{
    $alias = $sourceTable === 'daily_todos' ? 'dt' : 'qi';

    $sql = "SELECT
        COUNT(*) AS total,
        COUNT(t.id) AS copied
    FROM {$sourceTable} {$alias}
    LEFT JOIN tasks t ON t.legacy_source = '{$legacySource}' AND t.legacy_id = {$alias}.id";

    $row = DB::run($sql)->fetch() ?: [];

    return [
        'total' => (int)($row['total'] ?? 0),
        'copied' => (int)($row['copied'] ?? 0),
    ];
}

/**
 * สร้างข้อความรายงานจำนวนที่คัดลอก พร้อมบอกจำนวนที่ถูกลบหลังคัดลอกถ้ามี
 *
 * @param string $label  ชื่อแหล่งข้อมูล
 * @param int $copied    จำนวนต้นทางที่มีแถว tasks ตรงกัน
 * @param int $total     จำนวนต้นทางทั้งหมด
 * @return string ข้อความรายงาน
 */
function reportLine(string $label, int $copied, int $total): string
{
    $line = "Copied {$label}: {$copied} / {$total}";
    if ($copied < $total) {
        $deleted = $total - $copied;
        $line .= " ({$deleted} deleted from tasks since copied)";
    }
    return $line;
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
 * นิพจน์ SQL ที่ใช้เทียบชื่อ: เปลี่ยน NBSP เป็นช่องว่าง ตัดช่องว่างหัวท้าย ทำตัวเล็ก แล้วเทียบแบบ binary
 * TRIM ของ MariaDB ตัดแค่ช่องว่างธรรมดา ชื่อที่คัดลอกจากเว็บมักมี NBSP (U+00A0) ติดมา
 * ส่วน utf8mb4_bin มีไว้ไม่ให้มองข้ามวรรณยุกต์ไทยแบบ utf8mb4_unicode_ci
 *
 * @param string $column ชื่อคอลัมน์ใน SQL เช่น t.assignee
 * @return string นิพจน์ SQL
 */
function nameKeySql(string $column): string
{
    return "LOWER(TRIM(REPLACE({$column}, _utf8mb4 X'C2A0', ' '))) COLLATE utf8mb4_bin";
}

/**
 * สร้าง derived table ของ guest_name ที่ปรากฏในโปรเจคนั้น (จาก task/chat/activity)
 *
 * @return string SQL ของ derived table มีคอลัมน์ project_id, guest_name (เป็นค่าจาก nameKeySql แล้ว)
 */
function guestNamesSql(): string
{
    $key = nameKeySql('guest_name');
    return "SELECT DISTINCT project_id, {$key} AS guest_name
        FROM (
            SELECT project_id, guest_name COLLATE utf8mb4_bin AS guest_name FROM project_tasks
            UNION ALL
            SELECT project_id, guest_name COLLATE utf8mb4_bin AS guest_name FROM project_chats
            UNION ALL
            SELECT project_id, guest_name COLLATE utf8mb4_bin AS guest_name FROM project_activities
        ) g
        WHERE guest_name IS NOT NULL AND {$key} <> ''";
}

/**
 * สร้าง derived table ที่จับคู่ assignee ข้อความกับสมาชิกโปรเจคแบบ one-to-many
 *
 * @return string SQL ของ derived table มีคอลัมน์ id, user_id
 */
function matchedProjectTasksSql(): string
{
    $membersSql = '(
        SELECT p.id AS project_id, u.id AS user_id, u.display_name, u.username
        FROM projects p JOIN users u ON p.user_id = u.id
        UNION ALL
        SELECT pm.project_id, u.id, u.display_name, u.username
        FROM project_members pm JOIN users u ON pm.user_id = u.id
    )';

    $guestSql = guestNamesSql();
    $assignee = nameKeySql('t.assignee');

    return "SELECT t.id, mem.user_id
        FROM project_tasks t
        JOIN {$membersSql} mem ON mem.project_id = t.project_id
        WHERE t.assignee_user_id IS NULL
          AND {$assignee} <> ''
          AND ({$assignee} = " . nameKeySql('mem.display_name') . " OR {$assignee} = " . nameKeySql('mem.username') . ")
          AND NOT EXISTS (
              SELECT 1 FROM ({$guestSql}) g
              WHERE g.project_id = t.project_id AND g.guest_name = {$assignee}
          )";
}

/**
 * นับจำนวนงานโปรเจคที่ยังผูก assignee จากข้อความ และแยกสถานะการจับคู่
 *
 * @return array<string, int> จำนวน matched, ambiguous, guest, unmatched
 */
function projectTaskAssigneeCounts(): array
{
    $matchedSql = matchedProjectTasksSql();
    $guestSql = guestNamesSql();
    $assignee = nameKeySql('pt.assignee');

    $sql = "SELECT
        SUM(CASE WHEN gn.project_id IS NOT NULL THEN 1 ELSE 0 END) AS guest,
        SUM(CASE WHEN gn.project_id IS NULL AND m.match_count = 1 THEN 1 ELSE 0 END) AS matched,
        SUM(CASE WHEN gn.project_id IS NULL AND m.match_count > 1 THEN 1 ELSE 0 END) AS ambiguous,
        SUM(CASE WHEN gn.project_id IS NULL AND m.match_count IS NULL THEN 1 ELSE 0 END) AS unmatched
    FROM project_tasks pt
    LEFT JOIN (
        SELECT id, COUNT(DISTINCT user_id) AS match_count
        FROM ({$matchedSql}) mm
        GROUP BY id
    ) m ON m.id = pt.id
    LEFT JOIN ({$guestSql}) gn
        ON gn.project_id = pt.project_id
        AND gn.guest_name = {$assignee}
    WHERE pt.assignee_user_id IS NULL AND {$assignee} <> ''";

    $row = DB::run($sql)->fetch() ?: [];

    return [
        'matched'   => (int)($row['matched'] ?? 0),
        'ambiguous' => (int)($row['ambiguous'] ?? 0),
        'guest'     => (int)($row['guest'] ?? 0),
        'unmatched' => (int)($row['unmatched'] ?? 0),
    ];
}

/**
 * จับคู่ assignee ข้อความของงานโปรเจคกับสมาชิกโปรเจค แล้วบันทึก assignee_user_id
 *
 * @return int จำนวนงานที่ถูกผูก
 */
function linkProjectTaskAssignees(): int
{
    $matchedSql = matchedProjectTasksSql();

    $sql = "UPDATE project_tasks pt
    JOIN (
        SELECT id, MAX(user_id) AS user_id
        FROM ({$matchedSql}) mm
        GROUP BY id
        HAVING COUNT(DISTINCT user_id) = 1
    ) matched ON matched.id = pt.id
    SET pt.assignee_user_id = matched.user_id";

    return DB::run($sql)->rowCount();
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
        echo "Project task assignee links are left as-is; the old app ignores the column.\n";
        echo "Warning: edits made to copied tasks are lost.\n";
        echo "Warning: tasks created after the move stay in Tasks (inbox ones moved to quadrant 2) and do not appear in the old Planner.\n";
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "Rollback failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}
