<?php
// =====================================================
// tests/integration/project_sprints_test.php
//
// รอบงานและ backlog (S3a): สร้าง/แก้/เริ่ม/ปิด/ลบรอบงาน การวางแผนงาน
// จาก backlog งานย่อยที่ตามงานแม่ สิทธิ์ของแต่ละบทบาท และการเริ่มพร้อมกัน
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * ผู้ใช้ห้าบทบาทของชุดนี้ (เจ้าของ ผู้ดูแล สมาชิก ผู้ดู และคนนอก)
 *
 * สร้างครั้งเดียวต่อการรัน เพราะการสมัคร/เข้าสู่ระบบคือส่วนที่ช้าที่สุด
 *
 * @return array<string, mixed>
 */
function sprintFixture(): array
{
    static $f = null;
    if ($f !== null) return $f;

    $password = 'TestPass123!';
    $make = static function (string $role) use ($password): array {
        $username = 'spr_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, $password);
        return [$client, $username];
    };
    [$owner, $ownerU]     = $make('owner');
    [$admin, $adminU]     = $make('admin');
    [$member, $memberU]   = $make('member');
    [$viewer, $viewerU]   = $make('viewer');
    [$outsider]           = $make('outsider');

    return $f = compact(
        'owner', 'admin', 'member', 'viewer', 'outsider',
        'ownerU', 'adminU', 'memberU', 'viewerU'
    );
}

/**
 * สร้างโปรเจคใหม่พร้อมผู้ดูแล สมาชิก และผู้ดู แล้วคืนรหัสโปรเจค
 *
 * @param array<string, mixed> $f fixture
 * @param string $label คำกำกับชื่อโปรเจค
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function sprintProject(array $f, string $label): int
{
    $res = $f['owner']->post('/api/projects', ['name' => 'รอบงาน ' . $label . ' ' . TEST_RUN_ID]);
    if ($res['status'] !== 201) {
        throw new RuntimeException('project create failed: ' . $res['body']);
    }
    $projectId = (int)($f['owner']->json($res)['id'] ?? 0);

    foreach (['admin' => 'Admin', 'member' => 'Editor', 'viewer' => 'Viewer'] as $key => $role) {
        $invite = $f['owner']->post("/api/projects/{$projectId}/members", [
            'email_or_username' => $f[$key . 'U'],
            'role' => $role,
        ]);
        if ($invite['status'] !== 200) {
            throw new RuntimeException('member invite failed: ' . $invite['body']);
        }
    }
    return $projectId;
}

/**
 * สร้างงานผ่าน API แล้วคืนรหัสงาน
 *
 * @param array<string, mixed> $body ข้อมูลงาน
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function sprintTaskId(TestClient $client, int $projectId, array $body): int
{
    $res = $client->post("/api/projects/{$projectId}/tasks", $body);
    if ($res['status'] !== 201) {
        throw new RuntimeException('task create failed: ' . $res['body']);
    }
    return (int)($client->json($res)['id'] ?? 0);
}

/**
 * สร้างรอบงานผ่าน API แล้วคืนผลตอบกลับที่ถอด JSON พร้อม status
 *
 * @param array<string, mixed> $body ข้อมูลรอบงาน
 * @return array<string, mixed>
 */
function sprintCreate(TestClient $client, int $projectId, array $body): array
{
    $res = $client->post("/api/projects/{$projectId}/sprints", $body);
    return $client->json($res) + ['status' => $res['status']];
}

/**
 * วางแผนงานเข้า/ออกรอบงานผ่าน API
 *
 * @param array<int, int> $ids รหัสงานตามลำดับ
 * @return array{status: int, body: string, headers: string}
 */
function sprintPlan(TestClient $client, int $projectId, ?int $sprintId, array $ids): array
{
    return $client->post("/api/projects/{$projectId}/plan", [
        'sprint_id' => $sprintId,
        'ids' => $ids,
    ]);
}

/**
 * หารหัสคอลัมน์แรกของกลุ่มที่ระบุในโปรเจค
 *
 * @return int รหัสคอลัมน์
 */
function sprintColumnId(int $projectId, string $grp): int
{
    return (int)DB::run(
        'SELECT id FROM project_columns WHERE project_id = ? AND grp = ? ORDER BY position, id LIMIT 1',
        [$projectId, $grp]
    )->fetchColumn();
}

test('an admin creates numbered sprints and the date and role rules hold', function (TestClient $_c): void {
    $f = sprintFixture();
    $p = sprintProject($f, 'สร้างรอบ');

    $first = $f['admin']->post("/api/projects/{$p}/sprints", []);
    assertSame(201, $first['status'], $first['body']);
    assertSame('รอบ 1', $f['admin']->json($first)['name'] ?? null, 'an empty name numbers itself');

    $second = $f['admin']->post("/api/projects/{$p}/sprints", ['name' => '   ']);
    assertSame(201, $second['status'], $second['body']);
    assertSame('รอบ 2', $f['admin']->json($second)['name'] ?? null, 'blank whitespace counts as empty');

    $bad = $f['admin']->post("/api/projects/{$p}/sprints", [
        'name' => 'ช่วงผิด',
        'start_date' => '2026-05-10',
        'end_date' => '2026-05-01',
    ]);
    assertSame(422, $bad['status'], $bad['body']);
    assertStringContains('วันจบต้องไม่ก่อนวันเริ่ม', $bad['body']);

    assertSame(403, $f['member']->post("/api/projects/{$p}/sprints", ['name' => 'สมาชิกสร้าง'])['status']);
    assertSame(403, $f['viewer']->post("/api/projects/{$p}/sprints", ['name' => 'ผู้ดูสร้าง'])['status']);

    $list = $f['owner']->json($f['owner']->get("/api/projects/{$p}/sprints"));
    assertSame(2, count($list['sprints'] ?? []), 'only the two accepted sprints exist');
});

test('a member plans tasks in order and their subs follow the parent', function (TestClient $_c): void {
    $f = sprintFixture();
    $p = sprintProject($f, 'วางแผน');
    $sprint = sprintCreate($f['admin'], $p, ['name' => 'รอบวางแผน']);
    assertSame(201, $sprint['status'], $sprint['body'] ?? '');
    $sprintId = (int)$sprint['id'];

    $story = sprintTaskId($f['owner'], $p, ['title' => 'เรื่องมีลูก', 'kind' => 'story']);
    $sub1 = sprintTaskId($f['owner'], $p, ['title' => 'งานย่อย 1', 'kind' => 'sub', 'parent_id' => $story]);
    $sub2 = sprintTaskId($f['owner'], $p, ['title' => 'งานย่อย 2', 'kind' => 'sub', 'parent_id' => $story]);
    $t1 = sprintTaskId($f['owner'], $p, ['title' => 'งานหนึ่ง']);
    $t2 = sprintTaskId($f['owner'], $p, ['title' => 'งานสอง']);
    $t3 = sprintTaskId($f['owner'], $p, ['title' => 'บั๊กสาม', 'kind' => 'bug']);

    // รหัสผิดรูปถูกตรวจก่อนเข้า lock: ต้องได้ 422 ไม่ใช่ 500 เพราะหาคลาส exception ไม่เจอ
    foreach ([[null], ['x'], [['a' => 1]]] as $badIds) {
        $bad = sprintPlan($f['member'], $p, $sprintId, $badIds);
        assertSame(422, $bad['status'], 'ids ' . json_encode($badIds) . ' → ' . $bad['body']);
    }

    $order = [$t3, $t1, $t2];
    $planned = sprintPlan($f['member'], $p, $sprintId, $order);
    assertSame(200, $planned['status'], $planned['body']);

    $rows = DB::run(
        'SELECT id, sprint_id, backlog_rank FROM project_tasks WHERE id IN (?, ?, ?)',
        [$t3, $t1, $t2]
    )->fetchAll();
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['id']] = $row;
    }
    foreach ($order as $index => $taskId) {
        assertSame($sprintId, (int)$byId[$taskId]['sprint_id'], 'task ' . $taskId . ' is in the sprint');
        assertSame($index, (int)$byId[$taskId]['backlog_rank'], 'rank follows the list order');
    }

    assertSame(200, sprintPlan($f['member'], $p, $sprintId, [$story])['status'], 'the parent story joins the sprint');
    $subSprints = DB::run(
        'SELECT sprint_id FROM project_tasks WHERE id IN (?, ?)',
        [$sub1, $sub2]
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($subSprints as $subSprint) {
        assertSame($sprintId, (int)$subSprint, 'a sub follows its parent');
    }

    $epic = sprintTaskId($f['owner'], $p, ['title' => 'งานใหญ่', 'kind' => 'epic']);
    $epicPlan = sprintPlan($f['member'], $p, $sprintId, [$epic]);
    assertSame(422, $epicPlan['status'], $epicPlan['body']);
    assertStringContains('วางแผนได้เฉพาะเรื่องผู้ใช้ งาน และบั๊ก', $epicPlan['body']);

    $subPlan = sprintPlan($f['member'], $p, $sprintId, [$sub1]);
    assertSame(422, $subPlan['status'], $subPlan['body']);
    assertSame($epicPlan['body'], $subPlan['body'], 'an epic and a sub get the same refusal');

    $other = sprintProject($f, 'โปรเจคอื่น');
    $foreign = sprintTaskId($f['owner'], $other, ['title' => 'งานนอกโปรเจค']);
    $foreignPlan = sprintPlan($f['member'], $p, $sprintId, [$foreign]);
    $missingPlan = sprintPlan($f['member'], $p, $sprintId, [999999999]);
    assertSame(422, $foreignPlan['status'], $foreignPlan['body']);
    assertSame($foreignPlan['status'], $missingPlan['status'], 'foreign and missing ids answer the same');
    assertSame($foreignPlan['body'], $missingPlan['body'], 'and the body does not reveal which id exists');

    $closedBody = ['name' => 'รอบที่จะปิด', 'start_date' => '2026-03-01', 'end_date' => '2026-03-14'];
    $closed = sprintCreate($f['admin'], $p, $closedBody);
    $closedId = (int)$closed['id'];
    assertSame(200, $f['admin']->post("/api/projects/sprints/{$closedId}/start", [])['status']);
    assertSame(200, $f['admin']->post("/api/projects/sprints/{$closedId}/close", ['carry' => 'backlog'])['status']);
    $closedPlan = sprintPlan($f['member'], $p, $closedId, [$t1]);
    assertSame(404, $closedPlan['status'], $closedPlan['body']);
    assertStringContains('ไม่พบรอบงาน', $closedPlan['body']);

    assertSame(403, sprintPlan($f['viewer'], $p, $sprintId, [$t1])['status'], 'a viewer cannot plan');
});

test('starting needs dates, sums committed points and refuses a second active sprint', function (TestClient $_c): void {
    $f = sprintFixture();
    $p = sprintProject($f, 'เริ่มรอบ');

    $a = sprintCreate($f['admin'], $p, ['name' => 'รอบแรก']);
    $aId = (int)$a['id'];
    $noDates = $f['admin']->post("/api/projects/sprints/{$aId}/start", []);
    assertSame(422, $noDates['status'], $noDates['body']);
    assertStringContains('ตั้งวันเริ่มและวันจบก่อนเริ่มรอบ', $noDates['body']);

    $dates = ['start_date' => '2026-06-01', 'end_date' => '2026-06-14'];
    assertSame(200, $f['admin']->request('PUT', "/api/projects/sprints/{$aId}", $dates)['status']);

    $story = sprintTaskId($f['owner'], $p, ['title' => 'เรื่อง 5 แต้ม', 'kind' => 'story', 'story_points' => 5]);
    $task = sprintTaskId($f['owner'], $p, ['title' => 'งาน 3 แต้ม', 'story_points' => 3]);
    $bug = sprintTaskId($f['owner'], $p, ['title' => 'บั๊ก 2 แต้ม', 'kind' => 'bug', 'story_points' => 2]);
    assertSame(200, sprintPlan($f['member'], $p, $aId, [$story, $task, $bug])['status']);

    $started = $f['admin']->post("/api/projects/sprints/{$aId}/start", []);
    assertSame(200, $started['status'], $started['body']);
    $row = DB::run('SELECT state, committed_points, started_at FROM project_sprints WHERE id = ?', [$aId])->fetch();
    assertSame('active', $row['state'], 'the sprint is active');
    assertSame(10, (int)$row['committed_points'], '5 + 3 + 2 points were committed');
    assertTrue($row['started_at'] !== null, 'started_at is stamped');

    $b = sprintCreate($f['admin'], $p, ['name' => 'รอบสอง'] + $dates);
    $second = $f['admin']->post('/api/projects/sprints/' . (int)$b['id'] . '/start', []);
    assertSame(422, $second['status'], $second['body']);
    assertStringContains('ปิดรอบที่กำลังทำก่อน จึงเริ่มรอบใหม่ได้', $second['body']);
    assertSame(1, (int)DB::run(
        "SELECT COUNT(*) FROM project_sprints WHERE project_id = ? AND state = 'active'",
        [$p]
    )->fetchColumn(), 'only one sprint is active');
});

test('two simultaneous starts leave exactly one active sprint in every round', function (TestClient $_c): void {
    $f = sprintFixture();
    $p = sprintProject($f, 'เริ่มพร้อมกัน');

    $sessions = [];
    for ($i = 0; $i < 2; $i++) {
        $session = new TestClient(TEST_BASE_URL);
        $session->login($f['adminU'], 'TestPass123!');
        $sessions[] = $session;
    }

    for ($round = 1; $round <= 5; $round++) {
        $dates = ['start_date' => '2026-07-01', 'end_date' => '2026-07-14'];
        $one = sprintCreate($f['owner'], $p, ['name' => "รอบ {$round}a"] + $dates);
        $two = sprintCreate($f['owner'], $p, ['name' => "รอบ {$round}b"] + $dates);

        $results = TestClient::parallel([
            [$sessions[0], 'POST', '/api/projects/sprints/' . (int)$one['id'] . '/start', []],
            [$sessions[1], 'POST', '/api/projects/sprints/' . (int)$two['id'] . '/start', []],
        ]);

        $statuses = array_map(static fn(array $res): int => (int)$res['status'], $results);
        sort($statuses);
        assertSame([200, 422], $statuses, 'round ' . $round . ': ' . $results[0]['body'] . ' / ' . $results[1]['body']);
        assertSame(1, (int)DB::run(
            "SELECT COUNT(*) FROM project_sprints WHERE project_id = ? AND state = 'active'",
            [$p]
        )->fetchColumn(), 'round ' . $round . ' has exactly one active sprint');

        $activeId = (int)DB::run(
            "SELECT id FROM project_sprints WHERE project_id = ? AND state = 'active'",
            [$p]
        )->fetchColumn();
        $closed = $f['owner']->post("/api/projects/sprints/{$activeId}/close", ['carry' => 'backlog']);
        assertSame(200, $closed['status'], $closed['body']);
    }
});

test('closing carries unfinished work, counts completed points and refuses bad targets', function (TestClient $_c): void {
    $f = sprintFixture();
    $p = sprintProject($f, 'ปิดรอบ');
    $dates = ['start_date' => '2026-08-01', 'end_date' => '2026-08-14'];
    $doneColumn = sprintColumnId($p, 'done');

    $s = sprintCreate($f['admin'], $p, ['name' => 'รอบหลัก'] + $dates);
    $sId = (int)$s['id'];
    $target = sprintCreate($f['admin'], $p, ['name' => 'รอบปลายทาง'] + $dates);
    $targetId = (int)$target['id'];

    $doneStory = sprintTaskId($f['owner'], $p, ['title' => 'เรื่องเสร็จ', 'kind' => 'story', 'story_points' => 5]);
    $openStory = sprintTaskId($f['owner'], $p, ['title' => 'เรื่องค้าง', 'kind' => 'story', 'story_points' => 3]);
    $openSub1 = sprintTaskId($f['owner'], $p, ['title' => 'ย่อยค้าง 1', 'kind' => 'sub', 'parent_id' => $openStory]);
    $openSub2 = sprintTaskId($f['owner'], $p, ['title' => 'ย่อยค้าง 2', 'kind' => 'sub', 'parent_id' => $openStory]);
    $openTask = sprintTaskId($f['owner'], $p, ['title' => 'งานค้าง', 'story_points' => 2]);

    assertSame(200, sprintPlan($f['member'], $p, $sId, [$doneStory, $openStory, $openTask])['status']);
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$doneStory}", ['column_id' => $doneColumn])['status']);
    assertSame(200, $f['admin']->post("/api/projects/sprints/{$sId}/start", [])['status'], 'the sprint must be active before it is closed');

    $closed = $f['admin']->post("/api/projects/sprints/{$sId}/close", ['carry' => $targetId]);
    assertSame(200, $closed['status'], $closed['body']);
    $closedJson = $f['admin']->json($closed);
    assertSame($targetId, (int)($closedJson['target_sprint_id'] ?? 0), 'carry went to the planned sprint');
    assertSame(4, (int)($closedJson['moved'] ?? -1), 'the open story, its two subs and the open task moved');

    $sprintOf = static fn(int $taskId): mixed => DB::run(
        'SELECT sprint_id FROM project_tasks WHERE id = ?',
        [$taskId]
    )->fetchColumn();
    assertSame($sId, (int)$sprintOf($doneStory), 'a done task keeps the closed sprint');
    foreach ([$openStory, $openSub1, $openSub2, $openTask] as $taskId) {
        assertSame($targetId, (int)$sprintOf($taskId), 'unfinished work and its subs moved');
    }
    $closedRow = DB::run('SELECT state, completed_points FROM project_sprints WHERE id = ?', [$sId])->fetch();
    assertSame('closed', $closedRow['state']);
    assertSame(5, (int)$closedRow['completed_points'], 'only the done non-epic task counts');

    // carry = new: สร้างรอบใหม่ชื่อ "รอบ N" แล้วย้ายงานค้างเข้าไป
    $n = sprintCreate($f['admin'], $p, ['name' => 'รอบใหม่'] + $dates);
    $nId = (int)$n['id'];
    $nTask = sprintTaskId($f['owner'], $p, ['title' => 'งานรอบใหม่']);
    assertSame(200, sprintPlan($f['member'], $p, $nId, [$nTask])['status']);
    assertSame(200, $f['admin']->post("/api/projects/sprints/{$nId}/start", [])['status']);
    $newClose = $f['admin']->post("/api/projects/sprints/{$nId}/close", ['carry' => 'new']);
    assertSame(200, $newClose['status'], $newClose['body']);
    $newTarget = (int)($f['admin']->json($newClose)['target_sprint_id'] ?? 0);
    assertTrue($newTarget > 0, 'carry new names its new sprint');
    $newName = (string)DB::run('SELECT name FROM project_sprints WHERE id = ?', [$newTarget])->fetchColumn();
    assertTrue(preg_match('/^รอบ \d+$/u', $newName) === 1, 'the new sprint follows the auto name: ' . $newName);
    assertSame($newTarget, (int)$sprintOf($nTask), 'the unfinished task moved into the new sprint');

    // carry = backlog
    $b = sprintCreate($f['admin'], $p, ['name' => 'รอบกลับคลัง'] + $dates);
    $bId = (int)$b['id'];
    $bTask = sprintTaskId($f['owner'], $p, ['title' => 'งานกลับคลัง']);
    assertSame(200, sprintPlan($f['member'], $p, $bId, [$bTask])['status']);
    assertSame(200, $f['admin']->post("/api/projects/sprints/{$bId}/start", [])['status']);
    $backlogClose = $f['admin']->post("/api/projects/sprints/{$bId}/close", ['carry' => 'backlog']);
    assertSame(200, $backlogClose['status'], $backlogClose['body']);
    assertSame(null, $f['admin']->json($backlogClose)['target_sprint_id'] ?? null, 'backlog has no target sprint');
    assertSame(null, $sprintOf($bTask), 'the task is back in the backlog');

    // รอบ active ที่ไม่มีงานก็ปิดได้
    $e = sprintCreate($f['admin'], $p, ['name' => 'รอบว่าง'] + $dates);
    $eId = (int)$e['id'];
    assertSame(200, $f['admin']->post("/api/projects/sprints/{$eId}/start", [])['status']);
    $emptyClose = $f['admin']->post("/api/projects/sprints/{$eId}/close", ['carry' => 'backlog']);
    assertSame(200, $emptyClose['status'], $emptyClose['body']);
    assertSame(0, (int)($f['admin']->json($emptyClose)['moved'] ?? -1), 'an empty sprint moves nothing');

    // ปิดรอบที่ยัง planned ไม่ได้ และย้ายงานค้างไปรอบที่ปิดแล้วไม่ได้
    $planned = sprintCreate($f['admin'], $p, ['name' => 'รอบวางแผน'] + $dates);
    assertSame(422, $f['admin']->post('/api/projects/sprints/' . (int)$planned['id'] . '/close', ['carry' => 'backlog'])['status']);

    $x = sprintCreate($f['admin'], $p, ['name' => 'รอบปิดท้าย'] + $dates);
    $xId = (int)$x['id'];
    assertSame(200, $f['admin']->post("/api/projects/sprints/{$xId}/start", [])['status']);
    $badCarry = $f['admin']->post("/api/projects/sprints/{$xId}/close", ['carry' => $sId]);
    assertSame(422, $badCarry['status'], $badCarry['body']);
    assertStringContains('เลือกที่ย้ายงานค้างไม่ถูกต้อง', $badCarry['body']);
});

test('deleting a planned sprint returns its tasks to the backlog', function (TestClient $_c): void {
    $f = sprintFixture();
    $p = sprintProject($f, 'ลบรอบ');

    $s = sprintCreate($f['admin'], $p, ['name' => 'รอบจะถูกลบ']);
    $sId = (int)$s['id'];
    $task = sprintTaskId($f['owner'], $p, ['title' => 'งานในรอบที่ถูกลบ']);
    assertSame(200, sprintPlan($f['member'], $p, $sId, [$task])['status']);

    assertSame(200, $f['admin']->request('DELETE', "/api/projects/sprints/{$sId}", [])['status']);
    assertSame(null, DB::run('SELECT sprint_id FROM project_tasks WHERE id = ?', [$task])->fetchColumn(), 'the task is back in the backlog');

    $active = sprintCreate($f['admin'], $p, ['name' => 'รอบกำลังทำ', 'start_date' => '2026-09-01', 'end_date' => '2026-09-14']);
    $activeId = (int)$active['id'];
    assertSame(200, $f['admin']->post("/api/projects/sprints/{$activeId}/start", [])['status']);
    $deleted = $f['admin']->request('DELETE', "/api/projects/sprints/{$activeId}", []);
    assertSame(422, $deleted['status'], $deleted['body']);
    assertStringContains('ลบได้เฉพาะรอบที่ยังไม่เริ่ม', $deleted['body']);
});

test('task endpoints store sprint_id and enforce the sprint rules', function (TestClient $_c): void {
    $f = sprintFixture();
    $p = sprintProject($f, 'งานกับรอบ');
    $s = sprintCreate($f['admin'], $p, ['name' => 'รอบของงาน']);
    $sId = (int)$s['id'];

    $task = sprintTaskId($f['owner'], $p, ['title' => 'งานมีรอบ', 'sprint_id' => $sId]);
    assertSame($sId, (int)DB::run('SELECT sprint_id FROM project_tasks WHERE id = ?', [$task])->fetchColumn(), 'create stores the sprint');

    $sub = sprintTaskId($f['owner'], $p, ['title' => 'งานย่อยตามแม่', 'kind' => 'sub', 'parent_id' => $task]);
    assertSame($sId, (int)DB::run('SELECT sprint_id FROM project_tasks WHERE id = ?', [$sub])->fetchColumn(), 'a sub inherits the parent sprint');

    $parent = sprintTaskId($f['owner'], $p, ['title' => 'งานแม่อยู่คลัง']);
    $subDirect = sprintTaskId($f['owner'], $p, [
        'title' => 'งานย่อยส่งรอบเอง',
        'kind' => 'sub',
        'parent_id' => $parent,
        'sprint_id' => $sId,
    ]);
    assertSame(null, DB::run('SELECT sprint_id FROM project_tasks WHERE id = ?', [$subDirect])->fetchColumn(), 'a sub ignores a directly sent sprint');

    $epic = sprintTaskId($f['owner'], $p, ['title' => 'งานใหญ่', 'kind' => 'epic']);
    $epicPut = $f['owner']->request('PUT', "/api/projects/tasks/{$epic}", ['sprint_id' => $sId]);
    assertSame(422, $epicPut['status'], $epicPut['body']);
    assertStringContains('งานใหญ่ไม่อยู่ในรอบงาน', $epicPut['body']);

    $other = sprintProject($f, 'รอบต่างโปรเจค');
    $otherSprint = sprintCreate($f['admin'], $other, ['name' => 'รอบอื่น']);
    $otherSprintId = (int)$otherSprint['id'];

    $foreign = $f['owner']->request('PUT', "/api/projects/tasks/{$task}", ['sprint_id' => $otherSprintId]);
    $missing = $f['owner']->request('PUT', "/api/projects/tasks/{$task}", ['sprint_id' => 999999999]);
    assertSame(404, $foreign['status'], $foreign['body']);
    assertSame($foreign['status'], $missing['status'], 'foreign and missing sprints answer the same');
    assertSame($foreign['body'], $missing['body'], 'the body does not reveal which sprint exists');

    $createForeign = $f['owner']->post("/api/projects/{$p}/tasks", ['title' => 'งานนอก', 'sprint_id' => $otherSprintId]);
    $createMissing = $f['owner']->post("/api/projects/{$p}/tasks", ['title' => 'งานนอก', 'sprint_id' => 999999999]);
    assertSame(404, $createForeign['status'], $createForeign['body']);
    assertSame($createForeign['body'], $createMissing['body'], 'create answers both bad ids the same');

    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$task}", ['sprint_id' => null])['status']);
    assertSame(null, DB::run('SELECT sprint_id FROM project_tasks WHERE id = ?', [$task])->fetchColumn(), 'null sends the task back to the backlog');
});

test('a share guest sees sprints but cannot create one', function (TestClient $_c): void {
    $f = sprintFixture();
    $p = sprintProject($f, 'แขกอ่านรอบ');
    $s = sprintCreate($f['admin'], $p, ['name' => 'รอบที่แขกเห็น']);
    assertSame(201, $s['status'], $s['body'] ?? '');

    $share = $f['owner']->json($f['owner']->post("/api/projects/{$p}/share", ['share_role' => 'Viewer']));
    $token = (string)($share['share_token'] ?? '');
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . $token);
    $guest->get('/projects');

    $detail = $guest->get("/api/projects/{$p}/tasks");
    assertSame(200, $detail['status'], $detail['body']);
    $payload = $guest->json($detail);
    assertArrayHasKey('sprints', $payload, 'the task payload always carries sprints');
    assertSame('รอบที่แขกเห็น', $payload['sprints'][0]['name'] ?? null, 'the guest sees the sprint list');

    assertSame(200, $guest->get("/api/projects/{$p}/sprints")['status'], 'the guest can read the sprint endpoint');
    assertSame(403, $guest->post("/api/projects/{$p}/sprints", ['name' => 'แขกสร้าง'])['status'], 'but cannot create one');
});

test('an admin turns sprints off without deleting data and a member cannot', function (TestClient $_c): void {
    $f = sprintFixture();
    $p = sprintProject($f, 'สวิตช์รอบ');
    $s = sprintCreate($f['admin'], $p, ['name' => 'รอบที่ต้องอยู่ต่อ']);
    assertSame(201, $s['status'], $s['body'] ?? '');

    $off = $f['admin']->request('PUT', "/api/projects/{$p}", ['use_sprints' => 0]);
    assertSame(200, $off['status'], $off['body']);
    assertSame(0, (int)DB::run('SELECT use_sprints FROM projects WHERE id = ?', [$p])->fetchColumn(), 'the switch is stored off');
    assertSame(1, (int)DB::run('SELECT COUNT(*) FROM project_sprints WHERE project_id = ?', [$p])->fetchColumn(), 'turning it off deletes nothing');

    $on = $f['admin']->request('PUT', "/api/projects/{$p}", ['use_sprints' => 1]);
    assertSame(200, $on['status'], $on['body']);
    assertSame(1, (int)DB::run('SELECT use_sprints FROM projects WHERE id = ?', [$p])->fetchColumn(), 'the switch turns back on');

    $member = $f['member']->request('PUT', "/api/projects/{$p}", ['use_sprints' => 0]);
    assertSame(403, $member['status'], $member['body']);
    assertSame(1, (int)DB::run('SELECT use_sprints FROM projects WHERE id = ?', [$p])->fetchColumn(), 'a member cannot change it');

    assertSame(422, $f['admin']->request('PUT', "/api/projects/{$p}", ['use_sprints' => 2])['status'], 'only 0 and 1 are accepted');
});
