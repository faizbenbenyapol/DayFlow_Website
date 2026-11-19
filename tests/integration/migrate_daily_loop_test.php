<?php
// =====================================================
// tests/integration/migrate_daily_loop_test.php
//
// migrate-daily-loop.php --apply must survive deleted source rows:
// when a user is deleted, daily_todos/quick_items cascade away but the
// copy-log rows in legacy_task_copies remain, so a count equality check
// would fail on every subsequent run.
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * รัน scripts/migrate-daily-loop.php --apply จริงใน container (เขียนฐานข้อมูลทดสอบ)
 * คืน [exit code, ข้อความ output รวม stderr]
 *
 * @return array{0: int, 1: string}
 */
function runMigrateScript(): array
{
    exec('php ' . ROOT . '/scripts/migrate-daily-loop.php --apply 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

test('migrate-daily-loop --apply survives deleted source rows', function (TestClient $_c): void {
    $username = 'migrate_test_' . TEST_RUN_ID;
    $email    = 'migrate_test_' . TEST_RUN_ID . '@example.test';

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        [$username, $email, 'notused', 'Migrate Test']
    );
    $userId = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO daily_todos (user_id, todo_date, title) VALUES (?, ?, ?)",
        [$userId, date('Y-m-d'), 'daily ' . TEST_RUN_ID]
    );
    $dailyId = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO quick_items (user_id, content) VALUES (?, ?)",
        [$userId, 'quick ' . TEST_RUN_ID]
    );
    $quickId = (int)DB::conn()->lastInsertId();

    try {
        [$code, $output] = runMigrateScript();
        assertSame(0, $code, 'first --apply should succeed: ' . $output);
        assertStringContains('Copied daily_todo:', $output, 'first apply reports daily_todo count');
        assertStringContains('Copied quick_item:', $output, 'first apply reports quick_item count');
        assertTrue(preg_match('/Copied daily_todo: \d+ \/ \d+/', $output) === 1, 'first apply reports daily_todo count in new format');

        $copiedTask = DB::run(
            "SELECT id FROM tasks WHERE legacy_source = 'daily_todo' AND legacy_id = ?",
            [$dailyId]
        )->fetch();
        assertTrue($copiedTask !== false, 'daily todo should be copied to tasks before user deletion');

        DB::run('DELETE FROM users WHERE id = ?', [$userId]);

        [$code2, $output2] = runMigrateScript();
        assertSame(0, $code2, 'second --apply should succeed after source rows are deleted: ' . $output2);
        assertFalse(str_contains(strtolower($output2), 'mismatch'), 'output should not contain mismatch after fix');
        assertFalse(str_contains(strtolower($output2), 'failed'), 'output should not contain failed after fix');
    } finally {
        DB::run("DELETE FROM legacy_task_copies WHERE (legacy_source = 'daily_todo' AND legacy_id = ?) OR (legacy_source = 'quick_item' AND legacy_id = ?)", [$dailyId, $quickId]);
        DB::run('DELETE FROM users WHERE id = ?', [$userId]);
    }
});
