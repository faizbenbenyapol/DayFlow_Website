<?php
// =====================================================
// tests/integration/task_subtasks_test.php
//
// งานย่อย (subtasks) และเวลาเตือน (remind_at) ของงานส่วนตัว
// =====================================================

declare(strict_types=1);

/** สร้าง client ของบัญชีที่สองสำหรับทดสอบการแยกข้อมูล */
function subtasksOtherClient(): TestClient
{
    static $client = null;
    if ($client !== null) return $client;

    $client = new TestClient(TEST_BASE_URL);
    $client->login('subtasks_other_user', 'OtherPass123!');
    return $client;
}

test('can add, list, tick, untick, rename, delete and reorder a subtask', function (TestClient $client): void {
    $tag = 'subtasks-crud-' . TEST_RUN_ID;
    $task = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 1]))['task'];
    $taskId = (int)$task['id'];

    $a = $client->json($client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'A']))['subtask'];
    $b = $client->json($client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'B']))['subtask'];

    $list = $client->json($client->get('/api/tasks/' . $taskId . '/subtasks'))['subtasks'];
    assertSame(2, count($list));
    assertSame('A', $list[0]['title']);
    assertSame('B', $list[1]['title']);

    $ticked = $client->json($client->request('PUT', '/api/tasks/subtasks/' . (int)$a['id'], ['is_done' => true]))['subtask'];
    assertSame(1, (int)$ticked['is_done']);

    $unticked = $client->json($client->request('PUT', '/api/tasks/subtasks/' . (int)$a['id'], ['is_done' => false]))['subtask'];
    assertSame(0, (int)$unticked['is_done']);

    $renamed = $client->json($client->request('PUT', '/api/tasks/subtasks/' . (int)$a['id'], ['title' => 'A renamed']))['subtask'];
    assertSame('A renamed', $renamed['title']);

    $reorder = $client->post('/api/tasks/' . $taskId . '/subtasks/reorder', ['ids' => [(int)$b['id'], (int)$a['id']]]);
    assertSame(200, $reorder['status']);
    $list = $client->json($client->get('/api/tasks/' . $taskId . '/subtasks'))['subtasks'];
    assertSame('B', $list[0]['title']);
    assertSame('A renamed', $list[1]['title']);

    assertSame(200, $client->request('DELETE', '/api/tasks/subtasks/' . (int)$a['id'], [])['status']);
    $list = $client->json($client->get('/api/tasks/' . $taskId . '/subtasks'))['subtasks'];
    assertSame(1, count($list));

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('task list shows subtask_total and subtask_done', function (TestClient $client): void {
    $tag = 'subtasks-counts-' . TEST_RUN_ID;
    $task = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 1]))['task'];
    $taskId = (int)$task['id'];

    $a = $client->json($client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'One']))['subtask'];
    $client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'Two']);
    $client->request('PUT', '/api/tasks/subtasks/' . (int)$a['id'], ['is_done' => true]);

    $tasks = $client->json($client->get('/api/tasks'))['quadrants'][1] ?? [];
    $found = array_values(array_filter($tasks, static fn(array $t): bool => $t['title'] === $tag));
    assertSame(1, count($found));
    assertSame(2, (int)$found[0]['subtask_total']);
    assertSame(1, (int)$found[0]['subtask_done']);

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('ticking all subtasks leaves the parent task open', function (TestClient $client): void {
    $tag = 'subtasks-parent-open-' . TEST_RUN_ID;
    $task = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 1]))['task'];
    $taskId = (int)$task['id'];

    $a = $client->json($client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'One']))['subtask'];
    $b = $client->json($client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'Two']))['subtask'];
    $client->request('PUT', '/api/tasks/subtasks/' . (int)$a['id'], ['is_done' => true]);
    $client->request('PUT', '/api/tasks/subtasks/' . (int)$b['id'], ['is_done' => true]);

    $tasks = $client->json($client->get('/api/tasks'))['quadrants'][1] ?? [];
    $found = array_values(array_filter($tasks, static fn(array $t): bool => $t['title'] === $tag));
    assertSame('open', $found[0]['status'] ?? 'missing');

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('empty and 256-char subtask titles are rejected', function (TestClient $client): void {
    $tag = 'subtasks-title-validation-' . TEST_RUN_ID;
    $task = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 1]))['task'];
    $taskId = (int)$task['id'];

    assertSame(422, $client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => '   '])['status']);
    assertSame(422, $client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => str_repeat('ก', 256)])['status']);

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('another account cannot read, edit or delete subtasks', function (TestClient $client): void {
    $other = subtasksOtherClient();
    $tag = 'subtasks-isolation-' . TEST_RUN_ID;

    $task = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 1]))['task'];
    $taskId = (int)$task['id'];
    $sub = $client->json($client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'Secret']))['subtask'];
    $subId = (int)$sub['id'];

    assertSame(404, $other->get('/api/tasks/' . $taskId . '/subtasks')['status']);
    assertSame(404, $other->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'X'])['status']);
    assertSame(404, $other->request('PUT', '/api/tasks/subtasks/' . $subId, ['title' => 'Hacked'])['status']);
    assertSame(404, $other->request('DELETE', '/api/tasks/subtasks/' . $subId, [])['status']);
    assertSame(404, $other->post('/api/tasks/' . $taskId . '/subtasks/reorder', ['ids' => [$subId]])['status']);

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('deleting a task deletes its subtasks', function (TestClient $client): void {
    $tag = 'subtasks-cascade-' . TEST_RUN_ID;
    $task = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 1]))['task'];
    $taskId = (int)$task['id'];
    $sub = $client->json($client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'Gone']))['subtask'];

    $client->request('DELETE', '/api/tasks/' . $taskId, []);

    assertSame(404, $client->get('/api/tasks/' . $taskId . '/subtasks')['status']);
    assertSame(404, $client->request('PUT', '/api/tasks/subtasks/' . (int)$sub['id'], ['title' => 'x'])['status']);
});

test('remind_at saves, clears with empty string and rejects bad formats', function (TestClient $client): void {
    $tag = 'remind-at-crud-' . TEST_RUN_ID;
    $future = date('Y-m-d H:i', strtotime('+1 day'));
    $futureSeconds = date('Y-m-d H:i:s', strtotime('+2 days'));

    $task = $client->json($client->post('/api/tasks', [
        'title'     => $tag,
        'quadrant'  => 1,
        'remind_at' => $future,
    ]))['task'];
    assertSame($future . ':00', $task['remind_at']);

    $updated = $client->json($client->request('PUT', '/api/tasks/' . (int)$task['id'], ['remind_at' => $futureSeconds]))['task'];
    assertSame(substr($futureSeconds, 0, 16) . ':00', $updated['remind_at']);

    $cleared = $client->json($client->request('PUT', '/api/tasks/' . (int)$task['id'], ['remind_at' => '']))['task'];
    assertSame(null, $cleared['remind_at']);

    assertSame(422, $client->request('PUT', '/api/tasks/' . (int)$task['id'], ['remind_at' => 'not-a-time'])['status']);
    assertSame(422, $client->post('/api/tasks', ['title' => $tag . '-bad', 'quadrant' => 1, 'remind_at' => '2020/01/01 10:00'])['status']);

    $client->request('DELETE', '/api/tasks/' . (int)$task['id'], []);
});

test('past remind_at is rejected on create and on change but ignored when unchanged', function (TestClient $client): void {
    require_once ROOT . '/config/database.php';

    $tag = 'remind-at-past-' . TEST_RUN_ID;
    $past = date('Y-m-d H:i', strtotime('-1 hour'));
    $pastEarlier = date('Y-m-d H:i', strtotime('-2 hours'));
    $future = date('Y-m-d H:i', strtotime('+1 day'));

    assertSame(422, $client->post('/api/tasks', [
        'title'     => $tag,
        'quadrant'  => 1,
        'remind_at' => $past,
    ])['status']);

    $task = $client->json($client->post('/api/tasks', [
        'title'     => $tag,
        'quadrant'  => 1,
        'remind_at' => $future,
    ]))['task'];
    $taskId = (int)$task['id'];

    // Simulate an old reminder that has already passed
    DB::run('UPDATE tasks SET remind_at = ? WHERE id = ?', [$past . ':00', $taskId]);

    // Changing to a different past time is still rejected
    assertSame(422, $client->request('PUT', '/api/tasks/' . $taskId, ['remind_at' => $pastEarlier])['status']);

    // Saving the same past time again is allowed
    $same = $client->request('PUT', '/api/tasks/' . $taskId, ['remind_at' => $past]);
    assertSame(200, $same['status']);

    // Saving unrelated fields while the stored reminder is past is also allowed
    $untouched = $client->request('PUT', '/api/tasks/' . $taskId, ['title' => $tag . ' updated']);
    assertSame(200, $untouched['status']);

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('changing remind_at resets reminded_at', function (TestClient $client): void {
    require_once ROOT . '/config/database.php';

    $tag = 'remind-at-reset-' . TEST_RUN_ID;
    $old = date('Y-m-d H:i', strtotime('+1 hour'));
    $new = date('Y-m-d H:i', strtotime('+2 hours'));

    $task = $client->json($client->post('/api/tasks', [
        'title'     => $tag,
        'quadrant'  => 1,
        'remind_at' => $old,
    ]))['task'];
    $taskId = (int)$task['id'];

    DB::run('UPDATE tasks SET reminded_at = NOW() WHERE id = ?', [$taskId]);

    $updated = $client->json($client->request('PUT', '/api/tasks/' . $taskId, ['remind_at' => $new]))['task'];
    assertSame($new . ':00', $updated['remind_at']);

    $row = DB::run('SELECT reminded_at FROM tasks WHERE id = ?', [$taskId])->fetch();
    assertSame(null, $row['reminded_at']);

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
});

test('completing a weekly task copies subtasks and shifts remind_at', function (TestClient $client): void {
    $due = date('Y-m-d', strtotime('+2 days'));
    $remind = date('Y-m-d H:i', strtotime('+1 day'));

    $task = $client->json($client->post('/api/tasks', [
        'title'       => 'weekly-subtasks-' . TEST_RUN_ID,
        'quadrant'    => 2,
        'due_date'    => $due,
        'repeat_rule' => 'weekly',
        'remind_at'   => $remind,
    ]))['task'];
    $taskId = (int)$task['id'];

    $s1 = $client->json($client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'Ticked']))['subtask'];
    $client->post('/api/tasks/' . $taskId . '/subtasks', ['title' => 'Unticked']);
    $client->request('PUT', '/api/tasks/subtasks/' . (int)$s1['id'], ['is_done' => true]);

    $done = $client->json($client->request('PUT', '/api/tasks/' . $taskId, ['status' => 'done']));
    assertArrayHasKey('next_task', $done);

    $nextId = (int)$done['next_task']['id'];
    assertSame(date('Y-m-d', strtotime($due . ' +1 week')), $done['next_task']['due_date']);
    assertSame(date('Y-m-d H:i:00', strtotime($remind . ' +1 week')), $done['next_task']['remind_at']);

    $nextSubtasks = $client->json($client->get('/api/tasks/' . $nextId . '/subtasks'))['subtasks'];
    assertSame(2, count($nextSubtasks));
    foreach ($nextSubtasks as $s) {
        assertSame(0, (int)$s['is_done']);
    }

    $client->request('DELETE', '/api/tasks/' . $taskId, []);
    $client->request('DELETE', '/api/tasks/' . $nextId, []);
});
