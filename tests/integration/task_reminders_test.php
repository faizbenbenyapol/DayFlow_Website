<?php
// =====================================================
// tests/integration/task_reminders_test.php — task reminders in the push digest
//
// Verifies that /api/push/pending surfaces task reminders correctly and
// filters out done tasks, pending reminders, and reminders that are too old.
// =====================================================

declare(strict_types=1);

test('a recent reminder appears first in the pending digest', function (TestClient $client): void {
    require_once ROOT . '/config/database.php';

    $title = 'เตือนฉัน ' . TEST_RUN_ID;
    $task = $client->json($client->post('/api/tasks', [
        'title'    => $title,
        'quadrant' => 1,
    ]))['task'];
    $taskId = (int)$task['id'];

    $remindAt = date('Y-m-d H:i:s', strtotime('-30 minutes'));
    DB::run('UPDATE tasks SET remind_at = ?, reminded_at = NOW() WHERE id = ?', [$remindAt, $taskId]);

    $items = $client->json($client->get('/api/push/pending'))['items'];
    $first = $items[0] ?? null;

    assertTrue($first !== null, 'pending digest should contain the reminder');
    assertSame('🔔 ' . $title, $first['title'], 'reminder title should include bell emoji');
    assertStringContains('เตือนเวลา', $first['body'], 'reminder body should mention time');
    assertSame(APP_URL . '/tasks#task-' . $taskId, $first['url'], 'reminder url should link to the task');
    assertSame('task-remind-' . $taskId, $first['tag'], 'reminder tag should identify the task');

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('a done task is not listed as a reminder', function (TestClient $client): void {
    require_once ROOT . '/config/database.php';

    $title = 'เสร็จแล้ว ' . TEST_RUN_ID;
    $task = $client->json($client->post('/api/tasks', [
        'title'    => $title,
        'quadrant' => 1,
    ]))['task'];
    $taskId = (int)$task['id'];

    $remindAt = date('Y-m-d H:i:s', strtotime('-30 minutes'));
    DB::run('UPDATE tasks SET remind_at = ?, reminded_at = NOW() WHERE id = ?', [$remindAt, $taskId]);
    $client->request('PUT', '/api/tasks/' . $taskId, ['status' => 'done']);

    $items = $client->json($client->get('/api/push/pending'))['items'];
    $reminderTags = array_map(static fn(array $i): string => $i['tag'], $items);

    assertNotContains('task-remind-' . $taskId, $reminderTags, 'done task should not produce a reminder');

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('a task with reminded_at NULL is not listed', function (TestClient $client): void {
    require_once ROOT . '/config/database.php';

    $title = 'ยังไม่เตือน ' . TEST_RUN_ID;
    $task = $client->json($client->post('/api/tasks', [
        'title'    => $title,
        'quadrant' => 1,
    ]))['task'];
    $taskId = (int)$task['id'];

    DB::run('UPDATE tasks SET remind_at = ?, reminded_at = NULL WHERE id = ?', [date('Y-m-d H:i:s', strtotime('-30 minutes')), $taskId]);

    $items = $client->json($client->get('/api/push/pending'))['items'];
    $reminderTags = array_map(static fn(array $i): string => $i['tag'], $items);

    assertNotContains('task-remind-' . $taskId, $reminderTags, 'unhandled reminder should not appear');

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('a reminder older than 2 hours is not listed', function (TestClient $client): void {
    require_once ROOT . '/config/database.php';

    $title = 'เก่าเกินไป ' . TEST_RUN_ID;
    $task = $client->json($client->post('/api/tasks', [
        'title'    => $title,
        'quadrant' => 1,
    ]))['task'];
    $taskId = (int)$task['id'];

    $remindAt = date('Y-m-d H:i:s', strtotime('-3 hours'));
    DB::run('UPDATE tasks SET remind_at = ?, reminded_at = NOW() WHERE id = ?', [$remindAt, $taskId]);

    $items = $client->json($client->get('/api/push/pending'))['items'];
    $reminderTags = array_map(static fn(array $i): string => $i['tag'], $items);

    assertNotContains('task-remind-' . $taskId, $reminderTags, 'reminder older than 2 hours should not appear');

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});
