<?php
// =====================================================
// tests/integration/planner_todos_test.php
//
// Planner to-dos moved onto the tasks table in step 2 of the daily-loop
// refactor. These tests pin the cross-page behaviour: a planner to-do is
// just a task with a due date, and repeating tasks still roll forward when
// ticked off from the planner.
// =====================================================

declare(strict_types=1);

function plannerClient(string $label): TestClient
{
    $client = new TestClient(TEST_BASE_URL);
    $client->login('planner_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

test('a daily repeating planner todo rolls forward when ticked off', function (TestClient $_c): void {
    $client = plannerClient('repeat');
    $date   = date('Y-m-d', strtotime('+1 day'));

    // Create the repeating task from the Tasks page (planner create has no
    // repeat_rule field) and confirm it shows up in the planner list.
    $task = $client->json($client->post('/api/tasks', [
        'title'       => 'วิ่งเช้า ' . TEST_RUN_ID,
        'quadrant'    => 1,
        'due_date'    => $date,
        'repeat_rule' => 'daily',
    ]))['task'];

    $list = $client->json($client->get('/api/planner/todos?date=' . $date))['todos'] ?? [];
    $mine = array_values(array_filter($list, static fn(array $t): bool => (int)$t['id'] === (int)$task['id']));
    assertSame(1, count($mine), 'repeating task appears in planner for its due date');

    // Tick it off from the planner.
    $updated = $client->json($client->request('PUT', '/api/planner/todos/' . (int)$task['id'], ['is_done' => 1]));
    assertSame(1, (int)($updated['todo']['is_done'] ?? 0), 'planner marks the occurrence done');
    assertArrayHasKey('next_task', $updated, 'ticking a repeating task creates the next occurrence');
    assertSame(date('Y-m-d', strtotime($date . ' +1 day')), $updated['next_task']['due_date'] ?? null);

    $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
    $nextList = $client->json($client->get('/api/planner/todos?date=' . $nextDate))['todos'] ?? [];
    $nextMine = array_values(array_filter($nextList, static fn(array $t): bool => $t['title'] === 'วิ่งเช้า ' . TEST_RUN_ID));
    assertSame(1, count($nextMine), 'the next occurrence is visible in the planner');
});

test('planner reorder rejects a non-array items payload', function (TestClient $_c): void {
    $client = plannerClient('reorder');
    assertSame(422, $client->post('/api/planner/todos/reorder', ['items' => 'not-an-array'])['status']);
});

test('a planner todo for date X syncs with the tasks page', function (TestClient $_c): void {
    $client = plannerClient('sync');
    $date   = date('Y-m-d', strtotime('+2 days'));
    $title  = 'ซื้อของ ' . TEST_RUN_ID;

    $response = $client->post('/api/planner/todos', ['title' => $title, 'date' => $date]);
    assertSame(201, $response['status']);
    $created = $client->json($response);
    assertSame($title, $created['todo']['title'] ?? null);

    $tasks = $client->json($client->get('/api/tasks'))['quadrants'] ?? [];
    $found = null;
    foreach ($tasks as $quadrant) {
        foreach ($quadrant as $task) {
            if (($task['title'] ?? '') === $title && ($task['due_date'] ?? '') === $date) {
                $found = $task;
                break 2;
            }
        }
    }
    assertTrue($found !== null, 'planner todo shows up on the tasks page');
    assertSame('open', $found['status'] ?? null);

    $client->request('PUT', '/api/planner/todos/' . (int)$found['id'], ['is_done' => 1]);

    $tasks = $client->json($client->get('/api/tasks'))['quadrants'] ?? [];
    $after = null;
    foreach ($tasks as $quadrant) {
        foreach ($quadrant as $task) {
            if ((int)$task['id'] === (int)$found['id']) {
                $after = $task;
                break 2;
            }
        }
    }
    assertTrue($after !== null, 'task still exists after ticking from planner');
    assertSame('done', $after['status'] ?? null);
});
