<?php
// =====================================================
// tests/integration/task_inbox_recent_done_test.php — CR-2 inbox done 7-day window
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/** คืน true ถ้าชื่องานปรากฏในกลุ่ม quadrant ที่ระบุ */
function hasTitleInQuadrant(array $data, string $title, int $quadrant): bool
{
    return in_array($title, array_column($data['quadrants'][$quadrant] ?? [], 'title'), true);
}

test('open inbox task is present in quadrants[0]', function (TestClient $client): void {
    $tag = 'recent-open-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 0]));
    assertTrue(isset($created['task']['id']), 'task should be created');

    $data = $client->json($client->get('/api/tasks'));
    assertTrue(hasTitleInQuadrant($data, $tag, 0), 'open inbox task should appear in inbox group');
});

test('inbox task toggled done via API stays in inbox and records completed_at', function (TestClient $client): void {
    $tag = 'recent-done-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 0]));
    $id = (int)($created['task']['id'] ?? 0);
    assertTrue($id > 0, 'task should be created');

    $done = $client->request('PUT', '/api/tasks/' . $id, ['status' => 'done']);
    assertSame(200, $done['status'], 'task should be updated to done');

    $data = $client->json($client->get('/api/tasks'));
    assertTrue(hasTitleInQuadrant($data, $tag, 0), 'done inbox task should still appear in inbox group');

    $row = DB::run('SELECT completed_at FROM tasks WHERE id = ?', [$id])->fetch();
    assertTrue($row !== false && $row['completed_at'] !== null, 'completed_at must be set when done');
});

test('inbox done task older than 7 days is hidden from quadrants[0]', function (TestClient $client): void {
    $tag = 'recent-old-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 0]));
    $id = (int)($created['task']['id'] ?? 0);
    assertTrue($id > 0, 'task should be created');

    $client->request('PUT', '/api/tasks/' . $id, ['status' => 'done']);
    DB::run('UPDATE tasks SET completed_at = NOW() - INTERVAL 8 DAY WHERE id = ?', [$id]);

    $data = $client->json($client->get('/api/tasks'));
    assertFalse(hasTitleInQuadrant($data, $tag, 0), 'done inbox task older than 7 days should be hidden');
});

test('inbox done task within 7 days is visible in quadrants[0]', function (TestClient $client): void {
    $tag = 'recent-week-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 0]));
    $id = (int)($created['task']['id'] ?? 0);
    assertTrue($id > 0, 'task should be created');

    $client->request('PUT', '/api/tasks/' . $id, ['status' => 'done']);
    DB::run('UPDATE tasks SET completed_at = NOW() - INTERVAL 6 DAY WHERE id = ?', [$id]);

    $data = $client->json($client->get('/api/tasks'));
    assertTrue(hasTitleInQuadrant($data, $tag, 0), 'done inbox task within 7 days should be visible');
});

test('done task in quadrant 2 older than 7 days is still returned in quadrants[2]', function (TestClient $client): void {
    $tag = 'recent-q2-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 2]));
    $id = (int)($created['task']['id'] ?? 0);
    assertTrue($id > 0, 'task should be created');

    $client->request('PUT', '/api/tasks/' . $id, ['status' => 'done']);
    DB::run('UPDATE tasks SET completed_at = NOW() - INTERVAL 30 DAY WHERE id = ?', [$id]);

    $data = $client->json($client->get('/api/tasks'));
    assertTrue(hasTitleInQuadrant($data, $tag, 2), 'done quadrant task should always be visible');
});

test('reopening clears completed_at and re-marking done keeps the first completed_at', function (TestClient $client): void {
    $tag = 'recent-reopen-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 0]));
    $id = (int)($created['task']['id'] ?? 0);
    assertTrue($id > 0, 'task should be created');

    $client->request('PUT', '/api/tasks/' . $id, ['status' => 'done']);
    $knownPast = '2026-01-01 12:00:00';
    DB::run('UPDATE tasks SET completed_at = ? WHERE id = ?', [$knownPast, $id]);

    $client->request('PUT', '/api/tasks/' . $id, ['status' => 'done']);
    $row = DB::run('SELECT completed_at, status FROM tasks WHERE id = ?', [$id])->fetch();
    assertSame($knownPast, $row['completed_at'] ?? null, 're-marking done must keep the existing completed_at');

    $client->request('PUT', '/api/tasks/' . $id, ['status' => 'open']);
    $row = DB::run('SELECT completed_at, status FROM tasks WHERE id = ?', [$id])->fetch();
    assertSame('open', $row['status'] ?? null, 'task should be open after reopening');
    assertSame(null, $row['completed_at'] ?? null, 'completed_at must be cleared when reopened');
});

test('a search link can force-show an old done inbox task', function (TestClient $client): void {
    $tag = 'search-show-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 0]));
    $id = (int)($created['task']['id'] ?? 0);
    assertTrue($id > 0, 'task should be created');

    $client->request('PUT', '/api/tasks/' . $id, ['status' => 'done']);
    DB::run('UPDATE tasks SET completed_at = NOW() - INTERVAL 30 DAY WHERE id = ?', [$id]);

    $data = $client->json($client->get('/api/tasks'));
    assertFalse(hasTitleInQuadrant($data, $tag, 0), 'old done inbox task is hidden by default');

    $shown = $client->json($client->get('/api/tasks?show=' . $id));
    assertTrue(hasTitleInQuadrant($shown, $tag, 0), '?show=<id> forces the task to appear');

    // ต้องไม่เห็นงานของผู้ใช้อื่นแม้จะระบุ show มา
    $other = new TestClient(TEST_BASE_URL);
    $other->login('other_show_' . TEST_RUN_ID, 'TestPass123!');
    $otherTask = $client->json($other->post('/api/tasks', ['title' => 'other ' . $tag, 'quadrant' => 0]))['task'] ?? [];
    $otherId = (int)($otherTask['id'] ?? 0);
    assertTrue($otherId > 0, 'other user task should be created');

    $ownerSeesOther = $client->json($client->get('/api/tasks?show=' . $otherId));
    assertFalse(hasTitleInQuadrant($ownerSeesOther, 'other ' . $tag, 0), 'show must not reveal another user\'s task');
});
