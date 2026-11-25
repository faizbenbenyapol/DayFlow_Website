<?php
// =====================================================
// tests/integration/project_fields_test.php
//
// ช่องข้อมูลเพิ่มเติม (P0b slice 7, REQ-P6): สิทธิ์ตั้งช่อง การตรวจค่าตามชนิด
// การรวมค่าแบบไม่เขียนทับกัน การล้างค่าเมื่อเอาตัวเลือกออกหรือลบช่อง และลิงก์แชร์ไม่เห็น (D-5)
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * โปรเจคที่มีเจ้าของ สมาชิก ผู้ดูอย่างเดียว คนนอก แขกลิงก์แชร์ และงานสองงาน
 *
 * @return array<string, mixed>
 */
function fieldFixture(): array
{
    static $f = null;
    if ($f !== null) return $f;

    $make = static function (string $role): array {
        $username = 'fld_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, 'TestPass123!');
        return [$client, $username, (int)DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn()];
    };
    [$owner, , $ownerId] = $make('owner');
    [$member, $memberU, $memberId] = $make('member');
    [$viewer, $viewerU] = $make('viewer');
    [$outsider, , $outsiderId] = $make('outsider');

    $projectId = (int)($owner->json($owner->post('/api/projects', ['name' => 'ช่องข้อมูล ' . TEST_RUN_ID]))['id'] ?? 0);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $memberU, 'role' => 'Editor']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $viewerU, 'role' => 'Viewer']);
    $taskId = (int)($owner->json($owner->post("/api/projects/{$projectId}/tasks", ['title' => 'งานมีช่อง']))['id'] ?? 0);
    $otherTaskId = (int)($owner->json($owner->post("/api/projects/{$projectId}/tasks", ['title' => 'งานที่สอง']))['id'] ?? 0);

    $ids = [];
    foreach ([['แพลตฟอร์ม', 'select', ['iOS', 'Android', 'เว็บ']], ['ชั่วโมง', 'number', []], ['ลูกค้า', 'text', []],
              ['เปิดตัว', 'date', []], ['ผู้ทดสอบ', 'person', []], ['ด่วน', 'check', []]] as [$name, $kind, $options]) {
        $ids[$kind] = (int)($owner->json($owner->post("/api/projects/{$projectId}/fields", ['name' => $name, 'kind' => $kind, 'options' => $options]))['id'] ?? 0);
    }

    $share = $owner->json($owner->post("/api/projects/{$projectId}/share", ['share_role' => 'Viewer']));
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . ($share['share_token'] ?? ''));
    $guest->get('/projects');

    return $f = compact('owner', 'member', 'viewer', 'outsider', 'guest', 'ownerId', 'memberId', 'outsiderId',
        'projectId', 'taskId', 'otherTaskId', 'ids');
}

/** ค่าช่องข้อมูลของงานตามที่ API ส่งให้เจ้าของ */
function fieldValues(int $taskId): array
{
    $raw = DB::run('SELECT custom FROM project_tasks WHERE id = ?', [$taskId])->fetchColumn();
    $values = json_decode((string)$raw, true);
    return is_array($values) ? $values : [];
}

test('owners set up fields of all six kinds and they come back with the board', function (TestClient $_c): void {
    $f = fieldFixture();
    foreach ($f['ids'] as $kind => $id) {
        assertTrue($id > 0, "a {$kind} field is created");
    }
    $board = $f['owner']->json($f['owner']->get("/api/projects/{$f['projectId']}/tasks"));
    assertSame(['แพลตฟอร์ม', 'ชั่วโมง', 'ลูกค้า', 'เปิดตัว', 'ผู้ทดสอบ', 'ด่วน'], array_column($board['fields'], 'name'), 'in the order they were added');
    assertSame(['iOS', 'Android', 'เว็บ'], $board['fields'][0]['options']);
});

test('only owners and admins change fields; outsiders get the same 404 as a missing field', function (TestClient $_c): void {
    $f = fieldFixture();
    $p = $f['projectId'];
    assertSame(403, $f['member']->post("/api/projects/{$p}/fields", ['name' => 'สมาชิกเพิ่ม', 'kind' => 'text'])['status'], 'a member cannot add a field');
    assertSame(403, $f['viewer']->request('PUT', '/api/projects/fields/' . $f['ids']['text'], ['name' => 'ผู้ดูแก้'])['status'], 'a viewer cannot rename one');

    $foreign = $f['outsider']->request('DELETE', '/api/projects/fields/' . $f['ids']['text'], []);
    $missing = $f['owner']->request('DELETE', '/api/projects/fields/999999999', []);
    assertSame(404, $foreign['status']);
    assertSame($missing['body'], $foreign['body'], 'same answer, so the id does not leak');
});

test('field definitions are checked: names, kinds, options and the limit of ten', function (TestClient $_c): void {
    $f = fieldFixture();
    $p = $f['projectId'];
    $post = fn(array $body): int => $f['owner']->post("/api/projects/{$p}/fields", $body)['status'];
    assertSame(422, $post(['name' => 'ชั่วโมง', 'kind' => 'text']), 'a duplicate name is refused');
    assertSame(422, $post(['name' => 'ไม่มีตัวเลือก', 'kind' => 'select', 'options' => []]), 'a select needs options');
    assertSame(422, $post(['name' => 'ชนิดแปลก', 'kind' => 'money']), 'an unknown kind is refused');
    assertSame(422, $post(['name' => '', 'kind' => 'text']), 'an empty name is refused');
    // สมช้าย ≠ สมชาย: ชื่อที่ต่างแค่วรรณยุกต์เป็นคนละช่อง
    assertSame(201, $post(['name' => 'ลูกค้้า', 'kind' => 'text']), 'a name that differs by a tone mark is a different name');

    // 6 ช่องจาก fixture + "ลูกค้้า" = 7 เพิ่มอีก 3 จนครบ 10
    for ($i = 8; $i <= 10; $i++) {
        assertSame(201, $post(['name' => 'ช่อง ' . $i, 'kind' => 'text']));
    }
    assertSame(422, $post(['name' => 'ช่อง 11', 'kind' => 'text']), 'the eleventh field is refused');
});

test('values are checked by kind and stored as typed JSON', function (TestClient $_c): void {
    $f = fieldFixture();
    $put = fn(array $custom, ?TestClient $as = null): array => ($as ?? $f['owner'])->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['custom' => $custom]);
    $ids = $f['ids'];

    assertSame(200, $put([
        $ids['select'] => 'เว็บ', $ids['number'] => '6.5', $ids['text'] => "  บริษัท\u{00A0}เอ  ",
        $ids['date'] => '2026-12-01', $ids['person'] => $f['memberId'], $ids['check'] => true,
    ])['status']);
    $values = fieldValues($f['taskId']);
    assertSame('เว็บ', $values[(string)$ids['select']]);
    assertSame(6.5, $values[(string)$ids['number']], 'numbers are stored as numbers');
    assertSame('บริษัท เอ', $values[(string)$ids['text']], 'text is trimmed and NBSP becomes a space');
    assertSame($f['memberId'], $values[(string)$ids['person']]);
    assertSame(true, $values[(string)$ids['check']]);

    assertSame(422, $put([$ids['number'] => 'หกชั่วโมง'])['status'], 'a number field refuses words');
    assertSame(422, $put([$ids['select'] => 'Windows'])['status'], 'a select refuses a value that is not an option');
    assertSame(422, $put([$ids['date'] => '2026-02-30'])['status'], 'a date must exist');
    assertSame(422, $put([$ids['person'] => $f['outsiderId']])['status'], 'a person must be in the project');
    assertSame(422, $put([999999999 => 'x'])['status'], 'a field of another project is refused');
    assertSame(403, $put([$ids['text'] => 'ผู้ดู'], $f['viewer'])['status'], 'a viewer cannot fill fields');

    $history = $f['owner']->json($f['owner']->get('/api/projects/tasks/' . $f['taskId'] . '/history'))['items'];
    assertStringContains("แพลตฟอร์ม เปลี่ยนเป็น 'เว็บ'", implode("\n", array_column($history, 'action')), 'the change is in the task history');

    $before = count($f['owner']->json($f['owner']->get('/api/projects/tasks/' . $f['taskId'] . '/history'))['items']);
    $put([$ids['select'] => 'เว็บ']);
    $after = count($f['owner']->json($f['owner']->get('/api/projects/tasks/' . $f['taskId'] . '/history'))['items']);
    assertSame($before, $after, 'saving the same value adds no history line');
});

test('two people editing different fields keep both values; null clears one', function (TestClient $_c): void {
    $f = fieldFixture();
    $ids = $f['ids'];
    $f['owner']->request('PUT', '/api/projects/tasks/' . $f['otherTaskId'], ['custom' => [$ids['number'] => 3]]);
    $f['member']->request('PUT', '/api/projects/tasks/' . $f['otherTaskId'], ['custom' => [$ids['select'] => 'iOS']]);
    $values = fieldValues($f['otherTaskId']);
    assertSame(3, $values[(string)$ids['number']], "the owner's value survives the member's save");
    assertSame('iOS', $values[(string)$ids['select']]);

    $f['owner']->request('PUT', '/api/projects/tasks/' . $f['otherTaskId'], ['custom' => [$ids['number'] => null, $ids['check'] => false]]);
    $values = fieldValues($f['otherTaskId']);
    assertArrayNotHasKey((string)$ids['number'], $values, 'null removes the value');
    assertArrayNotHasKey((string)$ids['check'], $values, 'an unticked box stores nothing');
});

test('removing an option or a field clears its values without touching the edit time', function (TestClient $_c): void {
    $f = fieldFixture();
    $ids = $f['ids'];
    $stamp = static fn(int $taskId): string => (string)DB::run('SELECT updated_at FROM project_tasks WHERE id = ?', [$taskId])->fetchColumn();
    DB::run('UPDATE project_tasks SET updated_at = ? WHERE id IN (?, ?)', ['2026-01-01 00:00:00', $f['taskId'], $f['otherTaskId']]);

    // งานแรกเป็น "เว็บ" งานที่สองเป็น "iOS": เอา "เว็บ" ออก งานแรกเท่านั้นที่ค่าหาย
    assertSame(200, $f['owner']->request('PUT', '/api/projects/fields/' . $ids['select'], ['options' => ['iOS', 'Android']])['status']);
    assertArrayNotHasKey((string)$ids['select'], fieldValues($f['taskId']), 'the removed option is cleared from the task');
    assertSame('iOS', fieldValues($f['otherTaskId'])[(string)$ids['select']], 'an option that stays keeps its tasks');
    assertSame('2026-01-01 00:00:00', $stamp($f['taskId']), 'clearing is not an edit by the user');

    assertSame(200, $f['owner']->request('DELETE', '/api/projects/fields/' . $ids['text'], [])['status']);
    assertArrayNotHasKey((string)$ids['text'], fieldValues($f['taskId']), 'deleting a field removes its values');
    assertSame('2026-01-01 00:00:00', $stamp($f['taskId']));
    assertSame(422, $f['owner']->request('PUT', '/api/projects/fields/' . $ids['number'], ['options' => ['a']])['status'], 'only a select has options');
});

test('a share-link guest sees neither the fields nor their values (D-5)', function (TestClient $_c): void {
    $f = fieldFixture();
    $board = $f['guest']->json($f['guest']->get("/api/projects/{$f['projectId']}/tasks"));
    assertTrue(!empty($board['tasks']), 'the guest still sees the tasks');
    assertArrayNotHasKey('fields', $board, 'but not the field definitions');
    foreach ($board['tasks'] as $task) {
        assertSame(null, $task['custom'], 'nor any value');
    }
});

test('a task whose person field points at someone who left can still be saved', function (TestClient $_c): void {
    $f = fieldFixture();
    $ids = $f['ids'];
    $taskId = (int)($f['owner']->json($f['owner']->post("/api/projects/{$f['projectId']}/tasks", ['title' => 'ผู้ทดสอบออกไปแล้ว']))['id'] ?? 0);
    $leaver = new TestClient(TEST_BASE_URL);
    $leaverU = 'fld_leaver_' . TEST_RUN_ID;
    $leaver->login($leaverU, 'TestPass123!');
    $leaverId = (int)DB::run('SELECT id FROM users WHERE username = ?', [$leaverU])->fetchColumn();
    $f['owner']->post("/api/projects/{$f['projectId']}/members", ['email_or_username' => $leaverU, 'role' => 'Editor']);
    assertSame(200, $f['owner']->request('PUT', "/api/projects/tasks/{$taskId}", ['custom' => [$ids['person'] => $leaverId]])['status']);
    $f['owner']->request('DELETE', "/api/projects/{$f['projectId']}/members/{$leaverId}", []);

    // แผงส่งค่าเดิมของทุกช่องกลับมาพร้อมการแก้อื่น ค่าที่ไม่ได้แตะต้องไม่ทำให้บันทึกไม่ได้
    $save = $f['owner']->request('PUT', "/api/projects/tasks/{$taskId}", ['title' => 'แก้ชื่อได้', 'custom' => [$ids['person'] => $leaverId]]);
    assertSame(200, $save['status'], 'an untouched value is not checked again: ' . $save['body']);
    assertSame($leaverId, fieldValues($taskId)[(string)$ids['person']], 'and it stays until someone changes it');
    assertSame(422, $f['owner']->request('PUT', "/api/projects/tasks/{$taskId}", ['custom' => [$ids['person'] => $f['outsiderId']]])['status'], 'a new value is still checked');
});

test('share-link guests see no field names or values in the activity feed or task history (D-5)', function (TestClient $_c): void {
    $f = fieldFixture();
    $taskId = (int)($f['owner']->json($f['owner']->post("/api/projects/{$f['projectId']}/tasks", ['title' => 'งานงบลับ']))['id'] ?? 0);
    $save = $f['owner']->request('PUT', "/api/projects/tasks/{$taskId}", ['title' => 'งานงบลับ แก้ชื่อ', 'custom' => [$f['ids']['number'] => 987654]]);
    assertSame(200, $save['status'], $save['body']);

    $actions = static fn(array $rows): string => implode("\n", array_column($rows, 'action'));
    $feed = $actions($f['guest']->json($f['guest']->get("/api/projects/{$f['projectId']}/tasks"))['activities'] ?? []);
    $history = $actions($f['guest']->json($f['guest']->get("/api/projects/tasks/{$taskId}/history"))['items'] ?? []);
    foreach (['feed' => $feed, 'history' => $history] as $where => $text) {
        assertFalse(str_contains($text, '987654'), "the guest {$where} does not show the value: {$text}");
        assertFalse(str_contains($text, 'ชั่วโมง'), "the guest {$where} does not name the field: {$text}");
    }
    assertStringContains('แก้ชื่อ', $history, 'the guest still sees the ordinary change');
    $memberHistory = $actions($f['member']->json($f['member']->get("/api/projects/tasks/{$taskId}/history"))['items'] ?? []);
    assertStringContains("ชั่วโมง เปลี่ยนเป็น '987654'", $memberHistory, 'people in the project still see the value change');
});

test('a field name or option sent as a list is refused and nothing changes', function (TestClient $_c): void {
    $f = fieldFixture();
    $id = $f['ids']['select'];
    $before = DB::run('SELECT name, options FROM project_fields WHERE id = ?', [$id])->fetch();
    assertSame(422, $f['owner']->request('PUT', "/api/projects/fields/{$id}", ['name' => ['x']])['status'], 'a list as the name');
    assertSame(422, $f['owner']->request('PUT', "/api/projects/fields/{$id}", ['options' => [['x']]])['status'], 'a list inside the options');
    assertSame(422, $f['owner']->post("/api/projects/{$f['projectId']}/fields", ['name' => ['x'], 'kind' => 'text'])['status'], 'a list as a new name');
    assertSame($before, DB::run('SELECT name, options FROM project_fields WHERE id = ?', [$id])->fetch(), 'the field is unchanged');
});

test('a share link that can edit tasks still cannot read or write field values (D-5)', function (TestClient $_c): void {
    $f = fieldFixture();
    $projectId = (int)($f['owner']->json($f['owner']->post('/api/projects', ['name' => 'แชร์แก้ไขได้ ' . TEST_RUN_ID]))['id'] ?? 0);
    $fieldId = (int)($f['owner']->json($f['owner']->post("/api/projects/{$projectId}/fields", ['name' => 'งบลับ', 'kind' => 'number']))['id'] ?? 0);
    $taskId = (int)($f['owner']->json($f['owner']->post("/api/projects/{$projectId}/tasks", ['title' => 'งานลับ']))['id'] ?? 0);
    $f['owner']->request('PUT', "/api/projects/tasks/{$taskId}", ['custom' => [$fieldId => 987654]]);
    $share = $f['owner']->json($f['owner']->post("/api/projects/{$projectId}/share", ['share_role' => 'Editor']));
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . ($share['share_token'] ?? ''));
    $guest->get('/projects');

    $probes = [
        'a wrong type' => [$fieldId => ['x']],
        'a guessed value' => [$fieldId => 987654],
        'a new value' => [$fieldId => 1],
        'an unknown field' => [999999999 => 1],
    ];
    $bodies = [];
    foreach ($probes as $what => $custom) {
        $res = $guest->request('PUT', "/api/projects/tasks/{$taskId}", ['custom' => $custom]);
        assertSame(403, $res['status'], "{$what} is refused: " . $res['body']);
        $bodies[] = $res['body'];
    }
    assertSame(1, count(array_unique($bodies)), 'every probe gets the same answer');
    assertFalse(str_contains($bodies[0], 'งบลับ'), 'the answer does not name the field');
    assertSame(987654, fieldValues($taskId)[(string)$fieldId] ?? null, 'the value is untouched');
    assertSame(200, $guest->request('PUT', "/api/projects/tasks/{$taskId}", ['title' => 'แขกแก้ชื่อได้'])['status'], 'the guest can still edit the task itself');
});

test('saving the panel with only a field changed adds one private history line', function (TestClient $_c): void {
    $f = fieldFixture();
    $taskId = (int)($f['owner']->json($f['owner']->post("/api/projects/{$f['projectId']}/tasks", ['title' => 'งานแผง']))['id'] ?? 0);
    $count = static fn(): int => (int)DB::run('SELECT COUNT(*) FROM project_activities WHERE task_id = ?', [$taskId])->fetchColumn();
    $before = $count();
    // แผงรายละเอียดส่งข้อมูลงานครบทุกครั้ง แม้ผู้ใช้แก้แค่ค่าช่อง (projects-board.js submitEditTask)
    $task = DB::run('SELECT title, description, priority, due_date, assignee FROM project_tasks WHERE id = ?', [$taskId])->fetch();
    $save = $f['owner']->request('PUT', "/api/projects/tasks/{$taskId}", [
        'title' => $task['title'], 'description' => (string)$task['description'], 'priority' => $task['priority'],
        'due_date' => (string)$task['due_date'], 'custom' => [$f['ids']['number'] => 5000],
    ]);
    assertSame(200, $save['status'], $save['body']);
    assertSame($before + 1, $count(), 'one history line, not an extra empty one');
    assertSame(1, (int)DB::run('SELECT private FROM project_activities WHERE task_id = ? ORDER BY id DESC LIMIT 1', [$taskId])->fetchColumn(), 'and it is private');
});

test('a long text value saves and its history line is shortened', function (TestClient $_c): void {
    $f = fieldFixture();
    $taskId = (int)($f['owner']->json($f['owner']->post("/api/projects/{$f['projectId']}/tasks", ['title' => 'งานข้อความยาว']))['id'] ?? 0);
    // ช่องข้อความของตัวเอง เพราะ test ก่อนหน้าลบช่องข้อความของ fixture ไปแล้ว
    $fieldId = (int)($f['owner']->json($f['owner']->post("/api/projects/{$f['projectId']}/fields", ['name' => 'บันทึกยาว', 'kind' => 'text']))['id'] ?? 0);
    $long = str_repeat('ก', 500);
    $save = $f['owner']->request('PUT', "/api/projects/tasks/{$taskId}", ['custom' => [$fieldId => $long]]);
    assertSame(200, $save['status'], 'a 500-character value is allowed: ' . $save['body']);
    assertSame($long, fieldValues($taskId)[(string)$fieldId] ?? null, 'and stored in full');
    $line = (string)DB::run('SELECT action FROM project_activities WHERE task_id = ? ORDER BY id DESC LIMIT 1', [$taskId])->fetchColumn();
    assertStringContains('บันทึกยาว เปลี่ยนเป็น', $line, 'the history still records the change');
});

test('a share link that can edit tasks assigns a teammate and they are notified', function (TestClient $_c): void {
    $f = fieldFixture();
    $projectId = (int)($f['owner']->json($f['owner']->post('/api/projects', ['name' => 'แชร์มอบหมาย ' . TEST_RUN_ID]))['id'] ?? 0);
    $f['owner']->post("/api/projects/{$projectId}/members", ['email_or_username' => 'fld_member_' . TEST_RUN_ID, 'role' => 'Editor']);
    $taskId = (int)($f['owner']->json($f['owner']->post("/api/projects/{$projectId}/tasks", ['title' => 'งานให้แขกมอบ']))['id'] ?? 0);
    $share = $f['owner']->json($f['owner']->post("/api/projects/{$projectId}/share", ['share_role' => 'Editor']));
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . ($share['share_token'] ?? ''));
    $guest->get('/projects');

    $notified = static fn(): int => (int)DB::run("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND url LIKE ?", [$f['memberId'], '%task=' . $taskId . '%'])->fetchColumn();
    $res = $guest->request('PUT', "/api/projects/tasks/{$taskId}", ['title' => 'งานให้แขกมอบ', 'assignee_user_id' => $f['memberId']]);
    assertSame(200, $res['status'], 'a guest assigning a teammate is not a server error: ' . $res['body']);
    assertSame(1, $notified(), 'and the teammate gets the notification');
    assertSame(null, DB::run('SELECT actor_id FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$f['memberId']])->fetchColumn() ?: null, 'with no actor, since a guest is not a user');
});

test('share-link guests get the same not-found for every field id', function (TestClient $_c): void {
    $f = fieldFixture();
    $own = $f['guest']->request('PUT', '/api/projects/fields/' . $f['ids']['number'], ['name' => 'x']);
    $missing = $f['guest']->request('PUT', '/api/projects/fields/999999999', ['name' => 'x']);
    assertSame([404, $missing['body']], [$own['status'], $own['body']], 'a field of the shared project looks missing too');
});
