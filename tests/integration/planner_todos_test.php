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

test('a repeating inbox task keeps NULL quadrant when rolling forward via planner', function (TestClient $_c): void {
    $client = plannerClient('inbox_repeat');
    $date   = date('Y-m-d', strtotime('+1 day'));
    $title  = 'inbox rolling ' . TEST_RUN_ID;

    // สร้างงานซ้ำใน inbox (quadrant 0) จากหน้า Tasks
    $task = $client->json($client->post('/api/tasks', [
        'title'       => $title,
        'quadrant'    => 0,
        'due_date'    => $date,
        'repeat_rule' => 'daily',
    ]))['task'];
    assertSame(null, $task['quadrant'] ?? null, 'created inbox task has NULL quadrant');

    // ทำเครื่องหมายเสร็จจาก Planner
    $updated = $client->json($client->request('PUT', '/api/planner/todos/' . (int)$task['id'], ['is_done' => 1]));
    assertArrayHasKey('next_task', $updated, 'ticking a repeating inbox task creates the next occurrence');

    $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
    $nextList = $client->json($client->get('/api/planner/todos?date=' . $nextDate))['todos'] ?? [];
    $nextMine = array_values(array_filter($nextList, static fn(array $t): bool => $t['title'] === $title));
    assertSame(1, count($nextMine), 'the next occurrence is visible in the planner');
    assertTrue(array_key_exists('quadrant', $nextMine[0]), 'planner todo output must include quadrant');
    assertSame(null, $nextMine[0]['quadrant'] ?? null, 'next occurrence must stay in inbox (NULL quadrant)');
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

test('planner order stays independent of tasks-page drag', function (TestClient $_c): void {
    $client = plannerClient('order');
    $date   = date('Y-m-d', strtotime('+3 days'));

    // สร้าง to-do A, B, C บน Planner
    $titles = ['A ' . TEST_RUN_ID, 'B ' . TEST_RUN_ID, 'C ' . TEST_RUN_ID];
    $ids    = [];
    foreach ($titles as $title) {
        $created = $client->json($client->post('/api/planner/todos', ['title' => $title, 'date' => $date]));
        $ids[]   = (int)($created['todo']['id'] ?? 0);
    }

    // ดึงงานในหน้า Tasks ช่อง inbox (0) แล้วกลับลำดับ จำลองการลากบนหน้า Tasks
    $tasks = $client->json($client->get('/api/tasks'))['quadrants'] ?? [];
    $q0    = array_values(array_filter($tasks[0] ?? [], static fn(array $t): bool => in_array((int)$t['id'], $ids, true)));
    $reversed = array_reverse($q0);
    $reorder  = array_map(static fn(array $t, int $idx): array => [
        'id'       => (int)$t['id'],
        'quadrant' => 0,
        'position' => $idx,
    ], $reversed, array_keys($reversed));

    $response = $client->post('/api/tasks/reorder', ['items' => $reorder]);
    assertSame(200, $response['status'], 'tasks-page reorder must succeed');

    // Planner ต้องยังคงเห็น A, B, C ตามลำดับเดิม
    $list   = $client->json($client->get('/api/planner/todos?date=' . $date))['todos'] ?? [];
    $mine   = array_values(array_filter($list, static fn(array $t): bool => in_array((int)$t['id'], $ids, true)));
    assertSame(['A ' . TEST_RUN_ID, 'B ' . TEST_RUN_ID, 'C ' . TEST_RUN_ID], array_column($mine, 'title'));

    // เรียงใหม่บน Planner เป็น C, A, B
    $plannerReorder = [
        ['id' => $ids[2], 'position' => 0],
        ['id' => $ids[0], 'position' => 1],
        ['id' => $ids[1], 'position' => 2],
    ];
    assertSame(200, $client->post('/api/planner/todos/reorder', ['items' => $plannerReorder])['status']);

    $list = $client->json($client->get('/api/planner/todos?date=' . $date))['todos'] ?? [];
    $mine = array_values(array_filter($list, static fn(array $t): bool => in_array((int)$t['id'], $ids, true)));
    assertSame(['C ' . TEST_RUN_ID, 'A ' . TEST_RUN_ID, 'B ' . TEST_RUN_ID], array_column($mine, 'title'));
});

test('a planner-only share link sees only former daily todos', function (TestClient $_c): void {
    $owner = plannerClient('share_owner');
    $date  = date('Y-m-d', strtotime('+4 days'));

    // สร้าง to-do ใน Planner (inbox) และงานใน quadrant 1 ในวันเดียวกัน
    $todo = $owner->json($owner->post('/api/planner/todos', ['title' => 'Shared todo ' . TEST_RUN_ID, 'date' => $date]))['todo'] ?? [];
    $task = $owner->json($owner->post('/api/tasks', [
        'title'    => 'Shared task ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => $date,
    ]))['task'] ?? [];

    $created = $owner->json($owner->post('/api/app-shares', [
        'label' => 'เฉพาะแพลนเนอร์ ' . TEST_RUN_ID,
        'menus' => ['planner'],
    ]));

    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/shared/' . ($created['token'] ?? ''));

    $list = $guest->json($guest->get('/api/planner/todos?date=' . $date))['todos'] ?? [];
    $titles = array_column($list, 'title');
    assertContains('Shared todo ' . TEST_RUN_ID, $titles, 'planner-only share sees the inbox todo');
    assertNotContains('Shared task ' . TEST_RUN_ID, $titles, 'planner-only share must not see quadrant tasks');

    // หน้า Tasks ไม่อยู่ในลิงก์แชร์นี้
    assertContains($guest->get('/tasks')['status'], [403, 302], 'tasks page is not shared');
});

test('a share link with planner and tasks sees both kinds', function (TestClient $_c): void {
    $owner = plannerClient('share_both');
    $date  = date('Y-m-d', strtotime('+5 days'));

    $owner->post('/api/planner/todos', ['title' => 'Both todo ' . TEST_RUN_ID, 'date' => $date]);
    $owner->post('/api/tasks', [
        'title'    => 'Both task ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => $date,
    ]);

    $created = $owner->json($owner->post('/api/app-shares', [
        'label' => 'แพลนเนอร์และงาน ' . TEST_RUN_ID,
        'menus' => ['planner', 'tasks'],
    ]));

    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/shared/' . ($created['token'] ?? ''));

    $list   = $guest->json($guest->get('/api/planner/todos?date=' . $date))['todos'] ?? [];
    $titles = array_column($list, 'title');
    assertContains('Both todo ' . TEST_RUN_ID, $titles, 'combined share sees the inbox todo');
    assertContains('Both task ' . TEST_RUN_ID, $titles, 'combined share sees the quadrant task');
});

test('a task created on the Tasks page with a due date goes to the end of that day', function (TestClient $_c): void {
    $client = plannerClient('tasks_create');
    $date   = date('Y-m-d', strtotime('+6 days'));

    $plannerIds = [];
    foreach (['X', 'Y', 'Z'] as $label) {
        $created = $client->json($client->post('/api/planner/todos', ['title' => $label . ' ' . TEST_RUN_ID, 'date' => $date]));
        $plannerIds[] = (int)($created['todo']['id'] ?? 0);
    }

    $newTask = $client->json($client->post('/api/tasks', [
        'title'    => 'NEW ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => $date,
    ]))['task'] ?? [];

    $list = $client->json($client->get('/api/planner/todos?date=' . $date))['todos'] ?? [];
    $titles = array_column($list, 'title');
    assertSame(
        ['X ' . TEST_RUN_ID, 'Y ' . TEST_RUN_ID, 'Z ' . TEST_RUN_ID, 'NEW ' . TEST_RUN_ID],
        $titles,
        'new task from Tasks page should be last'
    );
});

test('a task moved to a date via PUT lands at the end of that day', function (TestClient $_c): void {
    $client = plannerClient('tasks_move');
    $dateD  = date('Y-m-d', strtotime('+7 days'));
    $dateE  = date('Y-m-d', strtotime('+8 days'));

    foreach (['X', 'Y', 'Z'] as $label) {
        $client->post('/api/planner/todos', ['title' => $label . ' ' . TEST_RUN_ID, 'date' => $dateD]);
    }

    $moved = $client->json($client->post('/api/tasks', [
        'title'    => 'MOVE ME ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => $dateE,
    ]))['task'] ?? [];

    $client->request('PUT', '/api/tasks/' . (int)$moved['id'], ['due_date' => $dateD]);

    $list = $client->json($client->get('/api/planner/todos?date=' . $dateD))['todos'] ?? [];
    $titles = array_column($list, 'title');
    assertSame(
        ['X ' . TEST_RUN_ID, 'Y ' . TEST_RUN_ID, 'Z ' . TEST_RUN_ID, 'MOVE ME ' . TEST_RUN_ID],
        $titles,
        'moved task should become last on the new date'
    );
});

test('updating only the title keeps the planner order unchanged', function (TestClient $_c): void {
    $client = plannerClient('title_update');
    $date   = date('Y-m-d', strtotime('+9 days'));

    $ids = [];
    foreach (['X', 'Y', 'Z'] as $label) {
        $created = $client->json($client->post('/api/planner/todos', ['title' => $label . ' ' . TEST_RUN_ID, 'date' => $date]));
        $ids[] = (int)($created['todo']['id'] ?? 0);
    }

    $client->request('PUT', '/api/tasks/' . $ids[1], ['title' => 'Y renamed ' . TEST_RUN_ID]);

    $list = $client->json($client->get('/api/planner/todos?date=' . $date))['todos'] ?? [];
    $titles = array_column($list, 'title');
    assertSame(
        ['X ' . TEST_RUN_ID, 'Y renamed ' . TEST_RUN_ID, 'Z ' . TEST_RUN_ID],
        $titles,
        'title-only update must not reorder'
    );
});

test('sending the same due_date keeps the task in its planner place', function (TestClient $_c): void {
    $client = plannerClient('same_date');
    $date   = date('Y-m-d', strtotime('+10 days'));

    $ids = [];
    foreach (['X', 'Y', 'Z'] as $label) {
        $created = $client->json($client->post('/api/planner/todos', ['title' => $label . ' ' . TEST_RUN_ID, 'date' => $date]));
        $ids[] = (int)($created['todo']['id'] ?? 0);
    }

    $client->request('PUT', '/api/tasks/' . $ids[0], ['due_date' => $date]);

    $list = $client->json($client->get('/api/planner/todos?date=' . $date))['todos'] ?? [];
    $titles = array_column($list, 'title');
    assertSame(
        ['X ' . TEST_RUN_ID, 'Y ' . TEST_RUN_ID, 'Z ' . TEST_RUN_ID],
        $titles,
        'same-date update must keep position'
    );
});
