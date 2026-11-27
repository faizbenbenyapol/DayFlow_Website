<?php
// =====================================================
// tests/integration/project_roadmap_test.php
//
// แผนงาน (S7): วันเริ่ม (start_date) บันทึก ล้าง และตรวจลำดับกับกำหนดส่ง
// id ของงานคนอื่นตอบเหมือน id ที่ถูกลบ และแขกลิงก์แชร์เห็นวันเริ่มได้ (D-5)
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * ผู้ใช้ของชุดนี้ (เจ้าของ สมาชิก คนนอก) สร้างครั้งเดียวต่อการรัน
 *
 * @return array<string, mixed>
 */
function roadFixture(): array
{
    static $f = null;
    if ($f !== null) return $f;

    $make = static function (string $role): array {
        $username = 'road_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, 'TestPass123!');
        return [$client, $username];
    };
    [$owner] = $make('owner');
    [$member, $memberU] = $make('member');
    [$outsider] = $make('outsider');

    return $f = compact('owner', 'member', 'outsider', 'memberU');
}

/**
 * สร้างโปรเจคซอฟต์แวร์พร้อมสมาชิก แล้วคืนรหัสโปรเจค
 *
 * @param array<string, mixed> $f fixture
 * @param string $label คำกำกับชื่อโปรเจค
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function roadProject(array $f, string $label): int
{
    $res = $f['owner']->post('/api/projects', ['name' => 'แผนงาน ' . $label . ' ' . TEST_RUN_ID, 'type' => 'software']);
    if ($res['status'] !== 201) {
        throw new RuntimeException('project create failed: ' . $res['body']);
    }
    $projectId = (int)($f['owner']->json($res)['id'] ?? 0);
    $invite = $f['owner']->post("/api/projects/{$projectId}/members", ['email_or_username' => $f['memberU'], 'role' => 'Editor']);
    if ($invite['status'] !== 200) {
        throw new RuntimeException('member invite failed: ' . $invite['body']);
    }
    return $projectId;
}

/**
 * สร้างงาน (ปริยายเป็นงานใหญ่) แล้วคืนรหัสงานและสถานะ
 *
 * @param array<string, mixed> $f fixture
 * @param int $projectId รหัสโปรเจค
 * @param array<string, mixed> $extra ค่าเพิ่มเติม เช่น start_date, due_date, kind
 * @return array{id: int, status: int}
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function roadTask(array $f, int $projectId, string $title, array $extra = []): array
{
    $res = $f['owner']->post("/api/projects/{$projectId}/tasks", array_merge(['title' => $title, 'kind' => 'epic'], $extra));
    if ($res['status'] !== 201) {
        throw new RuntimeException('task create failed: ' . $res['body']);
    }
    return ['id' => (int)($f['owner']->json($res)['id'] ?? 0), 'status' => $res['status']];
}

/**
 * วันเริ่มและวันจบที่ฐานข้อมูลเก็บไว้
 *
 * @return array{start_date: string|null, due_date: string|null}
 */
function roadDates(int $taskId): array
{
    /** @var array{start_date: string|null, due_date: string|null} $row */
    $row = DB::run('SELECT start_date, due_date FROM project_tasks WHERE id = ?', [$taskId])->fetch();
    return ['start_date' => $row['start_date'], 'due_date' => $row['due_date']];
}

/**
 * งานหนึ่งแถวจากรายการงานที่ API ส่งให้
 *
 * @param array<mixed> $tasks รายการงาน
 * @return array<string, mixed>|null
 */
function roadFindTask(array $tasks, int $taskId): ?array
{
    foreach ($tasks as $task) {
        if (is_array($task) && (int)($task['id'] ?? 0) === $taskId) return $task;
    }
    return null;
}

test('start_date is stored, cleared, and must be a real date not after the due date', function (TestClient $_c): void {
    $f = roadFixture();
    $p = roadProject($f, 'วัน');
    $task = roadTask($f, $p, 'งานใหญ่มีวัน', ['start_date' => '2026-11-01', 'due_date' => '2026-12-15']);
    $id = $task['id'];
    assertSame(['start_date' => '2026-11-01', 'due_date' => '2026-12-15'], roadDates($id));

    $list = $f['owner']->json($f['owner']->get("/api/projects/{$p}/tasks"));
    $payloadTask = roadFindTask($list['tasks'] ?? [], $id);
    assertSame('2026-11-01', $payloadTask['start_date'] ?? null, 'the task list carries start_date');

    // ส่งวันเริ่มตัวเดียว: เทียบกับกำหนดส่งที่เก็บไว้
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$id}", ['start_date' => '2026-11-20'])['status']);
    assertSame('2026-11-20', roadDates($id)['start_date']);
    assertSame(422, $f['member']->request('PUT', "/api/projects/tasks/{$id}", ['start_date' => '2026-12-20'])['status'], 'start after the stored due');
    assertSame('2026-11-20', roadDates($id)['start_date'], 'a refused start is not saved');
    assertSame(422, $f['member']->request('PUT', "/api/projects/tasks/{$id}", ['due_date' => '2026-10-31'])['status'], 'due before the stored start');
    assertSame(200, $f['member']->request('PUT', "/api/projects/tasks/{$id}", ['due_date' => '2026-12-31'])['status']);
    assertSame('2026-11-20', roadDates($id)['start_date'], 'the start is untouched by a due change');

    foreach (['2026-02-30', '30/11/2026', 20261201] as $bad) {
        $res = $f['owner']->request('PUT', "/api/projects/tasks/{$id}", ['start_date' => $bad]);
        assertSame(422, $res['status'], json_encode($bad) . ' → ' . $res['body']);
    }
    assertSame(422, $f['owner']->post("/api/projects/{$p}/tasks", [
        'title' => 'วันสลับ', 'kind' => 'epic', 'start_date' => '2026-12-01', 'due_date' => '2026-11-01',
    ])['status'], 'create refuses start after due');

    // ล้างวันเริ่มได้ และงานที่มีวันเริ่มอย่างเดียวยังตรวจวันจบใหม่กับค่าเดิมได้
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$id}", ['start_date' => ''])['status']);
    assertSame(null, roadDates($id)['start_date'], 'an empty start clears the column');
    $onlyStart = roadTask($f, $p, 'มีแต่วันเริ่ม', ['start_date' => '2026-10-10']);
    assertSame(['start_date' => '2026-10-10', 'due_date' => null], roadDates($onlyStart['id']));
    assertSame(422, $f['owner']->request('PUT', "/api/projects/tasks/{$onlyStart['id']}", ['due_date' => '2026-10-01'])['status'], 'due before the stored start when only the due is sent');
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$onlyStart['id']}", ['due_date' => ''])['status']);
    assertSame(null, roadDates($onlyStart['id'])['due_date']);
});

test('another user\'s task id answers exactly like a deleted one', function (TestClient $_c): void {
    $f = roadFixture();
    $p = roadProject($f, 'มองไม่เห็น');
    $task = roadTask($f, $p, 'งานของเจ้าของ', ['start_date' => '2026-11-01', 'due_date' => '2026-11-30']);
    $id = $task['id'];
    $body = ['start_date' => '2026-11-05', 'due_date' => '2026-12-01'];

    $foreign = $f['outsider']->request('PUT', "/api/projects/tasks/{$id}", $body);
    assertSame(404, $foreign['status'], 'an outsider cannot see the task');

    assertSame(200, $f['owner']->request('DELETE', "/api/projects/tasks/{$id}", [])['status']);
    $deleted = $f['outsider']->request('PUT', "/api/projects/tasks/{$id}", $body);
    assertSame(404, $deleted['status']);
    assertSame($deleted['body'], $foreign['body'], 'a foreign id and a deleted id are the same answer');

    $missing = $f['outsider']->request('PUT', '/api/projects/tasks/999999999', $body);
    assertSame(404, $missing['status']);
    assertSame($deleted['body'], $missing['body'], 'a never-existing id looks the same too');
});

test('a share-link guest receives start_date on tasks (D-5)', function (TestClient $_c): void {
    $f = roadFixture();
    $p = roadProject($f, 'แขก');
    $task = roadTask($f, $p, 'งานใหญ่ที่แขกเห็นวัน', ['start_date' => '2026-12-01', 'due_date' => '2027-01-15']);

    $share = $f['owner']->json($f['owner']->post("/api/projects/{$p}/share", ['share_role' => 'Viewer']));
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . ($share['share_token'] ?? ''));
    $guest->get('/projects');

    $board = $guest->json($guest->get("/api/projects/{$p}/tasks"));
    $guestTask = roadFindTask($board['tasks'] ?? [], $task['id']);
    assertSame('2026-12-01', $guestTask['start_date'] ?? null, 'the guest sees the start date');
    assertSame('2027-01-15', $guestTask['due_date'] ?? null, 'and the due date');
});
