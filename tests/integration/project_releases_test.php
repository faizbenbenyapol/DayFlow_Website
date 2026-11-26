<?php
// =====================================================
// tests/integration/project_releases_test.php
//
// เวอร์ชัน (S6): สิทธิ์ การตรวจชื่อ/วัน changelog ที่สร้างตอนออกเวอร์ชัน (กลุ่ม ลำดับ
// งานที่ไม่ขึ้น งานค้างที่ย้าย) changelog ไม่เปลี่ยนตามชื่องาน และ id ที่มองไม่เห็นตอบเหมือนกัน
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * ผู้ใช้ห้าบทบาทของชุดนี้ (เจ้าของ ผู้ดูแล สมาชิก ผู้ดู คนนอก) สร้างครั้งเดียวต่อการรัน
 *
 * @return array<string, mixed>
 */
function relFixture(): array
{
    static $f = null;
    if ($f !== null) return $f;

    $make = static function (string $role): array {
        $username = 'rel_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, 'TestPass123!');
        return [$client, $username];
    };
    [$owner] = $make('owner');
    [$admin, $adminU] = $make('admin');
    [$member, $memberU] = $make('member');
    [$viewer, $viewerU] = $make('viewer');
    [$outsider] = $make('outsider');

    return $f = compact('owner', 'admin', 'member', 'viewer', 'outsider', 'adminU', 'memberU', 'viewerU');
}

/**
 * สร้างโปรเจคซอฟต์แวร์พร้อมผู้ดูแล สมาชิก และผู้ดู แล้วคืนรหัสโปรเจค
 *
 * @param array<string, mixed> $f fixture
 * @param string $label คำกำกับชื่อโปรเจค
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function relProject(array $f, string $label): int
{
    $res = $f['owner']->post('/api/projects', ['name' => 'เวอร์ชัน ' . $label . ' ' . TEST_RUN_ID, 'type' => 'software']);
    if ($res['status'] !== 201) {
        throw new RuntimeException('project create failed: ' . $res['body']);
    }
    $projectId = (int)($f['owner']->json($res)['id'] ?? 0);
    foreach (['admin' => 'Admin', 'member' => 'Editor', 'viewer' => 'Viewer'] as $key => $role) {
        $invite = $f['owner']->post("/api/projects/{$projectId}/members", ['email_or_username' => $f[$key . 'U'], 'role' => $role]);
        if ($invite['status'] !== 200) {
            throw new RuntimeException('member invite failed: ' . $invite['body']);
        }
    }
    return $projectId;
}

/**
 * สร้างเวอร์ชันด้วยเจ้าของแล้วคืนรหัส
 *
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function relCreate(array $f, int $projectId, string $name): int
{
    $res = $f['owner']->post("/api/projects/{$projectId}/releases", ['name' => $name]);
    if ($res['status'] !== 201) {
        throw new RuntimeException('release create failed: ' . $res['body']);
    }
    return (int)($f['owner']->json($res)['id'] ?? 0);
}

/**
 * สร้างงานในคอลัมน์กลุ่มที่ระบุ (todo/done) แล้วคืนรหัสงาน
 *
 * @param array<string, mixed> $extra ค่าเพิ่มเติม (kind, parent_id, release_id)
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function relTask(array $f, int $projectId, string $title, string $grp, array $extra = []): int
{
    $columnId = (int)DB::run(
        'SELECT id FROM project_columns WHERE project_id = ? AND grp = ? ORDER BY position LIMIT 1',
        [$projectId, $grp]
    )->fetchColumn();
    $res = $f['owner']->post("/api/projects/{$projectId}/tasks", array_merge(['title' => $title, 'column_id' => $columnId], $extra));
    if ($res['status'] !== 201) {
        throw new RuntimeException('task create failed: ' . $res['body']);
    }
    return (int)($f['owner']->json($res)['id'] ?? 0);
}

/**
 * เลขงานที่ผู้ใช้เห็น (KEY-NUMBER) อ่านจากฐานข้อมูล
 */
function relKey(int $taskId): string
{
    $row = DB::run(
        'SELECT p.key_prefix, t.number FROM project_tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ?',
        [$taskId]
    )->fetch();
    return $row['key_prefix'] . '-' . $row['number'];
}

/**
 * release_id ปัจจุบันของงาน
 */
function relOf(int $taskId): ?int
{
    $value = DB::run('SELECT release_id FROM project_tasks WHERE id = ?', [$taskId])->fetchColumn();
    return $value === null ? null : (int)$value;
}

test('only admins manage releases; members, viewers and outsiders cannot', function (TestClient $_c): void {
    $f = relFixture();
    $p = relProject($f, 'สิทธิ์');

    $created = $f['admin']->post("/api/projects/{$p}/releases", ['name' => 'v1.0', 'release_date' => '2026-12-01']);
    assertSame(201, $created['status'], $created['body']);
    $rid = (int)$f['admin']->json($created)['id'];

    assertSame(403, $f['member']->post("/api/projects/{$p}/releases", ['name' => 'v2'])['status'], 'member cannot create');
    assertSame(403, $f['viewer']->post("/api/projects/{$p}/releases", ['name' => 'v2'])['status'], 'viewer cannot create');
    assertSame(404, $f['outsider']->post("/api/projects/{$p}/releases", ['name' => 'v2'])['status'], 'outsider sees no project');
    assertSame(403, $f['member']->request('PUT', "/api/projects/releases/{$rid}", ['name' => 'x'])['status'], 'member cannot edit');
    assertSame(403, $f['member']->post("/api/projects/releases/{$rid}/release", [])['status'], 'member cannot release');

    $list = $f['viewer']->get("/api/projects/{$p}/releases");
    assertSame(200, $list['status'], 'viewer can read');
    assertSame('v1.0', $f['viewer']->json($list)['releases'][0]['name'] ?? null);

    $edit = $f['admin']->request('PUT', "/api/projects/releases/{$rid}", ['name' => 'v1.1', 'state' => 'active', 'release_date' => '']);
    assertSame(200, $edit['status'], $edit['body']);
    $row = DB::run('SELECT name, state, release_date FROM project_releases WHERE id = ?', [$rid])->fetch();
    assertSame(['name' => 'v1.1', 'state' => 'active', 'release_date' => null], $row);

    $task = relTask($f, $p, 'งานผูกเวอร์ชัน', 'todo', ['release_id' => $rid]);
    assertSame(200, $f['admin']->request('DELETE', "/api/projects/releases/{$rid}", [])['status'], 'admin deletes');
    assertSame(null, relOf($task), 'the task stays and is unlinked');
});

test('bad names, dates and states are refused', function (TestClient $_c): void {
    $f = relFixture();
    $p = relProject($f, 'ตรวจค่า');

    foreach (['', "\u{00A0} \u{00A0}", str_repeat('ก', 61), ['x']] as $name) {
        $res = $f['owner']->post("/api/projects/{$p}/releases", ['name' => $name]);
        assertSame(422, $res['status'], json_encode($name) . ' → ' . $res['body']);
    }
    assertSame(201, $f['owner']->post("/api/projects/{$p}/releases", ['name' => str_repeat('ก', 60)])['status'], '60 chars is fine');
    foreach (['2026-02-30', '30/11/2026', 20261201] as $date) {
        $res = $f['owner']->post("/api/projects/{$p}/releases", ['name' => 'v', 'release_date' => $date]);
        assertSame(422, $res['status'], json_encode($date) . ' → ' . $res['body']);
    }
    $rid = relCreate($f, $p, 'v1');
    assertSame(422, $f['owner']->request('PUT', "/api/projects/releases/{$rid}", ['state' => 'released'])['status'], 'state released only via release');
    assertSame(422, $f['owner']->request('PUT', "/api/projects/releases/{$rid}", ['changelog' => 'x'])['status'], 'no changelog before release');
    assertSame(400, $f['owner']->request('PUT', "/api/projects/releases/{$rid}", ['other' => 1])['status'], 'nothing to update');
});

test('releasing builds a grouped changelog and carries unfinished tasks', function (TestClient $_c): void {
    $f = relFixture();
    $p = relProject($f, 'changelog');
    $rid = relCreate($f, $p, 'v1.4');
    $next = relCreate($f, $p, 'v1.5');
    $link = ['release_id' => $rid];

    $bug2 = relTask($f, $p, 'ปุ่มบันทึกกดซ้ำได้', 'done', $link + ['kind' => 'bug']);
    $story1 = relTask($f, $p, 'หน้าแรกแบบใหม่', 'done', $link + ['kind' => 'story']);
    $task1 = relTask($f, $p, "ย้ายฐานข้อมูล\nไป MariaDB", 'done', $link + ['kind' => 'task']);
    $bug1 = relTask($f, $p, 'วันที่ <b>เพี้ยน</b>', 'done', $link + ['kind' => 'bug']);
    $epic = relTask($f, $p, 'งานใหญ่ที่ไม่ขึ้น', 'done', $link + ['kind' => 'epic']);
    $sub = relTask($f, $p, 'งานย่อยที่ไม่ขึ้น', 'done', $link + ['kind' => 'sub', 'parent_id' => $story1]);
    $pending = relTask($f, $p, 'งานที่ยังไม่เสร็จ', 'todo', $link + ['kind' => 'story']);
    $other = relTask($f, $p, 'งานไม่ผูกเวอร์ชัน', 'done', ['kind' => 'story']);

    $res = $f['admin']->post("/api/projects/releases/{$rid}/release", ['carry' => $next]);
    assertSame(200, $res['status'], $res['body']);
    $json = $f['admin']->json($res);
    assertSame(1, $json['carried'], 'one unfinished task moved');

    $expected = "ของใหม่\n- หน้าแรกแบบใหม่ (" . relKey($story1) . ")\n\n"
        . "ปรับปรุง\n- ย้ายฐานข้อมูล ไป MariaDB (" . relKey($task1) . ")\n\n"
        . "แก้บั๊ก\n- ปุ่มบันทึกกดซ้ำได้ (" . relKey($bug2) . ")\n- วันที่ <b>เพี้ยน</b> (" . relKey($bug1) . ')';
    assertSame($expected, $json['changelog'], 'groups in story/task/bug order, tasks by number');
    $row = DB::run('SELECT state, released_at IS NOT NULL AS has_time, changelog FROM project_releases WHERE id = ?', [$rid])->fetch();
    assertSame('released', $row['state']);
    assertSame(1, (int)$row['has_time']);
    assertSame($expected, $row['changelog'], 'the same text is stored');
    assertSame($next, relOf($pending), 'the unfinished task moved to v1.5');
    assertSame($rid, relOf($epic), 'done tasks keep their release');
    assertSame($rid, relOf($sub), 'done sub keeps its release');
    assertSame(null, relOf($other), 'an unlinked task is untouched');

    // ไม่ผูกเวอร์ชัน (carry null) และเวอร์ชันที่ไม่มีงานเสร็จได้ changelog ว่าง
    $res2 = $f['admin']->post("/api/projects/releases/{$next}/release", ['carry' => null]);
    assertSame(200, $res2['status'], $res2['body']);
    assertSame('', $f['admin']->json($res2)['changelog']);
    assertSame(null, relOf($pending), 'carry null unlinks the unfinished task');
});

test('renaming a task after release does not change the changelog, which admins can edit', function (TestClient $_c): void {
    $f = relFixture();
    $p = relProject($f, 'แช่แข็ง');
    $rid = relCreate($f, $p, 'v2');
    $task = relTask($f, $p, 'ชื่อเดิม', 'done', ['release_id' => $rid, 'kind' => 'story']);
    assertSame(200, $f['owner']->post("/api/projects/releases/{$rid}/release", [])['status']);
    $before = (string)DB::run('SELECT changelog FROM project_releases WHERE id = ?', [$rid])->fetchColumn();

    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$task}", ['title' => 'ชื่อใหม่'])['status']);
    assertSame($before, (string)DB::run('SELECT changelog FROM project_releases WHERE id = ?', [$rid])->fetchColumn(), 'changelog unchanged');
    assertStringContains('ชื่อเดิม', $before);

    $edit = $f['admin']->request('PUT', "/api/projects/releases/{$rid}", ['changelog' => "ของใหม่\r\n- แก้เอง"]);
    assertSame(200, $edit['status'], $edit['body']);
    assertSame("ของใหม่\n- แก้เอง", (string)DB::run('SELECT changelog FROM project_releases WHERE id = ?', [$rid])->fetchColumn(), 'CRLF stored as LF');
    assertSame(422, $f['admin']->request('PUT', "/api/projects/releases/{$rid}", ['changelog' => str_repeat('ก', 20001)])['status'], 'too long');
    assertSame(422, $f['admin']->request('PUT', "/api/projects/releases/{$rid}", ['state' => 'planned'])['status'], 'cannot reopen');
});

test('a release cannot be released twice or carry into a released, foreign, deleted or missing release', function (TestClient $_c): void {
    $f = relFixture();
    $p = relProject($f, 'ปลายทาง');
    $rid = relCreate($f, $p, 'v1');
    $done = relCreate($f, $p, 'v0');
    $gone = relCreate($f, $p, 'ลบแล้ว');
    relTask($f, $p, 'งานค้าง', 'todo', ['release_id' => $rid]);
    assertSame(200, $f['owner']->post("/api/projects/releases/{$done}/release", [])['status']);
    assertSame(200, $f['owner']->request('DELETE', "/api/projects/releases/{$gone}", [])['status']);
    $foreign = relCreate($f, relProject($f, 'อื่น'), 'v9');

    $toReleased = $f['owner']->post("/api/projects/releases/{$rid}/release", ['carry' => $done]);
    assertSame(422, $toReleased['status'], $toReleased['body']);
    $bodies = [];
    foreach ([$foreign, $gone, 999999999, $rid, 'x'] as $carry) {
        $res = $f['owner']->post("/api/projects/releases/{$rid}/release", ['carry' => $carry]);
        assertSame(404, $res['status'], json_encode($carry) . ' → ' . $res['body']);
        $bodies[] = $res['body'];
    }
    assertSame(1, count(array_unique($bodies)), 'every invisible target answers the same body');
    assertSame('planned', (string)DB::run('SELECT state FROM project_releases WHERE id = ?', [$rid])->fetchColumn(), 'nothing changed');

    assertSame(200, $f['owner']->post("/api/projects/releases/{$rid}/release", [])['status']);
    $again = $f['owner']->post("/api/projects/releases/{$rid}/release", []);
    assertSame(422, $again['status'], $again['body']);
});

test('tasks link only to unreleased releases of their own project', function (TestClient $_c): void {
    $f = relFixture();
    $p = relProject($f, 'ผูกงาน');
    $open = relCreate($f, $p, 'v1');
    $shipped = relCreate($f, $p, 'v0');
    $task = relTask($f, $p, 'งานในเวอร์ชันที่ออก', 'done', ['release_id' => $shipped]);
    assertSame(200, $f['owner']->post("/api/projects/releases/{$shipped}/release", [])['status']);
    $foreign = relCreate($f, relProject($f, 'อื่น 2'), 'v9');

    $cases = [
        [$foreign, 404], [999999999, 404], ['x', 404], [-1, 404], [[1], 404],
        [$shipped, 200],            // ส่งค่าเดิมกลับมาไม่ถือว่าเปลี่ยน
        [$open, 200],
    ];
    foreach ($cases as [$value, $status]) {
        $res = $f['member']->request('PUT', "/api/projects/tasks/{$task}", ['release_id' => $value]);
        assertSame($status, $res['status'], json_encode($value) . ' → ' . $res['body']);
    }
    assertSame($open, relOf($task), 'moved to the open release');
    $back = $f['member']->request('PUT', "/api/projects/tasks/{$task}", ['release_id' => $shipped]);
    assertSame(422, $back['status'], 'cannot move back into a released release');

    $created = $f['member']->post("/api/projects/{$p}/tasks", ['title' => 'ใหม่', 'release_id' => $shipped]);
    assertSame(422, $created['status'], 'a new task cannot join a released release');
    assertSame(200, $f['member']->request('PUT', "/api/projects/tasks/{$task}", ['release_id' => null])['status']);
    assertSame(null, relOf($task), 'null unlinks');
});

test('an unknown, foreign or deleted release id answers the same 404 everywhere', function (TestClient $_c): void {
    $f = relFixture();
    $p = relProject($f, 'มองไม่เห็น');
    $gone = relCreate($f, $p, 'ลบ');
    assertSame(200, $f['owner']->request('DELETE', "/api/projects/releases/{$gone}", [])['status']);
    $foreign = relCreate($f, relProject($f, 'คนอื่น'), 'v9');

    foreach ([['PUT', '', ['name' => 'x']], ['DELETE', '', []], ['POST', '/release', []]] as [$method, $suffix, $body]) {
        $bodies = [];
        foreach ([$gone, 999999999] as $rid) {
            $res = $f['owner']->request($method, "/api/projects/releases/{$rid}{$suffix}", $body);
            assertSame(404, $res['status'], $method . ' ' . $rid);
            $bodies[] = $res['body'];
        }
        $res = $f['outsider']->request($method, "/api/projects/releases/{$foreign}{$suffix}", $body);
        assertSame(404, $res['status'], $method . ' foreign');
        $bodies[] = $res['body'];
        assertSame(1, count(array_unique($bodies)), $method . ' bodies match');
    }
});

test('the task list carries releases with task and done counts', function (TestClient $_c): void {
    $f = relFixture();
    $p = relProject($f, 'payload');
    $rid = relCreate($f, $p, 'v3');
    relTask($f, $p, 'เสร็จ', 'done', ['release_id' => $rid]);
    relTask($f, $p, 'ยังไม่เสร็จ', 'todo', ['release_id' => $rid]);

    $list = $f['viewer']->json($f['viewer']->get("/api/projects/{$p}/tasks"));
    $release = $list['releases'][0] ?? [];
    assertSame($rid, (int)($release['id'] ?? 0));
    assertSame(2, $release['task_count'] ?? null);
    assertSame(1, $release['done_count'] ?? null);
});
