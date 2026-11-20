<?php
// =====================================================
// tests/integration/focus_welcome_test.php — Daily Loop ขั้น 7–8
//
// ปุ่มโฟกัสจากงาน (REQ-21, EDGE-13) และส่วนต้อนรับบัญชีใหม่ (REQ-23, EDGE-16)
// แต่ละ test ใช้บัญชีใหม่ของตัวเอง เพราะส่วนต้อนรับขึ้นกับว่าบัญชีเคยมีงานหรือยัง
// =====================================================

declare(strict_types=1);

/** บัญชีใหม่ที่ยังไม่เคยมีงาน ชื่อไม่ซ้ำกันในแต่ละรอบ test */
function focusWelcomeAccount(string $label): TestClient
{
    $client = new TestClient(TEST_BASE_URL);
    $client->login('fw_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

/** ค่า welcome ของส่วนงานบนหน้าวันนี้ */
function welcomeShown(TestClient $client): bool
{
    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks'));
    assertArrayHasKey('welcome', $data['tasks'] ?? [], 'summary should say whether to welcome');
    return $data['tasks']['welcome'];
}

test('a new account is welcomed until its first task, and deleting that task does not bring it back', function (TestClient $_c): void {
    $client = focusWelcomeAccount('welcome');
    assertSame(true, welcomeShown($client), 'an account that never had a task is welcomed');

    $created = $client->json($client->post('/api/tasks', ['title' => 'งานแรก ' . TEST_RUN_ID, 'quadrant' => 1, 'due_date' => 'today']));
    $taskId = (int)($created['task']['id'] ?? 0);
    assertTrue($taskId > 0, 'first task created');
    assertSame(false, welcomeShown($client), 'the first task ends the welcome');

    assertSame(200, $client->request('DELETE', '/api/tasks/' . $taskId, [])['status'], 'first task deleted');
    assertSame(false, welcomeShown($client), 'an account that has had a task stays welcomed (EDGE-16)');
});

test('the focus button opens the Focus page with that task chosen', function (TestClient $_c): void {
    $client = focusWelcomeAccount('focuslink');
    $task = $client->json($client->post('/api/tasks', ['title' => 'โฟกัสงานนี้ ' . TEST_RUN_ID, 'quadrant' => 1, 'due_date' => 'today']))['task'];
    $id = (int)$task['id'];

    $page = $client->get('/focus?task=' . $id)['body'];
    assertStringContains('value="' . $id . '" selected', $page, 'the task from ?task= is chosen');

    $other = focusWelcomeAccount('focusother');
    // ดูเฉพาะ dropdown ของหน้าโฟกัส sheet จดงานใน layout ก็มี option ที่ selected
    preg_match('~id="selectFocusTask">(.*?)</select>~s', $other->get('/focus?task=' . $id)['body'], $select);
    assertTrue(isset($select[1]), 'the Focus page has its task list');
    assertFalse(str_contains($select[1], 'selected'), 'someone else\'s task id chooses nothing');
});

test('a finished round is tied to the chosen task', function (TestClient $_c): void {
    $client = focusWelcomeAccount('focustie');
    $task = $client->json($client->post('/api/tasks', ['title' => 'งานที่โฟกัส ' . TEST_RUN_ID, 'quadrant' => 1]))['task'];

    $saved = $client->post('/api/focus', ['type' => 'work', 'duration_min' => 25, 'task_id' => (int)$task['id'], 'task_title' => $task['title']]);
    assertSame(201, $saved['status'], $saved['body']);

    $session = $client->json($client->get('/api/focus'))['sessions'][0];
    assertSame((int)$task['id'], (int)$session['task_id']);
    assertSame($task['title'], $session['task_title'], 'the history names the task');
});

test('a round whose task was deleted mid-focus is still saved, under the task\'s title and tied to nothing', function (TestClient $_c): void {
    $client = focusWelcomeAccount('focusgone');
    $task = $client->json($client->post('/api/tasks', ['title' => 'งานที่ถูกลบ ' . TEST_RUN_ID, 'quadrant' => 1]))['task'];
    $client->request('DELETE', '/api/tasks/' . (int)$task['id'], []);

    $saved = $client->post('/api/focus', ['type' => 'work', 'duration_min' => 25, 'task_id' => (int)$task['id'], 'task_title' => $task['title'], 'title' => '']);
    assertSame(201, $saved['status'], 'the round is not lost (EDGE-13): ' . $saved['body']);

    $session = $client->json($client->get('/api/focus'))['sessions'][0];
    assertSame(null, $session['task_id'], 'tied to no task');
    assertSame($task['title'], $session['title'], 'kept under the task\'s old title');
});

test('a title typed for the round wins over the deleted task\'s title', function (TestClient $_c): void {
    $client = focusWelcomeAccount('focustyped');
    $client->post('/api/focus', ['type' => 'work', 'duration_min' => 25, 'task_id' => 999999999, 'task_title' => 'ชื่องาน', 'title' => 'ที่พิมพ์เอง']);

    assertSame('ที่พิมพ์เอง', $client->json($client->get('/api/focus'))['sessions'][0]['title']);
});

test('an account whose first tasks came from a backup is not welcomed again after deleting them', function (TestClient $_c): void {
    $client = focusWelcomeAccount('imported');
    $backup = ['format' => 'dayflow-export', 'version' => 3, 'tasks' => [['id' => 1, 'title' => 'จากไฟล์สำรอง', 'status' => 'open', 'quadrant' => 1]]];
    $imported = $client->upload('/api/settings/import', 'file', 'backup.json', json_encode($backup));
    assertSame(200, $imported['status'], $imported['body']);

    foreach ($client->json($client->get('/api/tasks'))['quadrants'][1] ?? [] as $task) {
        $client->request('DELETE', '/api/tasks/' . (int)$task['id'], []);
    }
    assertSame(false, welcomeShown($client), 'an import counts as having had a task (EDGE-16)');
});

test('a negative task id is saved as no task, not a server error', function (TestClient $_c): void {
    $client = focusWelcomeAccount('negative');
    $saved = $client->post('/api/focus', ['type' => 'work', 'duration_min' => 25, 'task_id' => -5]);
    assertSame(201, $saved['status'], $saved['body']);
    assertSame(null, $client->json($client->get('/api/focus'))['sessions'][0]['task_id']);
});
