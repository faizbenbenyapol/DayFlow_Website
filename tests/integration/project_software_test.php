<?php
// =====================================================
// tests/integration/project_software_test.php
//
// โครงสร้างงานชนิดซอฟต์แวร์ (S2a): เลขงานที่ไม่ซ้ำ คำนำหน้ารหัสงาน
// ชุดแต้มตามสเกลของโปรเจค กฎของงานแม่/งานลูก และการลบงานพร้อมงานย่อย
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * ผู้ใช้สี่บทบาทของชุดนี้ (เจ้าของ สมาชิก ผู้ดู คนนอก)
 *
 * สร้างครั้งเดียวต่อการรัน เพราะการสมัคร/เข้าสู่ระบบคือส่วนที่ช้าที่สุด
 *
 * @return array<string, mixed>
 */
function softwareFixture(): array
{
    static $f = null;
    if ($f !== null) return $f;

    $make = static function (string $role): array {
        $username = 'sw_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, 'TestPass123!');
        return [$client, $username];
    };
    [$owner, $ownerU] = $make('owner');
    [$member, $memberU] = $make('member');
    [$viewer, $viewerU] = $make('viewer');
    [$outsider] = $make('outsider');

    return $f = compact('owner', 'member', 'viewer', 'outsider', 'ownerU', 'memberU', 'viewerU');
}

/**
 * สร้างโปรเจคใหม่ผ่าน API และคืนรหัส
 *
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function softwareProject(TestClient $owner, string $name): int
{
    $res = $owner->post('/api/projects', ['name' => $name]);
    if ($res['status'] !== 201) throw new RuntimeException('project create failed: ' . $res['body']);
    return (int)($owner->json($res)['id'] ?? 0);
}

/**
 * สร้างงานผ่าน API และคืนรหัสงาน
 *
 * @param array<string, mixed> $body ข้อมูลงาน (title รวมถึง kind/parent_id/story_points)
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function softwareTaskId(TestClient $owner, int $projectId, array $body): int
{
    $res = $owner->post("/api/projects/{$projectId}/tasks", $body);
    if ($res['status'] !== 201) throw new RuntimeException('task create failed: ' . $res['body']);
    return (int)($owner->json($res)['id'] ?? 0);
}

/**
 * เพิ่มสมาชิกเข้าสู่โปรเจคด้วยบทบาทที่กำหนด
 *
 * @throws RuntimeException เมื่อเพิ่มไม่สำเร็จ
 */
function softwareAddMember(TestClient $owner, int $projectId, string $username, string $role): void
{
    $res = $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $username, 'role' => $role]);
    if ($res['status'] !== 200) throw new RuntimeException('member invite failed: ' . $res['body']);
}

test('twenty tasks created at once get twenty distinct consecutive numbers', function (TestClient $_c): void {
    $f = softwareFixture();
    $p = softwareProject($f['owner'], 'เลขงานขนาน ' . TEST_RUN_ID);

    // ห้า session แยกกันจริง: คำขอของ session เดียวจะถูก PHP ล็อกให้เข้าคิวและไม่แย่งกัน
    $sessions = [];
    for ($i = 0; $i < 5; $i++) {
        $session = new TestClient(TEST_BASE_URL);
        $session->login($f['ownerU'], 'TestPass123!');
        $sessions[] = $session;
    }

    $calls = [];
    foreach ($sessions as $s => $session) {
        for ($i = 0; $i < 4; $i++) {
            $calls[] = [$session, 'POST', "/api/projects/{$p}/tasks", ['title' => "งานขนาน {$s}-{$i}"]];
        }
    }
    $results = TestClient::parallel($calls);

    $numbers = [];
    foreach ($results as $i => $res) {
        assertSame(201, $res['status'], 'create ' . $i . ': ' . $res['body']);
        $numbers[] = (int)($f['owner']->json($res)['number'] ?? 0);
    }
    assertSame(20, count(array_unique($numbers)), 'every task got its own number');
    sort($numbers);
    assertSame(range(1, 20), $numbers, 'numbers run 1..20 without gaps');

    $stored = array_map(
        'intval',
        DB::run('SELECT number FROM project_tasks WHERE project_id = ? ORDER BY number', [$p])->fetchAll(PDO::FETCH_COLUMN)
    );
    assertSame($numbers, $stored, 'the same numbers are stored');
    assertSame(21, (int)DB::run('SELECT next_task_number FROM projects WHERE id = ?', [$p])->fetchColumn(), 'the counter sits after the last number');
});

test('a deleted number is never handed out again', function (TestClient $_c): void {
    $f = softwareFixture();
    $p = softwareProject($f['owner'], 'เลขไม่ซ้ำ ' . TEST_RUN_ID);

    $ids = [];
    foreach (['หนึ่ง', 'สอง', 'สาม'] as $title) {
        $res = $f['owner']->post("/api/projects/{$p}/tasks", ['title' => 'งาน ' . $title]);
        assertSame(201, $res['status'], $res['body']);
        $ids[] = (int)($f['owner']->json($res)['id'] ?? 0);
    }
    $newest = end($ids);
    assertSame(200, $f['owner']->request('DELETE', "/api/projects/tasks/{$newest}", [])['status']);

    $next = $f['owner']->post("/api/projects/{$p}/tasks", ['title' => 'งานที่สี่']);
    assertSame(201, $next['status'], $next['body']);
    assertSame(4, (int)($f['owner']->json($next)['number'] ?? 0), 'the new task gets 4, not the deleted 3');
    assertSame(0, (int)DB::run('SELECT COUNT(*) FROM project_tasks WHERE project_id = ? AND number = 3', [$p])->fetchColumn(), 'number 3 stays unused');
});

test('a new project derives its key prefix from the name', function (TestClient $_c): void {
    $f = softwareFixture();

    $dayflow = softwareProject($f['owner'], 'DayFlow mobile');
    assertSame('DAYF', DB::run('SELECT key_prefix FROM projects WHERE id = ?', [$dayflow])->fetchColumn(), 'the first four ASCII letters');

    $thai = softwareProject($f['owner'], 'ทดสอบระบบ');
    assertSame('PRJ', DB::run('SELECT key_prefix FROM projects WHERE id = ?', [$thai])->fetchColumn(), 'a Thai-only name falls back to PRJ');
});

test('only admins change the key prefix and it is uppercased and checked', function (TestClient $_c): void {
    $f = softwareFixture();
    $p = softwareProject($f['owner'], 'คำนำหน้า ' . TEST_RUN_ID);
    softwareAddMember($f['owner'], $p, $f['memberU'], 'Editor');

    $save = $f['owner']->request('PUT', "/api/projects/{$p}", ['key_prefix' => 'web']);
    assertSame(200, $save['status'], $save['body']);
    assertSame('WEB', DB::run('SELECT key_prefix FROM projects WHERE id = ?', [$p])->fetchColumn(), 'stored in upper case');

    foreach (['W', 'TOOLONGX', 'W1', 'ทดสอบ'] as $bad) {
        $res = $f['owner']->request('PUT', "/api/projects/{$p}", ['key_prefix' => $bad]);
        assertSame(422, $res['status'], $bad . ': ' . $res['body']);
        assertStringContains('ตัวย่อเลขงานต้องเป็นตัวอักษรอังกฤษ 2–6 ตัว', $res['body']);
    }
    assertSame('WEB', DB::run('SELECT key_prefix FROM projects WHERE id = ?', [$p])->fetchColumn(), 'refused values change nothing');

    $member = $f['member']->request('PUT', "/api/projects/{$p}", ['key_prefix' => 'APP']);
    assertSame(403, $member['status'], 'an Editor cannot change project settings: ' . $member['body']);
    assertSame('WEB', DB::run('SELECT key_prefix FROM projects WHERE id = ?', [$p])->fetchColumn());
});

test('story points follow the project scale and an epic has none', function (TestClient $_c): void {
    $f = softwareFixture();
    $p = softwareProject($f['owner'], 'แต้มงาน ' . TEST_RUN_ID);
    $task = softwareTaskId($f['owner'], $p, ['title' => 'งานมีแต้ม']);
    $put = static fn(array $body): array => $f['owner']->request('PUT', "/api/projects/tasks/{$task}", $body);
    $points = static fn(): mixed => DB::run('SELECT story_points FROM project_tasks WHERE id = ?', [$task])->fetchColumn();

    assertSame(200, $put(['story_points' => 5])['status'], 'fib accepts 5');
    assertSame(5, (int)$points());
    assertSame(422, $put(['story_points' => 4])['status'], 'fib refuses 4');
    assertSame(422, $put(['story_points' => 0])['status'], '0 is not a point value');
    assertSame(5, (int)$points(), 'refused points change nothing');

    assertSame(200, $f['owner']->request('PUT', "/api/projects/{$p}", ['point_scale' => 'ten'])['status']);
    assertSame(200, $put(['story_points' => 4])['status'], 'ten accepts 4');
    assertSame(4, (int)$points());

    $epic = softwareTaskId($f['owner'], $p, ['title' => 'งานใหญ่', 'kind' => 'epic']);
    $epicRes = $f['owner']->request('PUT', "/api/projects/tasks/{$epic}", ['story_points' => 3]);
    assertSame(422, $epicRes['status'], 'an epic cannot take points');
    assertStringContains('งานใหญ่ไม่มีแต้มของตัวเอง รวมจากงานลูก', $epicRes['body']);
    assertSame(null, DB::run('SELECT story_points FROM project_tasks WHERE id = ?', [$epic])->fetchColumn());

    assertSame(200, $put(['story_points' => null])['status'], 'null clears the points');
    assertSame(null, $points(), 'the column is empty again');
});

test('changing the kind does not re-check points the user did not send', function (TestClient $_c): void {
    $f = softwareFixture();
    $p = softwareProject($f['owner'], 'แต้มค้าง ' . TEST_RUN_ID);
    $story = softwareTaskId($f['owner'], $p, ['title' => 'เรื่องแต้ม 13', 'kind' => 'story', 'story_points' => 13]);
    $points = static fn(): mixed => DB::run('SELECT story_points FROM project_tasks WHERE id = ?', [$story])->fetchColumn();
    assertSame(13, (int)$points(), 'fib accepts 13');

    assertSame(200, $f['owner']->request('PUT', "/api/projects/{$p}", ['point_scale' => 'ten'])['status']);

    $changeKind = $f['owner']->request('PUT', "/api/projects/tasks/{$story}", ['kind' => 'bug']);
    assertSame(200, $changeKind['status'], 'changing kind keeps untouched points: ' . $changeKind['body']);
    assertSame('bug', DB::run('SELECT kind FROM project_tasks WHERE id = ?', [$story])->fetchColumn());
    assertSame(13, (int)$points(), 'the old points survive an untouched kind change');

    assertSame(422, $f['owner']->request('PUT', "/api/projects/tasks/{$story}", ['story_points' => 13])['status'], 'sending out-of-scale points is still refused');
    assertSame(13, (int)$points(), 'the refused write changes nothing');
});

test('parent rules keep epics, stories and subs in their places', function (TestClient $_c): void {
    $f = softwareFixture();
    $p = softwareProject($f['owner'], 'งานแม่ ' . TEST_RUN_ID);
    $create = static fn(array $body): array => $f['owner']->post("/api/projects/{$p}/tasks", $body);

    $epicRes = $create(['title' => 'งานใหญ่', 'kind' => 'epic']);
    assertSame(201, $epicRes['status'], $epicRes['body']);
    $epic = (int)($f['owner']->json($epicRes)['id'] ?? 0);

    $storyRes = $create(['title' => 'เรื่องใต้ใหญ่', 'kind' => 'story', 'parent_id' => $epic]);
    assertSame(201, $storyRes['status'], 'a story under an epic is fine: ' . $storyRes['body']);
    $story = (int)($f['owner']->json($storyRes)['id'] ?? 0);

    assertSame(422, $create(['title' => 'งานย่อยไม่มีแม่', 'kind' => 'sub'])['status'], 'a sub needs a parent');
    assertSame(422, $create(['title' => 'งานย่อยใต้งานใหญ่', 'kind' => 'sub', 'parent_id' => $epic])['status'], 'a sub cannot sit under an epic');
    assertSame(422, $create(['title' => 'เรื่องใต้เรื่อง', 'kind' => 'story', 'parent_id' => $story])['status'], 'a story cannot sit under a story');

    $epicParent = $create(['title' => 'งานใหญ่มีแม่', 'kind' => 'epic', 'parent_id' => $story]);
    assertSame(422, $epicParent['status'], 'an epic cannot have a parent');
    assertStringContains('งานใหญ่อยู่ใต้งานอื่นไม่ได้', $epicParent['body']);

    $own = softwareTaskId($f['owner'], $p, ['title' => 'งานของตัวเอง']);
    assertSame(422, $f['owner']->request('PUT', "/api/projects/tasks/{$own}", ['parent_id' => $own])['status'], 'a task cannot be its own parent');

    // งานแม่ที่อยู่คนละโปรเจคกับที่ถูกลบไปแล้วต้องตอบเหมือนกันทุกไบต์
    $other = softwareProject($f['owner'], 'โปรเจคอื่น ' . TEST_RUN_ID);
    $foreign = softwareTaskId($f['owner'], $other, ['title' => 'งานนอกโปรเจค']);
    $foreignRes = $f['owner']->request('PUT', "/api/projects/tasks/{$own}", ['parent_id' => $foreign]);

    $gone = softwareTaskId($f['owner'], $p, ['title' => 'งานที่จะถูกลบ']);
    assertSame(200, $f['owner']->request('DELETE', "/api/projects/tasks/{$gone}", [])['status']);
    $goneRes = $f['owner']->request('PUT', "/api/projects/tasks/{$own}", ['parent_id' => $gone]);

    assertSame(422, $foreignRes['status'], $foreignRes['body']);
    assertSame($foreignRes['status'], $goneRes['status'], 'same status for a foreign and a deleted parent');
    assertSame($foreignRes['body'], $goneRes['body'], 'same message, so parent ids do not leak');
    assertStringContains('ไม่พบงานแม่ในโปรเจคนี้', $foreignRes['body']);

    // งานใหญ่ที่มีเรื่องผู้ใช้อยู่ข้างในเปลี่ยนเป็นเรื่องผู้ใช้ไม่ได้
    $demote = $f['owner']->request('PUT', "/api/projects/tasks/{$epic}", ['kind' => 'story']);
    assertSame(422, $demote['status'], $demote['body']);
    assertStringContains('ย้ายงานลูกออกก่อนเปลี่ยนชนิดงาน', $demote['body']);
    assertSame('epic', DB::run('SELECT kind FROM project_tasks WHERE id = ?', [$epic])->fetchColumn(), 'the epic stays an epic');
});

test('deleting a task removes its subs while an epic leaves its stories parentless', function (TestClient $_c): void {
    $f = softwareFixture();
    $p = softwareProject($f['owner'], 'ลบงาน ' . TEST_RUN_ID);
    $create = static fn(array $body): int => softwareTaskId($f['owner'], $p, $body);

    $epic = $create(['title' => 'งานใหญ่', 'kind' => 'epic']);
    $story = $create(['title' => 'เรื่องมีลูก', 'kind' => 'story', 'parent_id' => $epic]);
    $sub1 = $create(['title' => 'งานย่อย 1', 'kind' => 'sub', 'parent_id' => $story]);
    $sub2 = $create(['title' => 'งานย่อย 2', 'kind' => 'sub', 'parent_id' => $story]);

    $deleted = $f['owner']->request('DELETE', "/api/projects/tasks/{$story}", []);
    assertSame(200, $deleted['status'], $deleted['body']);
    assertSame(2, (int)($f['owner']->json($deleted)['deleted_subtasks'] ?? -1), 'both subs are reported');
    assertSame(0, (int)DB::run('SELECT COUNT(*) FROM project_tasks WHERE id IN (?, ?, ?)', [$story, $sub1, $sub2])->fetchColumn(), 'the story and both subs are gone');
    assertSame($epic, (int)DB::run('SELECT id FROM project_tasks WHERE id = ?', [$epic])->fetchColumn(), 'the epic stays');

    $keptStory = $create(['title' => 'เรื่องที่ยังอยู่', 'kind' => 'story', 'parent_id' => $epic]);
    $deletedEpic = $f['owner']->request('DELETE', "/api/projects/tasks/{$epic}", []);
    assertSame(200, $deletedEpic['status'], $deletedEpic['body']);
    assertSame(0, (int)($f['owner']->json($deletedEpic)['deleted_subtasks'] ?? -1), 'no subs were deleted');
    assertSame($keptStory, (int)DB::run('SELECT id FROM project_tasks WHERE id = ?', [$keptStory])->fetchColumn(), 'the story stays');
    $row = DB::run('SELECT parent_id FROM project_tasks WHERE id = ?', [$keptStory])->fetch();
    assertSame(null, $row['parent_id'] ?? null, 'and its parent is cleared by the foreign key');
});

test('a viewer cannot change the kind of a task', function (TestClient $_c): void {
    $f = softwareFixture();
    $p = softwareProject($f['owner'], 'ผู้ดูแก้ชนิด ' . TEST_RUN_ID);
    softwareAddMember($f['owner'], $p, $f['viewerU'], 'Viewer');
    $task = softwareTaskId($f['owner'], $p, ['title' => 'งานของผู้ดู']);

    $res = $f['viewer']->request('PUT', "/api/projects/tasks/{$task}", ['kind' => 'story']);
    assertSame(403, $res['status'], $res['body']);
    assertSame('task', DB::run('SELECT kind FROM project_tasks WHERE id = ?', [$task])->fetchColumn(), 'the kind is untouched');
});
