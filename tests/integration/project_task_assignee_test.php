<?php
// =====================================================
// tests/integration/project_task_assignee_test.php
//
// ตรวจสอบว่าผู้รับผิดชอบงานโปรเจกต์ผูกกับบัญชีจริง รวมถึงการจับคู่
// จากข้อความเดิมใน migrate-daily-loop.php
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * สร้างโปรเจกต์ตัวอย่างพร้อมเจ้าของ สมาชิก editor และบัญชีภายนอก
 *
 * @return array{owner: TestClient, editor: TestClient, outsider: TestClient, projectId: int, taskId: int, editorId: int, editorUsername: string, outsiderUsername: string}
 */
function assigneeFixture(): array
{
    static $fixture = null;
    if ($fixture !== null) return $fixture;

    $password = 'TestPass123!';
    $run = 'assign_' . TEST_RUN_ID;

    /**
     * สร้างบัญชีให้พร้อมโดยตรงในฐานข้อมูล หลีกเลี่ยง rate limit ของ endpoint ลงทะเบียน
     *
     * @return string username ที่สร้าง
     */
    $makeUser = static function (string $role) use ($password, $run): string {
        $username = 'assign_' . $role . '_' . $run;
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::run(
            "INSERT INTO users (username, email, password_hash, display_name)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)",
            [$username, $username . '@example.test', $hash, $username]
        );
        $userId = (int)DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn();
        DB::run(
            "INSERT INTO user_settings (user_id, theme, timezone) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = user_settings.user_id",
            [$userId, 'light', 'Asia/Bangkok']
        );
        return $username;
    };

    $ownerU = $makeUser('owner');
    $editorU = $makeUser('editor');
    $outsiderU = $makeUser('outsider');

    $owner = new TestClient(TEST_BASE_URL);
    $owner->login($ownerU, $password);

    $editor = new TestClient(TEST_BASE_URL);
    $editor->login($editorU, $password);

    $outsider = new TestClient(TEST_BASE_URL);
    $outsider->login($outsiderU, $password);

    $project = $owner->json($owner->post('/api/projects', [
        'name'     => 'โครงการทดสอบผู้รับผิดชอบ ' . $run,
        'status'   => 'Planning',
        'priority' => 'Medium',
    ]));
    $projectId = (int)($project['id'] ?? ($project['project']['id'] ?? 0));
    if ($projectId < 1) throw new RuntimeException('fixture project not created');

    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $editorU, 'role' => 'Editor']);

    $task = $owner->json($owner->post("/api/projects/{$projectId}/tasks", [
        'title' => 'งานตั้งต้น', 'status' => 'To Do', 'priority' => 'Medium',
    ]));
    $taskId = (int)($task['id'] ?? ($task['task']['id'] ?? 0));

    $members = $owner->json($owner->get("/api/projects/{$projectId}/members"));
    $editorId = 0;
    foreach ($members['members'] ?? [] as $m) {
        if (($m['username'] ?? '') === $editorU) {
            $editorId = (int)$m['id'];
            break;
        }
    }

    return $fixture = [
        'owner'            => $owner,
        'editor'           => $editor,
        'outsider'         => $outsider,
        'projectId'        => $projectId,
        'taskId'           => $taskId,
        'editorId'         => $editorId,
        'editorUsername'   => $editorU,
        'outsiderUsername' => $outsiderU,
    ];
}

/**
 * หา id ผู้ใช้จาก username
 */
function userIdByUsername(string $username): int
{
    $row = DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetch();
    return $row ? (int)$row['id'] : 0;
}

/**
 * หางานจาก API list tasks
 */
function taskFromList(TestClient $client, int $projectId, int $taskId): ?array
{
    $data = $client->json($client->get("/api/projects/{$projectId}/tasks"));
    foreach ($data['tasks'] ?? [] as $t) {
        if ((int)$t['id'] === $taskId) return $t;
    }
    return null;
}

test('assigning a member via API returns assignee_user_id and assignee_name', function (TestClient $_c): void {
    $f = assigneeFixture();

    $res = $f['owner']->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['assignee_user_id' => $f['editorId']]);
    assertSame(200, $res['status'], 'owner should assign task to member: ' . $res['body']);

    $task = taskFromList($f['owner'], $f['projectId'], $f['taskId']);
    assertTrue($task !== null, 'task should exist in list');
    assertSame($f['editorId'], (int)$task['assignee_user_id'], 'assignee_user_id should match editor');
    assertSame($f['editorUsername'], $task['assignee_name'], 'assignee_name should be editor username');
});

test('assigning a non-member returns 422 and unassigning clears both fields', function (TestClient $_c): void {
    $f = assigneeFixture();

    $outsiderId = userIdByUsername($f['outsiderUsername'] ?? '');
    assertTrue($outsiderId > 0, 'outsider account should exist');

    $bad = $f['owner']->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['assignee_user_id' => $outsiderId]);
    assertSame(422, $bad['status'], 'assigning outsider should fail');

    $clear = $f['owner']->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['assignee_user_id' => null]);
    assertSame(200, $clear['status'], 'unassigning should succeed: ' . $clear['body']);

    $task = taskFromList($f['owner'], $f['projectId'], $f['taskId']);
    assertTrue($task !== null, 'task should exist in list');
    assertSame(null, $task['assignee_user_id'] ?? null, 'assignee_user_id should be null');
    assertTrue(!isset($task['assignee_name']) || $task['assignee_name'] === null, 'assignee_name should be absent or null');
});

test('removing a member from the project clears their assignments', function (TestClient $_c): void {
    $f = assigneeFixture();

    $assign = $f['owner']->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['assignee_user_id' => $f['editorId']]);
    assertSame(200, $assign['status'], 'assign before removal should succeed');

    $remove = $f['owner']->request('DELETE', '/api/projects/' . $f['projectId'] . '/members/' . $f['editorId'], []);
    assertSame(200, $remove['status'], 'owner should be able to remove member');

    $task = taskFromList($f['owner'], $f['projectId'], $f['taskId']);
    assertTrue($task !== null, 'task should still exist');
    assertSame(null, $task['assignee_user_id'] ?? null, 'assignee_user_id should be cleared');
    assertTrue(!isset($task['assignee_name']) || $task['assignee_name'] === null, 'assignee_name should be absent or null');
});

test('deleting the assignee account sets assignee_user_id to NULL via FK', function (TestClient $_c): void {
    $f = assigneeFixture();

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['assign_tmp_' . TEST_RUN_ID, 'assign_tmp_' . TEST_RUN_ID . '@example.test', 'notused', 'Tmp User']
    );
    $tmpId = (int)DB::conn()->lastInsertId();

    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$f['projectId'], $tmpId, 'Editor']);

    $assign = $f['owner']->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['assignee_user_id' => $tmpId]);
    assertSame(200, $assign['status'], 'assign to tmp user should succeed');

    DB::run('DELETE FROM users WHERE id = ?', [$tmpId]);

    $task = taskFromList($f['owner'], $f['projectId'], $f['taskId']);
    assertTrue($task !== null, 'task should still exist');
    assertSame(null, $task['assignee_user_id'] ?? null, 'assignee_user_id should be NULL after user deletion');
});

test('legacy assignee text "123" is kept when saved without assignee fields', function (TestClient $_c): void {
    $f = assigneeFixture();

    DB::run(
        'UPDATE project_tasks SET assignee = ?, assignee_user_id = NULL WHERE id = ?',
        ['123', $f['taskId']]
    );

    $res = $f['owner']->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['title' => 'อัปเดตชื่องาน']);
    assertSame(200, $res['status'], 'save without assignee fields should succeed: ' . $res['body']);

    $task = taskFromList($f['owner'], $f['projectId'], $f['taskId']);
    assertTrue($task !== null, 'task should exist in list');
    assertSame('123', $task['assignee'] ?? null, 'legacy text "123" must stay');
    assertTrue(empty($task['assignee_user_id']), 'assignee_user_id must stay null');
});

test('migrate-daily-loop links legacy assignee text to members', function (TestClient $_c): void {
    $run = 'match_' . TEST_RUN_ID;

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['match_owner_' . $run, 'match_owner_' . $run . '@example.test', 'notused', 'Owner']
    );
    $ownerId = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['match_somchai_' . $run, 'match_somchai_' . $run . '@example.test', 'notused', 'Somchai']
    );
    $somchaiId = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['match_dup1_' . $run, 'match_dup1_' . $run . '@example.test', 'notused', 'Dup']
    );
    $dup1Id = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['match_dup2_' . $run, 'match_dup2_' . $run . '@example.test', 'notused', 'Dup']
    );
    $dup2Id = (int)DB::conn()->lastInsertId();

    DB::run(
        'INSERT INTO projects (user_id, name, status, priority) VALUES (?, ?, ?, ?)',
        [$ownerId, 'Match project ' . $run, 'Planning', 'Medium']
    );
    $projectId = (int)DB::conn()->lastInsertId();

    /**
     * แยกตัวเลขจำนวน matched/ambiguous/guest/unmatched จาก output ของสคริปต์
     *
     * @return array<string, int>
     */
    $parseCounts = static function (string $output): array {
        $counts = ['matched' => 0, 'ambiguous' => 0, 'guest' => 0, 'unmatched' => 0];
        foreach (array_keys($counts) as $key) {
            if (preg_match('/' . $key . ': (\d+)/i', $output, $m)) {
                $counts[$key] = (int)$m[1];
            }
        }
        return $counts;
    };

    exec('php ' . ROOT . '/scripts/migrate-daily-loop.php 2>&1', $baseOut, $baseCode);
    $baseOutput = implode("\n", $baseOut);
    assertSame(0, $baseCode, 'baseline dry-run should succeed: ' . $baseOutput);
    $baseCounts = $parseCounts($baseOutput);

    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $somchaiId, 'Editor']);
    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $dup1Id, 'Editor']);
    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $dup2Id, 'Editor']);

    DB::run(
        'INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Somchai task', 'To Do', ' somchai ']
    );
    $somchaiTaskId = (int)DB::conn()->lastInsertId();

    DB::run(
        'INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Dup task', 'To Do', 'Dup']
    );

    DB::run(
        'INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Nobody task', 'To Do', 'Nobody']
    );

    DB::run(
        'INSERT INTO project_tasks (project_id, user_id, title, status, guest_name) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Guest task', 'To Do', 'VisitorGuest']
    );
    DB::run(
        'INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Guest-named task', 'To Do', 'VisitorGuest']
    );

    try {
        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php 2>&1', $dryOut, $dryCode);
        $dryOutput = implode("\n", $dryOut);
        assertSame(0, $dryCode, 'dry-run should succeed: ' . $dryOutput);
        $dryCounts = $parseCounts($dryOutput);
        assertSame(1, $dryCounts['matched'] - $baseCounts['matched'], 'dry-run reports 1 new matched');
        assertSame(1, $dryCounts['ambiguous'] - $baseCounts['ambiguous'], 'dry-run reports 1 new ambiguous');
        assertSame(1, $dryCounts['guest'] - $baseCounts['guest'], 'dry-run reports 1 new guest');
        assertSame(1, $dryCounts['unmatched'] - $baseCounts['unmatched'], 'dry-run reports 1 new unmatched');

        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php --apply 2>&1', $applyOut, $applyCode);
        $applyOutput = implode("\n", $applyOut);
        assertSame(0, $applyCode, 'apply should succeed: ' . $applyOutput);
        assertStringContains('Linked project task assignees:', $applyOutput, 'apply reports linked count');

        $linked = DB::run('SELECT assignee_user_id FROM project_tasks WHERE id = ?', [$somchaiTaskId])->fetch();
        assertSame($somchaiId, (int)$linked['assignee_user_id'], 'Somchai task should be linked');

        $dup = DB::run('SELECT assignee_user_id FROM project_tasks WHERE title = ? AND project_id = ?', ['Dup task', $projectId])->fetch();
        assertTrue($dup['assignee_user_id'] === null, 'Dup task should remain unlinked');

        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php --apply 2>&1', $againOut, $againCode);
        $againOutput = implode("\n", $againOut);
        assertSame(0, $againCode, 'second apply should succeed: ' . $againOutput);
        assertStringContains('Linked project task assignees: 0', $againOutput, 're-running links nothing new');
    } finally {
        DB::run('DELETE FROM project_tasks WHERE project_id = ?', [$projectId]);
        DB::run('DELETE FROM projects WHERE id = ?', [$projectId]);
        DB::run('DELETE FROM users WHERE id IN (?, ?, ?, ?)', [$ownerId, $somchaiId, $dup1Id, $dup2Id]);
        // ลบโปรเจกต์ fixture ที่ assigneeFixture สร้างไว้ ไม่ให้ค้างระหว่างรัน
        $f = assigneeFixture();
        DB::run('DELETE FROM project_tasks WHERE project_id = ?', [$f['projectId']]);
        DB::run('DELETE FROM projects WHERE id = ?', [$f['projectId']]);
    }
});

test('migration respects Thai tone marks and only links exact names', function (TestClient $_c): void {
    $run = 'tone_' . TEST_RUN_ID;

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['tone_owner_' . $run, 'tone_owner_' . $run . '@example.test', 'notused', 'Owner']
    );
    $ownerId = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['tone_somchai_' . $run, 'tone_somchai_' . $run . '@example.test', 'notused', 'สมช้าย']
    );
    $somchaiId = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['tone_bank1_' . $run, 'tone_bank1_' . $run . '@example.test', 'notused', 'แบ๊งค์']
    );
    $bank1Id = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['tone_bank2_' . $run, 'tone_bank2_' . $run . '@example.test', 'notused', 'แบงค์']
    );
    $bank2Id = (int)DB::conn()->lastInsertId();

    DB::run(
        'INSERT INTO projects (user_id, name, status, priority) VALUES (?, ?, ?, ?)',
        [$ownerId, 'Tone project ' . $run, 'Planning', 'Medium']
    );
    $projectId = (int)DB::conn()->lastInsertId();

    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $somchaiId, 'Editor']);
    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $bank1Id, 'Editor']);
    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $bank2Id, 'Editor']);

    DB::run(
        'INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Tone somchai task', 'To Do', 'สมชาย']
    );
    $somchaiTaskId = (int)DB::conn()->lastInsertId();

    DB::run(
        'INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Tone bank task', 'To Do', ' แบ๊งค์ ']
    );
    $bankTaskId = (int)DB::conn()->lastInsertId();

    try {
        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php --apply 2>&1', $applyOut, $applyCode);
        $applyOutput = implode("\n", $applyOut);
        assertSame(0, $applyCode, 'apply should succeed: ' . $applyOutput);

        $somchaiLinked = DB::run('SELECT assignee_user_id FROM project_tasks WHERE id = ?', [$somchaiTaskId])->fetch();
        assertTrue($somchaiLinked['assignee_user_id'] === null, 'สมชาย must not match สมช้าย');

        $bankLinked = DB::run('SELECT assignee_user_id FROM project_tasks WHERE id = ?', [$bankTaskId])->fetch();
        assertSame($bank1Id, (int)$bankLinked['assignee_user_id'], 'แบ๊งค์ must match the แบ๊งค์ member only');
    } finally {
        DB::run('DELETE FROM project_tasks WHERE project_id = ?', [$projectId]);
        DB::run('DELETE FROM projects WHERE id = ?', [$projectId]);
        DB::run('DELETE FROM users WHERE id IN (?, ?, ?, ?)', [$ownerId, $somchaiId, $bank1Id, $bank2Id]);
    }
});

test('migration links a legacy name carrying a non-breaking space, and skips one that is only that space', function (TestClient $_c): void {
    $run = 'nbsp_' . TEST_RUN_ID;
    $nbsp = "\u{00A0}";

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['nbsp_owner_' . $run, 'nbsp_owner_' . $run . '@example.test', 'notused', 'Owner']
    );
    $ownerId = (int)DB::conn()->lastInsertId();
    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['nbsp_somying_' . $run, 'nbsp_somying_' . $run . '@example.test', 'notused', 'สมหญิง']
    );
    $somyingId = (int)DB::conn()->lastInsertId();

    DB::run('INSERT INTO projects (user_id, name, status, priority) VALUES (?, ?, ?, ?)', [$ownerId, 'NBSP project ' . $run, 'Planning', 'Medium']);
    $projectId = (int)DB::conn()->lastInsertId();
    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $somyingId, 'Editor']);

    // ชื่อที่คัดลอกจากเว็บ มี NBSP ติดหัวท้าย
    DB::run('INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'NBSP task', 'To Do', $nbsp . 'สมหญิง' . $nbsp]);
    $nbspTaskId = (int)DB::conn()->lastInsertId();
    DB::run('INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Blank task', 'To Do', $nbsp]);
    $blankTaskId = (int)DB::conn()->lastInsertId();

    try {
        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php 2>&1', $dryOut, $dryCode);
        assertSame(0, $dryCode, 'dry-run should succeed: ' . implode("\n", $dryOut));

        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php --apply 2>&1', $applyOut, $applyCode);
        assertSame(0, $applyCode, 'apply should succeed: ' . implode("\n", $applyOut));

        $linked = DB::run('SELECT assignee_user_id FROM project_tasks WHERE id = ?', [$nbspTaskId])->fetchColumn();
        assertSame($somyingId, (int)$linked, 'a name wrapped in NBSP matches the member');
        $blank = DB::run('SELECT assignee_user_id FROM project_tasks WHERE id = ?', [$blankTaskId])->fetchColumn();
        assertSame(null, $blank, 'an assignee that is only NBSP links to nobody');
    } finally {
        DB::run('DELETE FROM project_tasks WHERE project_id = ?', [$projectId]);
        DB::run('DELETE FROM projects WHERE id = ?', [$projectId]);
        DB::run('DELETE FROM users WHERE id IN (?, ?)', [$ownerId, $somyingId]);
    }
});

test('migration treats a guest name seen only in project_chats as a guest', function (TestClient $_c): void {
    $run = 'chatguest_' . TEST_RUN_ID;

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['cg_owner_' . $run, 'cg_owner_' . $run . '@example.test', 'notused', 'Owner']
    );
    $ownerId = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['cg_member_' . $run, 'cg_member_' . $run . '@example.test', 'notused', 'MemberName']
    );
    $memberId = (int)DB::conn()->lastInsertId();

    DB::run(
        'INSERT INTO projects (user_id, name, status, priority) VALUES (?, ?, ?, ?)',
        [$ownerId, 'Chat guest project ' . $run, 'Planning', 'Medium']
    );
    $projectId = (int)DB::conn()->lastInsertId();

    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $memberId, 'Editor']);

    DB::run(
        'INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Chat guest task', 'To Do', 'MemberName']
    );
    $taskId = (int)DB::conn()->lastInsertId();

    $parseCounts = static function (string $output): array {
        $counts = ['matched' => 0, 'ambiguous' => 0, 'guest' => 0, 'unmatched' => 0];
        foreach (array_keys($counts) as $key) {
            if (preg_match('/' . $key . ': (\d+)/i', $output, $m)) {
                $counts[$key] = (int)$m[1];
            }
        }
        return $counts;
    };

    try {
        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php 2>&1', $baseOut, $baseCode);
        $baseOutput = implode("\n", $baseOut);
        assertSame(0, $baseCode, 'baseline dry-run should succeed: ' . $baseOutput);
        $baseCounts = $parseCounts($baseOutput);

        DB::run(
            'INSERT INTO project_chats (project_id, user_id, guest_name, message) VALUES (?, ?, ?, ?)',
            [$projectId, $ownerId, 'MemberName', 'hello from guest']
        );

        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php 2>&1', $dryOut, $dryCode);
        $dryOutput = implode("\n", $dryOut);
        assertSame(0, $dryCode, 'dry-run after chat guest should succeed: ' . $dryOutput);
        $dryCounts = $parseCounts($dryOutput);
        assertSame(1, $dryCounts['guest'] - $baseCounts['guest'], 'dry-run guest count increases by 1');

        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php --apply 2>&1', $applyOut, $applyCode);
        $applyOutput = implode("\n", $applyOut);
        assertSame(0, $applyCode, 'apply should succeed: ' . $applyOutput);

        $linked = DB::run('SELECT assignee_user_id FROM project_tasks WHERE id = ?', [$taskId])->fetch();
        assertTrue($linked['assignee_user_id'] === null, 'assignee matching a chat-only guest must not be linked');
    } finally {
        DB::run('DELETE FROM project_chats WHERE project_id = ?', [$projectId]);
        DB::run('DELETE FROM project_tasks WHERE project_id = ?', [$projectId]);
        DB::run('DELETE FROM projects WHERE id = ?', [$projectId]);
        DB::run('DELETE FROM users WHERE id IN (?, ?)', [$ownerId, $memberId]);
    }
});

// แขกสองชื่อที่ต่างกันแค่วรรณยุกต์ต้องไม่ถูกรวมเป็นชื่อเดียวก่อนเทียบ (EDGE-8)
// ถ้ารวม ชื่อ "สมชาย" ของแขกจะหายไป แล้วงานไปผูกกับสมาชิกชื่อเดียวกัน
test('guest names differing only by a tone mark both count as guests', function (TestClient $_c): void {
    $run = 'toneguest_' . TEST_RUN_ID;
    DB::run("INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, 'x', 'Owner')", ["tg_o_$run", "tg_o_$run@example.test"]);
    $ownerId = (int)DB::conn()->lastInsertId();
    DB::run("INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, 'x', 'สมชาย')", ["tg_m_$run", "tg_m_$run@example.test"]);
    $memberId = (int)DB::conn()->lastInsertId();
    DB::run("INSERT INTO projects (user_id, name, status, priority) VALUES (?, ?, 'Planning', 'Medium')", [$ownerId, "Tone guest $run"]);
    $projectId = (int)DB::conn()->lastInsertId();
    DB::run("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, 'Editor')", [$projectId, $memberId]);
    // แขก "สมช้าย" สร้างงานไว้ ส่วนแขก "สมชาย" แค่คุยในแชต
    DB::run("INSERT INTO project_tasks (project_id, user_id, guest_name, title, status) VALUES (?, NULL, 'สมช้าย', 'guest made this', 'To Do')", [$projectId]);
    DB::run("INSERT INTO project_chats (project_id, user_id, guest_name, message) VALUES (?, NULL, 'สมชาย', 'hi')", [$projectId]);
    DB::run("INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, 'for the chat guest', 'To Do', 'สมชาย')", [$projectId, $ownerId]);
    $taskId = (int)DB::conn()->lastInsertId();

    try {
        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php --apply 2>&1', $out, $code);
        assertSame(0, $code, 'apply should succeed: ' . implode("\n", $out));
        $row = DB::run('SELECT assignee_user_id FROM project_tasks WHERE id = ?', [$taskId])->fetch();
        assertTrue($row['assignee_user_id'] === null, 'a name equal to a guest who only chatted must not be linked, got ' . describeValue($row['assignee_user_id']));
    } finally {
        DB::run('DELETE FROM project_chats WHERE project_id = ?', [$projectId]);
        DB::run('DELETE FROM project_tasks WHERE project_id = ?', [$projectId]);
        DB::run('DELETE FROM projects WHERE id = ?', [$projectId]);
        DB::run('DELETE FROM users WHERE id IN (?, ?)', [$ownerId, $memberId]);
    }
});

test('deleting a linked assignee account clears both assignee_user_id and assignee text', function (TestClient $_c): void {
    require_once ROOT . '/models/ProjectTask.php';
    require_once ROOT . '/models/User.php';

    $run = 'delacct_' . TEST_RUN_ID;

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['del_owner_' . $run, 'del_owner_' . $run . '@example.test', 'notused', 'Owner']
    );
    $ownerId = (int)DB::conn()->lastInsertId();

    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)",
        ['del_member_' . $run, 'del_member_' . $run . '@example.test', 'notused', 'MemberDisplay']
    );
    $memberId = (int)DB::conn()->lastInsertId();

    DB::run(
        'INSERT INTO projects (user_id, name, status, priority) VALUES (?, ?, ?, ?)',
        [$ownerId, 'Delete account project ' . $run, 'Planning', 'Medium']
    );
    $projectId = (int)DB::conn()->lastInsertId();

    DB::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $memberId, 'Editor']);

    DB::run(
        'INSERT INTO project_tasks (project_id, user_id, title, status, assignee) VALUES (?, ?, ?, ?, ?)',
        [$projectId, $ownerId, 'Delete account task', 'To Do', 'MemberDisplay']
    );
    $taskId = (int)DB::conn()->lastInsertId();

    try {
        exec('php ' . ROOT . '/scripts/migrate-daily-loop.php --apply 2>&1', $applyOut, $applyCode);
        assertSame(0, $applyCode, 'apply should link the assignee: ' . implode("\n", $applyOut));

        $before = DB::run('SELECT assignee_user_id, assignee FROM project_tasks WHERE id = ?', [$taskId])->fetch();
        assertSame($memberId, (int)$before['assignee_user_id'], 'assignee was linked by migration');

        User::deleteAccount($memberId);

        $tasks = ProjectTask::getByProject($projectId);
        $found = null;
        foreach ($tasks as $t) {
            if ((int)$t['id'] === $taskId) {
                $found = $t;
                break;
            }
        }
        assertTrue($found !== null, 'task still exists');
        assertSame(null, $found['assignee_user_id'] ?? null, 'assignee_user_id is null after account deletion');
        assertTrue(empty($found['assignee_name']), 'assignee_name is null/empty after account deletion');
    } finally {
        DB::run('DELETE FROM project_tasks WHERE project_id = ?', [$projectId]);
        DB::run('DELETE FROM projects WHERE id = ?', [$projectId]);
        DB::run('DELETE FROM users WHERE id IN (?, ?)', [$ownerId, $memberId]);
    }
});
