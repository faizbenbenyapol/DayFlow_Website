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
