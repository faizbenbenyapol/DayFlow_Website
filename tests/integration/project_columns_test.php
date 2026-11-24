<?php
// =====================================================
// tests/integration/project_columns_test.php
//
// คอลัมน์บอร์ดคือความจริงเดียวของงาน (column_id) ส่วน status เดิมเป็นเงา
// ชุดนี้จึงตรวจทั้ง CRUD ของคอลัมน์ สิทธิ์ของแต่ละบทบาท และกฎที่ห้าม
// ทำให้กลุ่มใดกลุ่มหนึ่งว่าง รวมถึงการแปลสถานะเดิมของ client เก่า
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * สร้างบัญชี owner/editor/viewer/outsider ครั้งเดียวและใช้ร่วมกันทุกเคส
 *
 * @return array<string, TestClient|string>
 */
function columnUsers(): array
{
    static $users = null;
    if ($users !== null) return $users;

    $password = 'TestPass123!';
    $make = static function (string $role) use ($password): array {
        $username = 'col_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, $password);
        return [$client, $username];
    };

    [$owner] = $make('owner');
    [$editor, $editorU] = $make('editor');
    [$viewer, $viewerU] = $make('viewer');
    [$outsider] = $make('outsider');

    return $users = [
        'owner' => $owner,
        'editor' => $editor,
        'viewer' => $viewer,
        'outsider' => $outsider,
        'editorU' => $editorU,
        'viewerU' => $viewerU,
    ];
}

/**
 * สร้างโปรเจคใหม่ผ่าน API และคืนรหัส (โยนเมื่อไม่ใช่ 201)
 *
 * @param array<string, TestClient|string> $users
 */
function columnProject(array $users, string $type = 'general'): int
{
    $owner = $users['owner'];
    $response = $owner->post('/api/projects', [
        'name' => 'คอลัมน์ ' . $type . ' ' . bin2hex(random_bytes(3)) . ' ' . TEST_RUN_ID,
        'type' => $type,
        'status' => 'Planning',
        'priority' => 'Medium',
    ]);
    if ($response['status'] !== 201) {
        throw new RuntimeException('project create failed: ' . $response['body']);
    }
    return (int)($owner->json($response)['id'] ?? 0);
}

/**
 * ดึงรายการคอลัมน์จาก GET /api/projects/{id}/tasks
 *
 * @return array<int, array<string, mixed>>
 */
function columnList(TestClient $client, int $projectId): array
{
    $response = $client->get("/api/projects/{$projectId}/tasks");
    if ($response['status'] !== 200) {
        throw new RuntimeException('task list failed: ' . $response['body']);
    }
    return $client->json($response)['columns'] ?? [];
}

/**
 * สร้างงานในคอลัมน์ที่ระบุและคืนรหัสงาน
 */
function columnTaskCreate(TestClient $client, int $projectId, string $title, int $columnId): int
{
    $response = $client->post("/api/projects/{$projectId}/tasks", [
        'title' => $title,
        'column_id' => $columnId,
        'priority' => 'Medium',
    ]);
    if ($response['status'] !== 201) {
        throw new RuntimeException('task create failed: ' . $response['body']);
    }
    return (int)($client->json($response)['id'] ?? 0);
}

/**
 * อ่านแถวงานจากฐานข้อมูลตามรหัส
 *
 * @return array<string, mixed>
 */
function columnDbTask(int $taskId): array
{
    return DB::run(
        'SELECT id, project_id, column_id, position, status, updated_at FROM project_tasks WHERE id = ?',
        [$taskId]
    )->fetch() ?: [];
}

/**
 * ตรวจสถานะเงาของทุกงานให้ตรงกฎเป๊ะ ๆ: done → Done, todo → To Do,
 * doing → Review เฉพาะคอลัมน์ doing สุดท้าย (position/id มากสุด) นอกนั้น In Progress
 */
function columnAssertShadow(int $projectId): void
{
    $columns = DB::run(
        'SELECT id, grp, position FROM project_columns WHERE project_id = ?',
        [$projectId]
    )->fetchAll();

    $lastDoingId = 0;
    $lastDoingPosition = -1;
    foreach ($columns as $column) {
        if ($column['grp'] !== 'doing') continue;
        $position = (int)$column['position'];
        $id = (int)$column['id'];
        if ($position > $lastDoingPosition || ($position === $lastDoingPosition && $id > $lastDoingId)) {
            $lastDoingPosition = $position;
            $lastDoingId = $id;
        }
    }

    $tasks = DB::run(
        'SELECT t.id, t.status, t.column_id, c.grp FROM project_tasks t
         JOIN project_columns c ON c.id = t.column_id
         WHERE t.project_id = ?',
        [$projectId]
    )->fetchAll();

    foreach ($tasks as $task) {
        $grp = (string)$task['grp'];
        if ($grp === 'done') {
            $expected = 'Done';
        } elseif ($grp === 'todo') {
            $expected = 'To Do';
        } else {
            $expected = (int)$task['column_id'] === $lastDoingId ? 'Review' : 'In Progress';
        }
        assertSame($expected, $task['status'], 'task ' . $task['id'] . ' shadow');
    }
}

/**
 * คืนคอลัมน์แรกของกลุ่มจากรายการคอลัมน์
 *
 * @param array<int, array<string, mixed>> $columns
 * @return array<string, mixed>
 */
function columnOfGroup(array $columns, string $grp, bool $last = false): array
{
    $inGroup = array_values(array_filter($columns, static fn(array $c): bool => $c['grp'] === $grp));
    if ($inGroup === []) throw new RuntimeException('no column in group ' . $grp);
    return $last ? $inGroup[count($inGroup) - 1] : $inGroup[0];
}

// --- คอลัมน์ตั้งต้นตามประเภท ---

test('a new project gets exactly the default columns of its type', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];

    $expected = [
        'general' => [['ต้องทำ', 'todo'], ['กำลังทำ', 'doing'], ['รอตรวจ', 'doing'], ['เสร็จแล้ว', 'done']],
        'finance' => [['ต้องทำ', 'todo'], ['กำลังทำ', 'doing'], ['รอตรวจ', 'doing'], ['เสร็จแล้ว', 'done']],
        'software' => [['ต้องทำ', 'todo'], ['กำลังทำ', 'doing'], ['รอรีวิว', 'doing'], ['ทดสอบ', 'doing'], ['เสร็จ', 'done']],
        'marketing' => [['ไอเดีย', 'todo'], ['ร่าง', 'doing'], ['รอตรวจ', 'doing'], ['นัดโพสต์', 'doing'], ['โพสต์แล้ว', 'done']],
    ];

    foreach ($expected as $type => $want) {
        $projectId = columnProject($users, $type);
        $columns = columnList($owner, $projectId);
        $got = array_map(static fn(array $c): array => [(string)$c['name'], (string)$c['grp']], $columns);
        assertSame($want, $got, "{$type} default columns");

        $detail = $owner->json($owner->get("/api/projects/{$projectId}/tasks"));
        assertSame($type, $detail['project']['type'] ?? null, "{$type} is returned by getById");

        $list = $owner->json($owner->get('/api/projects'));
        $mine = array_values(array_filter(
            $list['projects'] ?? [],
            static fn(array $p): bool => (int)$p['id'] === $projectId
        ));
        assertSame($type, $mine[0]['type'] ?? null, "{$type} is returned by the list");
    }
});

test('an unknown project type is refused with 422', function (TestClient $_c): void {
    $users = columnUsers();
    $response = $users['owner']->post('/api/projects', [
        'name' => 'ประเภทผิด ' . TEST_RUN_ID,
        'type' => 'not-a-type',
    ]);
    assertSame(422, $response['status'], $response['body']);
});

// --- ลำดับของคอลัมน์ ---

test('a column is appended at the end of its group and can be renamed', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);

    $created = $owner->post("/api/projects/{$projectId}/columns", ['name' => 'คิวงาน', 'grp' => 'doing']);
    assertSame(201, $created['status'], $created['body']);
    $columnId = (int)($owner->json($created)['id'] ?? 0);
    assertTrue($columnId > 0, 'new column id');

    $columns = columnList($owner, $projectId);
    $doing = array_values(array_filter($columns, static fn(array $c): bool => $c['grp'] === 'doing'));
    assertSame('คิวงาน', $doing[count($doing) - 1]['name'], 'appended at the end of doing');

    $renamed = $owner->request('PUT', "/api/projects/columns/{$columnId}", ['name' => 'คิวใหม่']);
    assertSame(200, $renamed['status'], $renamed['body']);
    $columns = columnList($owner, $projectId);
    $found = array_values(array_filter($columns, static fn(array $c): bool => (int)$c['id'] === $columnId));
    assertSame('คิวใหม่', $found[0]['name'] ?? null, 'renamed');
    columnAssertShadow($projectId);

    // ย้ายคอลัมน์ข้ามกลุ่ม: งานในคอลัมน์ต้องเปลี่ยนสถานะตามกลุ่มใหม่ แต่เวลาแก้ไขไม่เปลี่ยน
    $taskId = columnTaskCreate($owner, $projectId, 'งานในคิว', $columnId);
    $before = columnDbTask($taskId);
    // คิวใหม่ต่อท้ายกลุ่ม doing จึงเป็นคอลัมน์ doing สุดท้าย → Review
    assertSame('Review', $before['status'], 'a task in the last doing column');

    $regrouped = $owner->request('PUT', "/api/projects/columns/{$columnId}", ['grp' => 'todo']);
    assertSame(200, $regrouped['status'], $regrouped['body']);
    $columns = columnList($owner, $projectId);
    $todo = array_values(array_filter($columns, static fn(array $c): bool => $c['grp'] === 'todo'));
    assertSame('คิวใหม่', $todo[count($todo) - 1]['name'], 'regrouped to the end of todo');

    $after = columnDbTask($taskId);
    assertSame('To Do', $after['status'], 'the shadow follows the new group');
    assertSame($before['updated_at'], $after['updated_at'], 'regrouping a column does not touch task updated_at');
    columnAssertShadow($projectId);
});

test('reordering a group needs exactly its columns', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);

    $columns = columnList($owner, $projectId);
    $doing = array_values(array_filter($columns, static fn(array $c): bool => $c['grp'] === 'doing'));
    $doingIds = array_map(static fn(array $c): int => (int)$c['id'], $doing);

    // ไม่ครบทุกคอลัมน์ของกลุ่ม → 422
    $short = $owner->post("/api/projects/{$projectId}/columns/reorder", ['grp' => 'doing', 'ids' => [$doingIds[0]]]);
    assertSame(422, $short['status'], $short['body']);

    $reversed = array_reverse($doingIds);
    assertSame(200, $owner->post("/api/projects/{$projectId}/columns/reorder", ['grp' => 'doing', 'ids' => $reversed])['status']);
    $after = columnList($owner, $projectId);
    $doingAfter = array_values(array_filter($after, static fn(array $c): bool => $c['grp'] === 'doing'));
    assertSame($reversed, array_map(static fn(array $c): int => (int)$c['id'], $doingAfter), 'order follows the request');

    assertSame(200, $owner->post("/api/projects/{$projectId}/columns/reorder", ['grp' => 'doing', 'ids' => $doingIds])['status']);
    columnAssertShadow($projectId);
});

// --- งานที่ถูกย้ายตอนลบคอลัมน์ ---

test('deleting a column moves its tasks in order and keeps updated_at', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);

    $columns = columnList($owner, $projectId);
    $doing1 = columnOfGroup($columns, 'doing');
    $doing2 = columnOfGroup($columns, 'doing', true);

    $taskA = columnTaskCreate($owner, $projectId, 'งาน A', (int)$doing1['id']);
    $taskB = columnTaskCreate($owner, $projectId, 'งาน B', (int)$doing1['id']);
    $taskC = columnTaskCreate($owner, $projectId, 'งาน C', (int)$doing2['id']);

    $beforeA = columnDbTask($taskA);
    $beforeB = columnDbTask($taskB);
    assertTrue($beforeA['updated_at'] !== null, 'task A has updated_at');

    $deleted = $owner->request('DELETE', "/api/projects/columns/{$doing1['id']}", ['move_to' => (int)$doing2['id']]);
    assertSame(200, $deleted['status'], $deleted['body']);

    $afterA = columnDbTask($taskA);
    $afterB = columnDbTask($taskB);
    $afterC = columnDbTask($taskC);

    assertSame((int)$doing2['id'], (int)$afterA['column_id'], 'A moved to the target column');
    assertSame((int)$doing2['id'], (int)$afterB['column_id'], 'B moved to the target column');
    assertTrue((int)$afterC['position'] < (int)$afterA['position'], 'A is appended after C');
    assertTrue((int)$afterA['position'] < (int)$afterB['position'], 'A stays before B');
    assertSame($beforeA['updated_at'], $afterA['updated_at'], 'A keeps its updated_at');
    assertSame($beforeB['updated_at'], $afterB['updated_at'], 'B keeps its updated_at');
    assertSame('Review', $afterA['status'], 'A now sits in the last doing column');

    $columnsAfter = columnList($owner, $projectId);
    assertSame([], array_values(array_filter($columnsAfter, static fn(array $c): bool => (int)$c['id'] === (int)$doing1['id'])), 'the column is gone');

    columnAssertShadow($projectId);
});

// --- EDGE-P2: ทุกกลุ่มต้องเหลืออย่างน้อย 1 คอลัมน์ ---

test('deleting or regrouping the last column of a group is refused', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users, 'software');

    $columns = columnList($owner, $projectId);
    $done = columnOfGroup($columns, 'done');

    $deleted = $owner->request('DELETE', "/api/projects/columns/{$done['id']}", []);
    assertSame(422, $deleted['status'], $deleted['body']);
    assertStringContains('แต่ละกลุ่มต้องมีอย่างน้อย 1 คอลัมน์', $deleted['body']);

    $regrouped = $owner->request('PUT', "/api/projects/columns/{$done['id']}", ['grp' => 'doing']);
    assertSame(422, $regrouped['status'], $regrouped['body']);
    assertStringContains('แต่ละกลุ่มต้องมีอย่างน้อย 1 คอลัมน์', $regrouped['body']);

    // ของเดิมยังอยู่ครบ
    assertSame(count($columns), count(columnList($owner, $projectId)));
});

// --- สิทธิ์ของแต่ละบทบาท ---

test('Editor and Viewer cannot touch columns, an outsider sees nothing', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);

    // เพิ่ม editor/viewer เข้าโปรเจคนี้
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $users['editorU'], 'role' => 'Editor']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $users['viewerU'], 'role' => 'Viewer']);

    $columns = columnList($owner, $projectId);
    $doing = columnOfGroup($columns, 'doing');
    $doingIds = array_map(
        static fn(array $c): int => (int)$c['id'],
        array_values(array_filter($columns, static fn(array $c): bool => $c['grp'] === 'doing'))
    );

    foreach (['editor', 'viewer'] as $who) {
        $client = $users[$who];
        assertSame(403, $client->post("/api/projects/{$projectId}/columns", ['name' => 'x', 'grp' => 'todo'])['status'], "{$who} create");
        assertSame(403, $client->request('PUT', "/api/projects/columns/{$doing['id']}", ['name' => 'x'])['status'], "{$who} update");
        assertSame(403, $client->post("/api/projects/{$projectId}/columns/reorder", ['grp' => 'doing', 'ids' => $doingIds])['status'], "{$who} reorder");
        assertSame(403, $client->request('DELETE', "/api/projects/columns/{$doing['id']}", [])['status'], "{$who} delete");
    }

    // คนนอก: คอลัมน์ที่มีอยู่กับคอลัมน์ที่ไม่มีอยู่ต้องตอบเหมือนกันทุกไบต์
    $outsider = $users['outsider'];
    $existing = $outsider->request('PUT', "/api/projects/columns/{$doing['id']}", ['name' => 'x']);
    $missing = $outsider->request('PUT', '/api/projects/columns/999999999', ['name' => 'x']);
    assertSame(404, $existing['status'], $existing['body']);
    assertSame($missing['status'], $existing['status'], 'same status');
    assertSame($missing['body'], $existing['body'], 'same body');

    $existingDelete = $outsider->request('DELETE', "/api/projects/columns/{$doing['id']}", []);
    $missingDelete = $outsider->request('DELETE', '/api/projects/columns/999999999', []);
    assertSame($missingDelete['status'], $existingDelete['status'], 'same delete status');
    assertSame($missingDelete['body'], $existingDelete['body'], 'same delete body');

    // โปรเจคที่มองไม่เห็นใช้ 404 ของโปรเจคเดิม
    $existingProject = $outsider->post("/api/projects/{$projectId}/columns", ['name' => 'x', 'grp' => 'todo']);
    $missingProject = $outsider->post('/api/projects/999999999/columns', ['name' => 'x', 'grp' => 'todo']);
    assertSame(404, $existingProject['status'], $existingProject['body']);
    assertSame($missingProject['body'], $existingProject['body'], 'same project 404 body');

    // ไม่มีอะไรถูกเขียนจากความพยายามทั้งหมด
    assertSame(count($columns), count(columnList($owner, $projectId)));
});

test('task update and delete hide a task of another project behind the same 404', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);
    $columns = columnList($owner, $projectId);
    $taskId = columnTaskCreate($owner, $projectId, 'งานลับ', (int)$columns[0]['id']);

    $outsider = $users['outsider'];
    $foreignUpdate = $outsider->request('PUT', "/api/projects/tasks/{$taskId}", ['title' => 'x']);
    $missingUpdate = $owner->request('PUT', '/api/projects/tasks/999999999', ['title' => 'x']);
    assertSame(404, $foreignUpdate['status'], $foreignUpdate['body']);
    assertSame($missingUpdate['status'], $foreignUpdate['status'], 'same update status');
    assertSame($missingUpdate['body'], $foreignUpdate['body'], 'same update body');

    $foreignDelete = $outsider->request('DELETE', "/api/projects/tasks/{$taskId}", []);
    $missingDelete = $owner->request('DELETE', '/api/projects/tasks/999999999', []);
    assertSame(404, $foreignDelete['status'], $foreignDelete['body']);
    assertSame($missingDelete['status'], $foreignDelete['status'], 'same delete status');
    assertSame($missingDelete['body'], $foreignDelete['body'], 'same delete body');

    // งานยังอยู่จริง
    assertSame($taskId, (int)columnDbTask($taskId)['id'], 'the task was not deleted');
});

// --- การแก้ไขงานที่ไม่เปลี่ยนคอลัมน์ ---

test('editing a task without changing its column keeps its position', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);
    $columns = columnList($owner, $projectId);
    $todo = columnOfGroup($columns, 'todo');
    $doing = columnOfGroup($columns, 'doing');

    $taskA = columnTaskCreate($owner, $projectId, 'งาน A', (int)$todo['id']);
    columnTaskCreate($owner, $projectId, 'งาน B', (int)$todo['id']);
    $before = columnDbTask($taskA);
    assertSame(0, (int)$before['position'], 'A starts at the top of its column');

    // บอร์ดเดิมส่ง status เดิมมาทุกครั้งที่บันทึก: ต้องไม่ย้ายงานลงล่าง
    $legacy = $owner->request('PUT', "/api/projects/tasks/{$taskA}", ['title' => 'A edited', 'status' => 'To Do']);
    assertSame(200, $legacy['status'], $legacy['body']);
    $afterLegacy = columnDbTask($taskA);
    assertSame((int)$before['position'], (int)$afterLegacy['position'], 'unchanged legacy status keeps position');
    assertSame((int)$before['column_id'], (int)$afterLegacy['column_id'], 'unchanged legacy status keeps column');

    // ส่ง column_id เดิมมาก็ต้องไม่ย้ายเช่นกัน
    $explicit = $owner->request('PUT', "/api/projects/tasks/{$taskA}", ['title' => 'A edited again', 'column_id' => (int)$todo['id']]);
    assertSame(200, $explicit['status'], $explicit['body']);
    $afterExplicit = columnDbTask($taskA);
    assertSame((int)$before['position'], (int)$afterExplicit['position'], 'unchanged column_id keeps position');
    assertSame((int)$before['column_id'], (int)$afterExplicit['column_id'], 'unchanged column_id keeps column');

    // เปลี่ยนคอลัมน์จริงยังต้องย้าย
    $moved = $owner->request('PUT', "/api/projects/tasks/{$taskA}", ['column_id' => (int)$doing['id']]);
    assertSame(200, $moved['status'], $moved['body']);
    $afterMove = columnDbTask($taskA);
    assertSame((int)$doing['id'], (int)$afterMove['column_id'], 'a real column change moves the task');

    columnAssertShadow($projectId);
});

// --- สถานะเดิมของ client เก่า ---

test('legacy status on create, update and reorder lands in the mapped column', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);
    $columns = columnList($owner, $projectId);
    $todo = columnOfGroup($columns, 'todo');
    $doingFirst = columnOfGroup($columns, 'doing');
    $doingLast = columnOfGroup($columns, 'doing', true);
    $done = columnOfGroup($columns, 'done');

    $cases = [
        ['To Do', $todo, 'To Do'],
        ['In Progress', $doingFirst, 'In Progress'],
        ['Review', $doingLast, 'Review'],
        ['Done', $done, 'Done'],
    ];
    $created = [];
    foreach ($cases as [$status, $column, $expectStatus]) {
        $response = $owner->post("/api/projects/{$projectId}/tasks", ['title' => 'งาน ' . $status, 'status' => $status]);
        assertSame(201, $response['status'], $response['body']);
        $task = columnDbTask((int)($owner->json($response)['id'] ?? 0));
        assertSame((int)$column['id'], (int)$task['column_id'], "{$status} maps to its column");
        assertSame($expectStatus, $task['status'], "{$status} keeps a matching shadow");
        $created[$status] = (int)$task['id'];
    }

    // อัปเดตด้วยสถานะเดิม
    assertSame(200, $owner->request('PUT', '/api/projects/tasks/' . $created['To Do'], ['status' => 'Review'])['status']);
    $moved = columnDbTask($created['To Do']);
    assertSame((int)$doingLast['id'], (int)$moved['column_id'], 'update moves to the last doing column');
    // คอลัมน์ doing สุดท้าย → Review เสมอ ไม่ขึ้นกับสถานะเดิม
    assertSame('Review', $moved['status'], 'the last doing column always gives Review');

    assertSame(200, $owner->request('PUT', '/api/projects/tasks/' . $created['To Do'], ['status' => 'To Do'])['status']);
    $moved = columnDbTask($created['To Do']);
    assertSame((int)$todo['id'], (int)$moved['column_id'], 'update moves back to the first todo column');
    assertSame('To Do', $moved['status']);

    // จัดลำดับด้วยสถานะเดิม
    $x = columnTaskCreate($owner, $projectId, 'งาน X', (int)$todo['id']);
    $y = columnTaskCreate($owner, $projectId, 'งาน Y', (int)$todo['id']);
    $reorder = $owner->post('/api/projects/tasks/reorder', [
        'project_id' => $projectId,
        'items' => [
            ['id' => $x, 'status' => 'Done', 'position' => 0],
            ['id' => $y, 'status' => 'In Progress', 'position' => 0],
        ],
    ]);
    assertSame(200, $reorder['status'], $reorder['body']);
    assertSame((int)$done['id'], (int)columnDbTask($x)['column_id'], 'reorder maps Done');
    assertSame('Done', columnDbTask($x)['status']);
    assertSame((int)$doingFirst['id'], (int)columnDbTask($y)['column_id'], 'reorder maps In Progress');
    assertSame('In Progress', columnDbTask($y)['status']);

    // สถานะที่ไม่รู้จัก
    assertSame(422, $owner->post("/api/projects/{$projectId}/tasks", ['title' => 'ผิด', 'status' => 'Nope'])['status']);
    assertSame(422, $owner->request('PUT', '/api/projects/tasks/' . $x, ['status' => 'Nope'])['status']);

    columnAssertShadow($projectId);
});

// --- สถานะเงาเป็นฟังก์ชันของคอลัมน์ ---

test('current board status changes land in the matching column with the pure shadow', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);
    $columns = columnList($owner, $projectId);
    $todo = columnOfGroup($columns, 'todo');
    $doingFirst = columnOfGroup($columns, 'doing');
    $doingLast = columnOfGroup($columns, 'doing', true);

    // บั๊กเดิม: todo → รอตรวจ ได้ column ถูกแต่ status เป็น In Progress
    $taskId = columnTaskCreate($owner, $projectId, 'งาน B', (int)$todo['id']);
    assertSame(200, $owner->request('PUT', "/api/projects/tasks/{$taskId}", ['status' => 'Review'])['status']);
    $moved = columnDbTask($taskId);
    assertSame((int)$doingLast['id'], (int)$moved['column_id'], 'Review maps to the last doing column');
    assertSame('Review', $moved['status'], 'the last doing column gives Review');

    assertSame(200, $owner->request('PUT', "/api/projects/tasks/{$taskId}", ['status' => 'To Do'])['status']);
    $back = columnDbTask($taskId);
    assertSame((int)$todo['id'], (int)$back['column_id'], 'To Do maps to the first todo column');
    assertSame('To Do', $back['status']);

    // ลากการ์ดบนบอร์ดเดิมส่ง status ผ่าน reorder ก็ต้องได้ผลเดียวกัน
    $x = columnTaskCreate($owner, $projectId, 'งาน X', (int)$todo['id']);
    $y = columnTaskCreate($owner, $projectId, 'งาน Y', (int)$todo['id']);
    $reorderReview = $owner->post('/api/projects/tasks/reorder', [
        'project_id' => $projectId,
        'items' => [['id' => $x, 'status' => 'Review', 'position' => 0]],
    ]);
    assertSame(200, $reorderReview['status'], $reorderReview['body']);
    assertSame((int)$doingLast['id'], (int)columnDbTask($x)['column_id'], 'reorder Review maps to the last doing column');
    assertSame('Review', columnDbTask($x)['status'], 'reorder Review gives Review');

    $reorderProgress = $owner->post('/api/projects/tasks/reorder', [
        'project_id' => $projectId,
        'items' => [['id' => $y, 'status' => 'In Progress', 'position' => 0]],
    ]);
    assertSame(200, $reorderProgress['status'], $reorderProgress['body']);
    assertSame((int)$doingFirst['id'], (int)columnDbTask($y)['column_id'], 'reorder In Progress maps to the first doing column');
    assertSame('In Progress', columnDbTask($y)['status']);

    columnAssertShadow($projectId);
});

test('adding or reordering a doing column flips the shadow without touching updated_at', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);
    $columns = columnList($owner, $projectId);
    $doing = array_values(array_filter($columns, static fn(array $c): bool => $c['grp'] === 'doing'));
    $doingFirst = $doing[0];
    $doingLast = $doing[count($doing) - 1];

    // งานในคอลัมน์ doing สุดท้ายเริ่มที่ Review
    $taskId = columnTaskCreate($owner, $projectId, 'งานในรอตรวจ', (int)$doingLast['id']);
    $before = columnDbTask($taskId);
    assertSame('Review', $before['status'], 'the current last doing column gives Review');

    // เพิ่มคอลัมน์ doing ต่อท้าย → คอลัมน์เดิมไม่ใช่สุดท้ายแล้ว งานต้องกลายเป็น In Progress
    $created = $owner->post("/api/projects/{$projectId}/columns", ['name' => 'คิวทำต่อ', 'grp' => 'doing']);
    assertSame(201, $created['status'], $created['body']);
    $afterAdd = columnDbTask($taskId);
    assertSame('In Progress', $afterAdd['status'], 'a new last doing column flips the shadow');
    assertSame($before['updated_at'], $afterAdd['updated_at'], 'adding a column does not touch task updated_at');

    // เรียงกลุ่ม doing ให้คอลัมน์เดิมกลับมาเป็นคอลัมน์สุดท้าย → กลับเป็น Review
    $newColumnId = (int)($owner->json($created)['id'] ?? 0);
    $newOrder = [$newColumnId, (int)$doingFirst['id'], (int)$doingLast['id']];
    $reorder = $owner->post("/api/projects/{$projectId}/columns/reorder", ['grp' => 'doing', 'ids' => $newOrder]);
    assertSame(200, $reorder['status'], $reorder['body']);

    $afterReorder = columnDbTask($taskId);
    assertSame('Review', $afterReorder['status'], 'the original column is last again');
    assertSame($before['updated_at'], $afterReorder['updated_at'], 'reordering columns does not touch task updated_at');

    columnAssertShadow($projectId);
});

test('a column id of another project is refused and nothing changes', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectA = columnProject($users);
    $projectB = columnProject($users);
    $columnsA = columnList($owner, $projectA);
    $columnsB = columnList($owner, $projectB);
    $foreignColumn = (int)$columnsB[0]['id'];

    $before = (int)DB::run('SELECT COUNT(*) FROM project_tasks WHERE project_id = ?', [$projectA])->fetchColumn();
    $create = $owner->post("/api/projects/{$projectA}/tasks", ['title' => 'งานข้ามโปรเจค', 'column_id' => $foreignColumn]);
    assertSame(404, $create['status'], $create['body']);
    assertSame('ไม่พบคอลัมน์', (string)($owner->json($create)['error'] ?? ''));
    $after = (int)DB::run('SELECT COUNT(*) FROM project_tasks WHERE project_id = ?', [$projectA])->fetchColumn();
    assertSame($before, $after, 'no task was created');

    $taskId = columnTaskCreate($owner, $projectA, 'งานในโปรเจค A', (int)$columnsA[0]['id']);
    $taskBefore = columnDbTask($taskId);
    $update = $owner->request('PUT', '/api/projects/tasks/' . $taskId, ['column_id' => $foreignColumn]);
    assertSame(404, $update['status'], $update['body']);
    assertSame((int)$taskBefore['column_id'], (int)columnDbTask($taskId)['column_id'], 'the task keeps its column');

    $reorder = $owner->post('/api/projects/tasks/reorder', [
        'project_id' => $projectA,
        'items' => [['id' => $taskId, 'column_id' => $foreignColumn, 'position' => 5]],
    ]);
    assertSame(404, $reorder['status'], $reorder['body']);
    $afterReorder = columnDbTask($taskId);
    assertSame((int)$taskBefore['column_id'], (int)$afterReorder['column_id'], 'nothing changed after a foreign reorder');
    assertSame((int)$taskBefore['position'], (int)$afterReorder['position'], 'position unchanged too');
});

// --- การตรวจชื่อและจำนวนคอลัมน์ ---

test('duplicate, too-long and too-many columns are refused', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);

    assertSame(422, $owner->post("/api/projects/{$projectId}/columns", ['name' => 'ต้องทำ', 'grp' => 'todo'])['status'], 'duplicate name');
    assertSame(422, $owner->post("/api/projects/{$projectId}/columns", ['name' => str_repeat('ก', 61), 'grp' => 'todo'])['status'], 'too long');
    assertSame(422, $owner->post("/api/projects/{$projectId}/columns", ['name' => '   ', 'grp' => 'todo'])['status'], 'empty name');
    assertSame(422, $owner->post("/api/projects/{$projectId}/columns", ['name' => 'กลุ่มผิด', 'grp' => 'nope'])['status'], 'bad group');

    // 4 ของเดิม + 8 ใหม่ = 12 แล้วคอลัมน์ที่ 13 ต้องถูกปฏิเสธ
    for ($i = 5; $i <= 12; $i++) {
        $response = $owner->post("/api/projects/{$projectId}/columns", ['name' => 'คอลัมน์ ' . $i, 'grp' => 'doing']);
        assertSame(201, $response['status'], 'adding column ' . $i . ': ' . $response['body']);
    }
    assertSame(12, count(columnList($owner, $projectId)));
    assertSame(422, $owner->post("/api/projects/{$projectId}/columns", ['name' => 'คอลัมน์ 13', 'grp' => 'doing'])['status'], '13th column');
});

test('column names are compared with utf8mb4_bin', function (TestClient $_c): void {
    $users = columnUsers();
    $owner = $users['owner'];
    $projectId = columnProject($users);

    assertSame(201, $owner->post("/api/projects/{$projectId}/columns", ['name' => 'สมชาย', 'grp' => 'todo'])['status']);
    // unicode_ci มองสองชื่อนี้เป็นชื่อเดียวกัน แต่ utf8mb4_bin ไม่
    assertSame(201, $owner->post("/api/projects/{$projectId}/columns", ['name' => 'สมช้าย', 'grp' => 'todo'])['status']);
    assertSame(422, $owner->post("/api/projects/{$projectId}/columns", ['name' => 'สมชาย', 'grp' => 'todo'])['status']);
});
