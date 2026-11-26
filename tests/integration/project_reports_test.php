<?php
// =====================================================
// tests/integration/project_reports_test.php
//
// รายงานของโปรเจค (S4a): ยอดรวมรายวันของรอบงาน (burndown) ที่บันทึกจากงานจริง
// การยกยอดวันก่อนหน้า velocity ของรอบที่ปิดแล้ว และสิทธิ์/การไม่เปิดเผยรหัส
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * ผู้ใช้ของชุดนี้ (เจ้าของ ผู้ดู และคนนอก) สร้างครั้งเดียวต่อการรัน
 *
 * @return array<string, mixed>
 */
function reportFixture(): array
{
    static $f = null;
    if ($f !== null) return $f;

    $make = static function (string $role): array {
        $username = 'rep_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, 'TestPass123!');
        return [$client, $username];
    };
    [$owner] = $make('owner');
    [$viewer, $viewerU] = $make('viewer');
    [$outsider] = $make('outsider');

    return $f = compact('owner', 'viewer', 'outsider', 'viewerU');
}

/**
 * สร้างโปรเจคใหม่ (ค่าเริ่มต้นชนิด software) และเพิ่มผู้ดู แล้วคืนรหัสโปรเจค
 *
 * @param array<string, mixed> $f fixture
 * @param string $label คำกำกับชื่อโปรเจค
 * @param string $type ประเภทโปรเจค
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function reportProject(array $f, string $label, string $type = 'software'): int
{
    $res = $f['owner']->post('/api/projects', [
        'name' => 'รายงาน ' . $label . ' ' . TEST_RUN_ID,
        'type' => $type,
    ]);
    if ($res['status'] !== 201) {
        throw new RuntimeException('project create failed: ' . $res['body']);
    }
    $projectId = (int)($f['owner']->json($res)['id'] ?? 0);

    $invite = $f['owner']->post("/api/projects/{$projectId}/members", [
        'email_or_username' => $f['viewerU'],
        'role' => 'Viewer',
    ]);
    if ($invite['status'] !== 200) {
        throw new RuntimeException('member invite failed: ' . $invite['body']);
    }
    return $projectId;
}

/**
 * สร้างงานผ่าน API แล้วคืนรหัสงาน
 *
 * @param array<string, mixed> $body ข้อมูลงาน
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function reportTaskId(TestClient $client, int $projectId, array $body): int
{
    $res = $client->post("/api/projects/{$projectId}/tasks", $body);
    if ($res['status'] !== 201) {
        throw new RuntimeException('task create failed: ' . $res['body']);
    }
    return (int)($client->json($res)['id'] ?? 0);
}

/**
 * สร้างรอบงานผ่าน API แล้วคืนผลตอบกลับที่ถอด JSON
 *
 * @param array<string, mixed> $body ข้อมูลรอบงาน
 * @return array<string, mixed>
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function reportSprint(TestClient $client, int $projectId, array $body): array
{
    $res = $client->post("/api/projects/{$projectId}/sprints", $body);
    if ($res['status'] !== 201) {
        throw new RuntimeException('sprint create failed: ' . $res['body']);
    }
    return $client->json($res);
}

/**
 * หารหัสคอลัมน์แรกของกลุ่มที่ระบุในโปรเจค
 *
 * @return int รหัสคอลัมน์
 */
function reportColumnId(int $projectId, string $grp): int
{
    return (int)DB::run(
        'SELECT id FROM project_columns WHERE project_id = ? AND grp = ? ORDER BY position, id LIMIT 1',
        [$projectId, $grp]
    )->fetchColumn();
}

/**
 * อ่านแถวของวันนี้ใน project_sprint_days ของรอบงาน
 *
 * @return array<string, mixed>|null แถวที่บันทึกไว้ หรือ null เมื่อไม่มี
 */
function reportToday(int $sprintId): ?array
{
    return DB::run(
        'SELECT scope_points, done_points FROM project_sprint_days WHERE sprint_id = ? AND `day` = ?',
        [$sprintId, date('Y-m-d')]
    )->fetch() ?: null;
}

test('starting a sprint records today scope from its real tasks', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'ยอดวันแรก');
    $sprint = reportSprint($f['owner'], $p, [
        'name' => 'รอบแรก',
        'start_date' => date('Y-m-d', strtotime('-4 days')),
        'end_date' => date('Y-m-d', strtotime('+3 days')),
    ]);
    $sid = (int)$sprint['id'];

    reportTaskId($f['owner'], $p, ['title' => 'งาน 3 แต้ม', 'story_points' => 3, 'sprint_id' => $sid]);
    reportTaskId($f['owner'], $p, ['title' => 'งาน 5 แต้ม', 'story_points' => 5, 'sprint_id' => $sid]);
    $parent = reportTaskId($f['owner'], $p, ['title' => 'เรื่องแม่', 'kind' => 'story', 'sprint_id' => $sid]);
    reportTaskId($f['owner'], $p, ['title' => 'งานย่อย 2 แต้ม', 'kind' => 'sub', 'parent_id' => $parent, 'story_points' => 2]);

    $epicPoints = $f['owner']->post("/api/projects/{$p}/tasks", [
        'title' => 'งานใหญ่มีแต้ม',
        'kind' => 'epic',
        'story_points' => 3,
    ]);
    assertSame(422, $epicPoints['status'], $epicPoints['body']);
    reportTaskId($f['owner'], $p, ['title' => 'งานใหญ่', 'kind' => 'epic']);

    $started = $f['owner']->post("/api/projects/sprints/{$sid}/start", []);
    assertSame(200, $started['status'], $started['body']);

    $row = reportToday($sid);
    assertTrue($row !== null, 'the start wrote a row for today');
    assertSame(10, (int)$row['scope_points'], '3 + 5 + 2 points, the epic is not counted');
    assertSame(0, (int)$row['done_points'], 'nothing is done yet');
});

test('moving a task to a done column changes done and moving it back clears it', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'ย้ายคอลัมน์');
    $dates = ['start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d')];
    $sprint = reportSprint($f['owner'], $p, ['name' => 'รอบย้าย'] + $dates);
    $sid = (int)$sprint['id'];

    $three = reportTaskId($f['owner'], $p, ['title' => 'งาน 3', 'story_points' => 3, 'sprint_id' => $sid]);
    reportTaskId($f['owner'], $p, ['title' => 'งาน 5', 'story_points' => 5, 'sprint_id' => $sid]);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$sid}/start", [])['status']);

    $done = reportColumnId($p, 'done');
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$three}", ['column_id' => $done])['status']);
    assertSame(3, (int)reportToday($sid)['done_points'], 'the 3-point task is done');

    $todo = reportColumnId($p, 'todo');
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$three}", ['column_id' => $todo])['status']);
    assertSame(0, (int)reportToday($sid)['done_points'], 'moving it back clears done');
});

test('scope follows tasks added to the sprint and points changed', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'ขอบเขต');
    $sprint = reportSprint($f['owner'], $p, [
        'name' => 'รอบขอบเขต',
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d'),
    ]);
    $sid = (int)$sprint['id'];

    $three = reportTaskId($f['owner'], $p, ['title' => 'งาน 3', 'story_points' => 3, 'sprint_id' => $sid]);
    $five = reportTaskId($f['owner'], $p, ['title' => 'งาน 5', 'story_points' => 5, 'sprint_id' => $sid]);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$sid}/start", [])['status']);
    assertSame(8, (int)reportToday($sid)['scope_points']);

    reportTaskId($f['owner'], $p, ['title' => 'งานใหม่ 2', 'story_points' => 2, 'sprint_id' => $sid]);
    assertSame(10, (int)reportToday($sid)['scope_points'], 'a 2-point task joins the active sprint');

    $growth = $f['owner']->request('PUT', "/api/projects/tasks/{$five}", ['story_points' => 8]);
    assertSame(200, $growth['status'], $growth['body']);
    assertSame(13, (int)reportToday($sid)['scope_points'], '5 became 8, so scope rose by 3');
    assertSame(3, (int)DB::run('SELECT story_points FROM project_tasks WHERE id = ?', [$three])->fetchColumn());
});

test('regrouping a doing column into done counts the points it holds', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'ย้ายกลุ่ม');
    $sprint = reportSprint($f['owner'], $p, [
        'name' => 'รอบย้ายกลุ่ม',
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d'),
    ]);
    $sid = (int)$sprint['id'];

    $doing = reportColumnId($p, 'doing');
    reportTaskId($f['owner'], $p, ['title' => 'งานใน doing', 'story_points' => 5, 'sprint_id' => $sid, 'column_id' => $doing]);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$sid}/start", [])['status']);
    assertSame(0, (int)reportToday($sid)['done_points']);

    $regroup = $f['owner']->request('PUT', "/api/projects/columns/{$doing}", ['grp' => 'done']);
    assertSame(200, $regroup['status'], $regroup['body']);
    assertSame(5, (int)reportToday($sid)['done_points'], 'the regrouping made its task done');
});

test('burndown carries each recorded row forward until the next one', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'burndown');
    $start = date('Y-m-d', strtotime('-4 days'));
    $sprint = reportSprint($f['owner'], $p, [
        'name' => 'รอบ burndown',
        'start_date' => $start,
        'end_date' => date('Y-m-d', strtotime('+3 days')),
    ]);
    $sid = (int)$sprint['id'];

    reportTaskId($f['owner'], $p, ['title' => 'งาน 5', 'story_points' => 5, 'sprint_id' => $sid]);
    reportTaskId($f['owner'], $p, ['title' => 'งาน 3', 'story_points' => 3, 'sprint_id' => $sid]);
    reportTaskId($f['owner'], $p, ['title' => 'งาน 2', 'story_points' => 2, 'sprint_id' => $sid]);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$sid}/start", [])['status']);

    DB::run(
        'INSERT INTO project_sprint_days (sprint_id, `day`, scope_points, done_points) VALUES (?, ?, 10, 0), (?, ?, 10, 4)
         ON DUPLICATE KEY UPDATE scope_points = VALUES(scope_points), done_points = VALUES(done_points)',
        [$sid, date('Y-m-d', strtotime('-4 days')), $sid, date('Y-m-d', strtotime('-2 days'))]
    );

    $res = $f['owner']->get("/api/projects/{$p}/reports?sprint={$sid}");
    assertSame(200, $res['status'], $res['body']);
    $burndown = $f['owner']->json($res)['burndown'];
    assertSame($sid, (int)$burndown['sprint']['id']);
    assertSame(5, count($burndown['days']), 'from start to today, capped at today');

    $expectedDays = [];
    $expectedDone = [0, 0, 4, 4, (int)reportToday($sid)['done_points']];
    for ($i = 4; $i >= 0; $i--) {
        $expectedDays[] = date('Y-m-d', strtotime("-{$i} days"));
    }
    assertSame($expectedDays, array_column($burndown['days'], 'day'));
    assertSame($expectedDone, array_column($burndown['days'], 'done'));
    foreach ($burndown['days'] as $day) {
        assertSame(10, (int)$day['scope'], 'scope stays 10 on every day');
        assertSame((int)$day['scope'] - (int)$day['done'], (int)$day['remaining']);
    }
});

test('closing a sprint keeps the row it recorded before the unfinished work moved out', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'ปิดรอบ');
    $sprint = reportSprint($f['owner'], $p, [
        'name' => 'รอบปิด',
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d'),
    ]);
    $sid = (int)$sprint['id'];

    $three = reportTaskId($f['owner'], $p, ['title' => 'งาน 3', 'story_points' => 3, 'sprint_id' => $sid]);
    reportTaskId($f['owner'], $p, ['title' => 'งาน 5', 'story_points' => 5, 'sprint_id' => $sid]);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$sid}/start", [])['status']);
    $done = reportColumnId($p, 'done');
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$three}", ['column_id' => $done])['status']);
    assertSame(3, (int)reportToday($sid)['done_points']);

    $closed = $f['owner']->post("/api/projects/sprints/{$sid}/close", ['carry' => 'backlog']);
    assertSame(200, $closed['status'], $closed['body']);

    $row = reportToday($sid);
    assertSame(8, (int)$row['scope_points'], 'the close did not rewrite scope');
    assertSame(3, (int)$row['done_points'], 'nor done');

    $res = $f['owner']->get("/api/projects/{$p}/reports?sprint={$sid}");
    assertSame(200, $res['status'], $res['body']);
    $burndown = $f['owner']->json($res)['burndown'];
    assertSame('closed', $burndown['sprint']['state']);
    assertSame(1, count($burndown['days']));
    assertSame(8, (int)$burndown['days'][0]['scope']);
    assertSame(3, (int)$burndown['days'][0]['done']);
});

test('velocity lists the last five closed sprints oldest first and averages the last three', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'velocity');
    $dates = ['start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d')];
    $done = reportColumnId($p, 'done');
    $closed = [];

    $first = reportSprint($f['owner'], $p, ['name' => 'รอบหนึ่ง'] + $dates);
    $firstId = (int)$first['id'];
    $firstTask = reportTaskId($f['owner'], $p, ['title' => 'งาน 5', 'story_points' => 5, 'sprint_id' => $firstId]);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$firstId}/start", [])['status']);
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$firstTask}", ['column_id' => $done])['status']);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$firstId}/close", ['carry' => 'backlog'])['status']);
    $closed[] = $firstId;

    $second = reportSprint($f['owner'], $p, ['name' => 'รอบสอง'] + $dates);
    $secondId = (int)$second['id'];
    $secondDone = reportTaskId($f['owner'], $p, ['title' => 'งาน 3', 'story_points' => 3, 'sprint_id' => $secondId]);
    reportTaskId($f['owner'], $p, ['title' => 'งาน 2', 'story_points' => 2, 'sprint_id' => $secondId]);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$secondId}/start", [])['status']);
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$secondDone}", ['column_id' => $done])['status']);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$secondId}/close", ['carry' => 'backlog'])['status']);
    $closed[] = $secondId;

    $res = $f['owner']->get("/api/projects/{$p}/reports");
    assertSame(200, $res['status'], $res['body']);
    $velocity = $f['owner']->json($res)['velocity'];

    assertSame($closed, array_column($velocity['sprints'], 'id'), 'oldest closed sprint first');
    assertSame(5, $velocity['sprints'][0]['committed']);
    assertSame(5, $velocity['sprints'][0]['completed']);
    assertSame(5, $velocity['sprints'][1]['committed']);
    assertSame(3, $velocity['sprints'][1]['completed']);
    assertSame(4, $velocity['average'], 'round((5 + 3) / 2)');
});

test('reports hide bad sprint ids, accept a viewer and refuse an outsider', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'สิทธิ์รายงาน');
    $planned = reportSprint($f['owner'], $p, ['name' => 'รอบวางแผน']);
    $plannedId = (int)$planned['id'];

    $empty = $f['owner']->get("/api/projects/{$p}/reports?sprint={$plannedId}");
    assertSame(200, $empty['status'], $empty['body']);
    $burndown = $f['owner']->json($empty)['burndown'];
    assertSame($plannedId, (int)$burndown['sprint']['id']);
    assertSame([], $burndown['days'], 'a planned sprint has no recorded days');

    $other = reportProject($f, 'โปรเจคอื่น');
    $foreignId = (int)reportSprint($f['owner'], $other, ['name' => 'รอบอื่น'])['id'];

    $deleted = reportSprint($f['owner'], $p, ['name' => 'รอบจะลบ']);
    $deletedId = (int)$deleted['id'];
    assertSame(200, $f['owner']->request('DELETE', "/api/projects/sprints/{$deletedId}", [])['status']);

    $foreign = $f['owner']->get("/api/projects/{$p}/reports?sprint={$foreignId}");
    $gone = $f['owner']->get("/api/projects/{$p}/reports?sprint={$deletedId}");
    $garbage = $f['owner']->get("/api/projects/{$p}/reports?sprint=abc");
    assertSame(404, $foreign['status'], $foreign['body']);
    assertSame($foreign['status'], $gone['status'], 'a deleted sprint answers like a foreign one');
    assertSame($foreign['body'], $gone['body'], 'and the body does not reveal which id exists');
    assertSame($foreign['status'], $garbage['status'], 'garbage answers the same too');
    assertSame($foreign['body'], $garbage['body']);

    $viewer = $f['viewer']->get("/api/projects/{$p}/reports");
    assertSame(200, $viewer['status'], $viewer['body']);

    $outsiderReport = $f['outsider']->get("/api/projects/{$p}/reports");
    $outsiderTasks = $f['outsider']->get("/api/projects/{$p}/tasks");
    assertSame(404, $outsiderReport['status'], $outsiderReport['body']);
    assertSame($outsiderTasks['status'], $outsiderReport['status'], 'a non-member answers like other project APIs');
    assertSame($outsiderTasks['body'], $outsiderReport['body']);
});

test('a sprint started before its start_date still shows today', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'เริ่มก่อนวันเริ่ม');
    $sprint = reportSprint($f['owner'], $p, [
        'name' => 'รอบเริ่มก่อน',
        'start_date' => date('Y-m-d', strtotime('+3 days')),
        'end_date' => date('Y-m-d', strtotime('+16 days')),
    ]);
    $sid = (int)$sprint['id'];

    reportTaskId($f['owner'], $p, ['title' => 'งาน 5', 'story_points' => 5, 'sprint_id' => $sid]);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$sid}/start", [])['status']);

    $res = $f['owner']->get("/api/projects/{$p}/reports");
    assertSame(200, $res['status'], $res['body']);
    $days = $f['owner']->json($res)['burndown']['days'];
    assertSame(1, count($days), 'today is the only day shown');
    assertSame(date('Y-m-d'), $days[0]['day']);
    assertSame(5, (int)$days[0]['scope'], 'the real scope of today is shown');
});

test('an active sprint past its end_date keeps showing days through today', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'เลยวันจบ');
    $sprint = reportSprint($f['owner'], $p, [
        'name' => 'รอบเลยวันจบ',
        'start_date' => date('Y-m-d', strtotime('-6 days')),
        'end_date' => date('Y-m-d', strtotime('-2 days')),
    ]);
    $sid = (int)$sprint['id'];

    $task = reportTaskId($f['owner'], $p, ['title' => 'งาน 5', 'story_points' => 5, 'sprint_id' => $sid]);
    assertSame(200, $f['owner']->post("/api/projects/sprints/{$sid}/start", [])['status']);
    $done = reportColumnId($p, 'done');
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$task}", ['column_id' => $done])['status']);

    DB::run(
        'INSERT INTO project_sprint_days (sprint_id, `day`, scope_points, done_points) VALUES (?, ?, 5, 0)
         ON DUPLICATE KEY UPDATE scope_points = VALUES(scope_points), done_points = VALUES(done_points)',
        [$sid, date('Y-m-d', strtotime('-6 days'))]
    );

    $res = $f['owner']->get("/api/projects/{$p}/reports");
    assertSame(200, $res['status'], $res['body']);
    $days = $f['owner']->json($res)['burndown']['days'];
    assertSame(7, count($days), 'today-6 through today');
    assertSame(date('Y-m-d', strtotime('-6 days')), $days[0]['day']);
    assertSame(date('Y-m-d'), $days[6]['day'], 'the last day is today');
    assertSame(5, (int)$days[6]['done'], 'the last day is today\'s real row');
});

test('a project without sprints reports null burndown and empty velocity', function (TestClient $_c): void {
    $f = reportFixture();
    $p = reportProject($f, 'ไม่มีรอบ', 'general');

    $res = $f['owner']->get("/api/projects/{$p}/reports");
    assertSame(200, $res['status'], $res['body']);
    assertSame(
        ['burndown' => null, 'velocity' => ['sprints' => [], 'average' => null]],
        $f['owner']->json($res)
    );
});
