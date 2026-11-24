<?php
// =====================================================
// tests/integration/demo_reset_test.php
//
// The shared demo account is written to by every visitor. The nightly reset
// has to put back exactly what the owner set up — and must never touch a real
// account. Runs next to the database, inside the app container.
// =====================================================

declare(strict_types=1);

function demoScript(string $args = ''): string
{
    return (string)shell_exec(PHP_BINARY . ' ' . escapeshellarg(ROOT . '/scripts/demo-reset.php') . ' ' . $args . ' 2>&1');
}

function demoTaskTitles(int $userId): array
{
    $titles = DB::run('SELECT title FROM tasks WHERE user_id = ?', [$userId])->fetchAll(PDO::FETCH_COLUMN);
    sort($titles, SORT_STRING);   // not the database's collation: that is not what is under test
    return $titles;
}

/**
 * Runs $body with a temporary demo account and a clean snapshot slot, and
 * always puts the database and the snapshot file back as they were.
 * Tests run against a throwaway database, never a live one.
 */
function withDemoAccount(callable $body): void
{
    require_once ROOT . '/config/database.php';
    require_once ROOT . '/models/User.php';
    require_once ROOT . '/models/AccountData.php';
    require_once ROOT . '/core/DemoReset.php';

    // Only one account may be the demo. A database that already has one
    // (migration 018 flags a user named 'demo') hands it back afterwards.
    $existingDemos = DB::run('SELECT id FROM users WHERE is_demo = 1')->fetchAll(PDO::FETCH_COLUMN);
    DB::run('UPDATE users SET is_demo = 0 WHERE is_demo = 1');

    $path   = DemoReset::snapshotPath();
    $backup = is_file($path) ? file_get_contents($path) : null;
    @unlink($path);

    $demoId  = User::create('demo_t_' . TEST_RUN_ID, 'demo_t_' . TEST_RUN_ID . '@example.test', 'TestPass123!');
    $otherId = User::create('real_t_' . TEST_RUN_ID, 'real_t_' . TEST_RUN_ID . '@example.test', 'TestPass123!');
    DB::run('UPDATE users SET is_demo = 1 WHERE id = ?', [$demoId]);

    try {
        $body($demoId, $otherId);
    } finally {
        DB::run('DELETE FROM users WHERE id IN (?, ?)', [$demoId, $otherId]);
        foreach ($existingDemos as $id) DB::run('UPDATE users SET is_demo = 1 WHERE id = ?', [$id]);
        @unlink($path);
        if ($backup !== null) file_put_contents($path, $backup);
    }
}

test('the demo account returns to its snapshot and nobody else is touched', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $otherId): void {
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$demoId, 'ต้นแบบ']);
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$otherId, 'ของคนจริง']);

        assertStringContains('บันทึก snapshot แล้ว', demoScript('--snapshot'));

        // What visitors do in a day: add junk, delete the sample.
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$demoId, 'ขยะจากผู้เยี่ยมชม']);
        DB::run('DELETE FROM tasks WHERE user_id = ? AND title = ?', [$demoId, 'ต้นแบบ']);
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$otherId, 'งานใหม่ของคนจริง']);

        assertStringContains('รีเซ็ตบัญชี demo แล้ว', demoScript());

        assertSame(['ต้นแบบ'], demoTaskTitles($demoId), 'the demo is back to the sample data');
        assertSame(['ของคนจริง', 'งานใหม่ของคนจริง'], demoTaskTitles($otherId), 'a real account is never reset');
    });
});

test('projects visitors made are cleared and the sample projects come back whole', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $otherId): void {
        DB::run("INSERT INTO projects (user_id, name) VALUES (?, 'โปรเจกต์ตัวอย่าง')", [$demoId]);
        $sampleId = (int)DB::conn()->lastInsertId();
        DB::run("INSERT INTO project_columns (project_id, name, grp, position) VALUES (?, 'ต้องทำ', 'todo', 0)", [$sampleId]);
        $sampleColumnId = (int)DB::conn()->lastInsertId();
        DB::run("INSERT INTO project_columns (project_id, name, grp, position) VALUES (?, 'กำลังทำ', 'doing', 1)", [$sampleId]);
        DB::run("INSERT INTO project_tasks (project_id, user_id, column_id, title, status) VALUES (?, ?, ?, 'งานในโปรเจกต์', 'To Do')", [$sampleId, $demoId, $sampleColumnId]);
        DB::run("INSERT INTO project_chats (project_id, user_id, message) VALUES (?, ?, 'สวัสดี')", [$sampleId, $demoId]);
        DB::run("INSERT INTO projects (user_id, name) VALUES (?, 'ของคนจริง')", [$otherId]);
        $realId = (int)DB::conn()->lastInsertId();

        assertStringContains('บันทึก snapshot แล้ว', demoScript('--snapshot'));

        // A day of visitors: a new project, the sample renamed and emptied.
        DB::run("INSERT INTO projects (user_id, name) VALUES (?, 'ขยะจากผู้เยี่ยมชม')", [$demoId]);
        DB::run("UPDATE projects SET name = 'ถูกแก้' WHERE id = ?", [$sampleId]);
        DB::run('DELETE FROM project_tasks WHERE project_id = ?', [$sampleId]);

        assertStringContains('รีเซ็ตบัญชี demo แล้ว', demoScript());

        $projects = DB::run('SELECT id, name FROM projects WHERE user_id = ?', [$demoId])->fetchAll();
        assertSame(['โปรเจกต์ตัวอย่าง'], array_column($projects, 'name'), 'only the sample project is left');
        $restoredId = (int)$projects[0]['id'];

        $task = DB::run('SELECT title, status, column_id FROM project_tasks WHERE project_id = ?', [$restoredId])->fetch();
        assertSame('งานในโปรเจกต์', $task['title'], 'its tasks are back inside it');
        assertSame('To Do', $task['status'], 'the status comes back with the task');

        // The restored task must point at a column of the restored project.
        $columnProject = DB::run('SELECT project_id FROM project_columns WHERE id = ?', [(int)$task['column_id']])->fetchColumn();
        assertSame($restoredId, (int)$columnProject, 'the task points at a column of the restored project');
        assertTrue((int)$task['column_id'] !== $sampleColumnId, 'the task no longer points at the old column id');
        assertSame(['ต้องทำ', 'กำลังทำ'], DB::run('SELECT name FROM project_columns WHERE project_id = ? ORDER BY position', [$restoredId])->fetchAll(PDO::FETCH_COLUMN));

        assertSame(['สวัสดี'], DB::run('SELECT message FROM project_chats WHERE project_id = ?', [$restoredId])->fetchAll(PDO::FETCH_COLUMN));
        assertSame('ของคนจริง', DB::run('SELECT name FROM projects WHERE id = ?', [$realId])->fetchColumn(), 'a real project is never touched');
    });
});

test('a snapshot from before projects were covered leaves the projects alone', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $_otherId): void {
        demoScript('--snapshot');
        $data = json_decode((string)file_get_contents(DemoReset::snapshotPath()), true);
        unset($data['demo_projects']);
        file_put_contents(DemoReset::snapshotPath(), json_encode($data));

        DB::run("INSERT INTO projects (user_id, name) VALUES (?, 'ต้องอยู่ต่อ')", [$demoId]);
        demoScript();
        assertSame(['ต้องอยู่ต่อ'], DB::run('SELECT name FROM projects WHERE user_id = ?', [$demoId])->fetchAll(PDO::FETCH_COLUMN));
    });
});

test('a snapshot taken before board columns existed comes back with default columns', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $_otherId): void {
        // snapshot บน production ถ่ายด้วยโค้ดก่อน migration 038: ไม่มี project_columns และงานไม่มี column_id
        DB::run("INSERT INTO projects (user_id, name, type) VALUES (?, 'โปรเจกต์ก่อนมีคอลัมน์', 'software')", [$demoId]);
        $sampleId = (int)DB::conn()->lastInsertId();
        foreach (['To Do', 'In Progress', 'Review', 'Done'] as $status) {
            DB::run('INSERT INTO project_tasks (project_id, user_id, title, status) VALUES (?, ?, ?, ?)', [$sampleId, $demoId, 'งาน ' . $status, $status]);
        }
        demoScript('--snapshot');
        $data = json_decode((string)file_get_contents(DemoReset::snapshotPath()), true);
        unset($data['demo_projects']['project_columns']);
        foreach ($data['demo_projects']['project_tasks'] as &$task) unset($task['column_id']);
        unset($task);
        file_put_contents(DemoReset::snapshotPath(), json_encode($data, JSON_UNESCAPED_UNICODE));

        // demo-reset.php ไม่มี autoloader: ถ้า DemoReset ไม่ require model เอง บรรทัดนี้จะล้ม
        assertStringContains('รีเซ็ตบัญชี demo แล้ว', demoScript());

        $restoredId = (int)DB::run('SELECT id FROM projects WHERE user_id = ?', [$demoId])->fetchColumn();
        $columns = DB::run(
            "SELECT id, name, grp FROM project_columns WHERE project_id = ? ORDER BY FIELD(grp, 'todo', 'doing', 'done'), position, id",
            [$restoredId]
        )->fetchAll();
        assertSame(['ต้องทำ', 'กำลังทำ', 'รอรีวิว', 'ทดสอบ', 'เสร็จ'], array_column($columns, 'name'), 'the software defaults are created');

        // แต่ละสถานะเดิมลงคอลัมน์ตามกฎกลุ่ม และสถานะเป็นเงาของคอลัมน์ (Review = doing คอลัมน์สุดท้าย)
        $want = ['งาน To Do' => ['ต้องทำ', 'To Do'], 'งาน In Progress' => ['กำลังทำ', 'In Progress'],
                 'งาน Review' => ['ทดสอบ', 'Review'], 'งาน Done' => ['เสร็จ', 'Done']];
        $got = DB::run(
            'SELECT t.title, c.name, t.status FROM project_tasks t JOIN project_columns c ON c.id = t.column_id AND c.project_id = t.project_id
             WHERE t.project_id = ?',
            [$restoredId]
        )->fetchAll();
        assertSame(4, count($got), 'every task has a column of the restored project');
        foreach ($got as $row) {
            assertSame($want[$row['title']], [$row['name'], $row['status']], $row['title']);
        }
    });
});

test('the snapshot never carries the account row or its password hash', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $_otherId): void {
        demoScript('--snapshot');
        $raw = (string)file_get_contents(DemoReset::snapshotPath());

        assertFalse(str_contains($raw, 'password_hash'), 'no credentials in the file');
        assertFalse(isset(json_decode($raw, true)['user']), 'no account row');
    });
});

test('resetting without a snapshot fails loudly and changes nothing', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $_otherId): void {
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$demoId, 'ต้องไม่หาย']);

        $output = demoScript();
        assertStringContains('snapshot', $output);
        assertSame(['ต้องไม่หาย'], demoTaskTitles($demoId), 'no snapshot means no reset');
    });
});

test('a corrupt snapshot is refused rather than wiping the demo', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $_otherId): void {
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$demoId, 'ต้องไม่หาย']);
        file_put_contents(DemoReset::snapshotPath(), '{not json');

        assertStringContains('เสียหาย', demoScript());
        assertSame(['ต้องไม่หาย'], demoTaskTitles($demoId));
    });
});
