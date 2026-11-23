<?php
// =====================================================
// tests/integration/dashboard_test.php
//
// The summary endpoint builds one block of queries per widget, so it must
// return exactly the modules the client asked for — no more (wasted queries)
// and no fewer (blank widgets).
// =====================================================

declare(strict_types=1);

test('summary without a modules hint returns every module', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary'));

    foreach (['tasks', 'calendar', 'habits', 'finance', 'workout', 'focus', 'subscriptions', 'projects', 'notes', 'stocks', 'transfer'] as $module) {
        assertArrayHasKey($module, $data, 'legacy callers must keep receiving every module');
    }
});

test('summary returns only the requested modules', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks,finance'));

    assertArrayHasKey('tasks', $data);
    assertArrayHasKey('finance', $data);
    assertArrayNotHasKey('stocks', $data, 'a hidden widget must not be built');
    assertArrayNotHasKey('projects', $data);
    assertSame(['tasks', 'finance'], $data['meta']['modules']);
});

test('summary carries the server clock that note times are written in', function (TestClient $client): void {
    // The page measures "x นาทีที่แล้ว" against this, not the browser's clock.
    $client->post('/api/notes', ['title' => 'นาฬิกา ' . TEST_RUN_ID, 'is_encrypted' => false]);
    $data = $client->json($client->get('/api/dashboard/summary?modules=notes'));

    $serverTime = (string)($data['meta']['server_time'] ?? '');
    assertTrue((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $serverTime), 'a wall clock: ' . $serverTime);

    // Pinned notes come first, so the newest is not always the first one.
    $stamps = array_column($data['notes']['items'] ?? [], 'updated_at');
    $latest = $stamps === [] ? null : max($stamps);
    assertTrue(is_string($latest), 'a note to compare: ' . json_encode($data['notes'] ?? null));
    $gap = strtotime($serverTime) - strtotime($latest);
    assertTrue($gap >= 0 && $gap < 120, "the note was just saved, yet it is {$gap}s from the server clock");
});

test('summary ignores module names it does not know', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks,bogus,../etc/passwd'));

    assertSame(['tasks'], $data['meta']['modules'], 'unknown names must be dropped, not passed through');
    assertArrayHasKey('tasks', $data);
});

test('summary reports no failing modules on a healthy schema', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary'));

    assertSame([], $data['meta']['warnings'], 'a module fell back to its empty value');
    assertFalse($data['meta']['partial']);
});

test('summary payload keeps the shape the widgets render', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks,finance'));

    foreach (['overdue', 'due_today', 'overdue_items', 'today_items', 'my_project_items'] as $key) {
        assertArrayHasKey($key, $data['tasks']);
    }
    foreach (['month', 'income', 'expense', 'balance'] as $key) {
        assertArrayHasKey($key, $data['finance']);
    }
});

test('summary requires a session', function (TestClient $_client): void {
    $anonymous = new TestClient(TEST_BASE_URL);
    $response  = $anonymous->get('/api/dashboard/summary');

    assertContains($response['status'], [401, 302], 'an unauthenticated caller must not get data');
});

// =====================================================
// The Today page's sections
// =====================================================

require_once ROOT . '/config/database.php';

function todayClient(string $label): TestClient
{
    $client = new TestClient(TEST_BASE_URL);
    $client->login('today_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

function todayDbClient(string $label): TestClient
{
    $username = 'todaydb_' . $label . '_' . TEST_RUN_ID;
    dashboardCreateUser($username);
    $client = new TestClient(TEST_BASE_URL);
    $client->login($username, 'TestPass123!');
    return $client;
}

function dashboardCreateUser(string $username): int
{
    $hash = password_hash('TestPass123!', PASSWORD_BCRYPT, ['cost' => 12]);
    DB::run(
        "INSERT INTO users (username, email, password_hash, display_name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)",
        [$username, $username . '@example.test', $hash, $username]
    );
    $userId = (int)DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn();
    DB::run(
        "INSERT INTO user_settings (user_id, theme, timezone) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = user_settings.user_id",
        [$userId, 'light', 'Asia/Bangkok']
    );
    return $userId;
}

test('tasks are split into overdue and due today, with the ids a tick needs', function (TestClient $_c): void {
    $client = todayClient('tasks');
    $late  = $client->json($client->post('/api/tasks', ['title' => 'เกิน ' . TEST_RUN_ID, 'quadrant' => 1, 'due_date' => date('Y-m-d', strtotime('-2 day'))]));
    $today = $client->json($client->post('/api/tasks', ['title' => 'วันนี้ ' . TEST_RUN_ID, 'quadrant' => 2, 'due_date' => date('Y-m-d')]));
    $later = $client->json($client->post('/api/tasks', ['title' => 'ไว้ก่อน ' . TEST_RUN_ID, 'quadrant' => 2, 'due_date' => date('Y-m-d', strtotime('+9 day'))]));

    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks'))['tasks'];

    $lateIds  = array_column($data['overdue_items'], 'id');
    $todayIds = array_column($data['today_items'], 'id');
    assertContains($late['task']['id'], $lateIds, 'an overdue task is listed as overdue');
    assertContains($today['task']['id'], $todayIds, 'a task due today is listed for today');
    assertNotContains($later['task']['id'], array_merge($lateIds, $todayIds), 'a task due later is neither');
    assertNotContains($today['task']['id'], $lateIds, 'today is not overdue');
    assertTrue($data['due_today'] >= 1 && $data['overdue'] >= 1, 'the counts agree with the lists');

    // Ticking one takes it off the list.
    $client->request('PUT', '/api/tasks/' . $today['task']['id'], ['status' => 'done']);
    $after = $client->json($client->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertNotContains($today['task']['id'], array_column($after['today_items'], 'id'));
});

test('overdue and today items are ordered and separated by due date', function (TestClient $_c): void {
    $client = todayDbClient('due_order');
    $late3 = $client->json($client->post('/api/tasks', ['title' => '3 วันก่อน ' . TEST_RUN_ID, 'quadrant' => 1, 'due_date' => date('Y-m-d', strtotime('-3 day'))]))['task'];
    $late1 = $client->json($client->post('/api/tasks', ['title' => 'เมื่อวาน ' . TEST_RUN_ID, 'quadrant' => 1, 'due_date' => date('Y-m-d', strtotime('-1 day'))]))['task'];
    $today = $client->json($client->post('/api/tasks', ['title' => 'วันนี้ ' . TEST_RUN_ID, 'quadrant' => 1, 'due_date' => date('Y-m-d')]))['task'];
    $noDate = $client->json($client->post('/api/tasks', ['title' => 'ไม่มีวัน ' . TEST_RUN_ID, 'quadrant' => 0]))['task'];

    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    $overdueIds = array_column($data['overdue_items'], 'id');
    $todayIds = array_column($data['today_items'], 'id');

    assertSame([(int)$late3['id'], (int)$late1['id']], array_slice($overdueIds, 0, 2), 'overdue tasks sorted oldest first');
    assertContains((int)$today['id'], $todayIds, 'today task is in today_items');
    assertNotContains((int)$noDate['id'], array_merge($overdueIds, $todayIds), 'task without due date is neither');
});

test('a task with quadrant 0 and due date today appears in today_items', function (TestClient $_c): void {
    $client = todayDbClient('today_inbox');
    $task = $client->json($client->post('/api/tasks', [
        'title'    => 'วันนี้ inbox ' . TEST_RUN_ID,
        'quadrant' => 0,
        'due_date' => date('Y-m-d'),
    ]))['task'];

    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    $todayIds = array_column($data['today_items'], 'id');
    assertContains((int)$task['id'], $todayIds, 'inbox task due today is listed for today');
});

test('bulk overdue action moves all overdue tasks to today in old due-date order', function (TestClient $_c): void {
    $client = todayDbClient('overdue_bulk_today');
    $ids = [];
    for ($i = 6; $i >= 0; $i--) {
        $task = $client->json($client->post('/api/tasks', [
            'title'    => 'ค้าง ' . $i . ' ' . TEST_RUN_ID,
            'quadrant' => 1,
            'due_date' => date('Y-m-d', strtotime('-' . ($i + 1) . ' day')),
        ]))['task'];
        $ids[] = (int)$task['id'];
    }

    $result = $client->json($client->post('/api/tasks/overdue', ['action' => 'today']));
    assertTrue($result['ok'] ?? false);
    assertSame(7, $result['count'] ?? 0);

    $list = $client->json($client->get('/api/planner/todos?date=' . date('Y-m-d')))['todos'] ?? [];
    $moved = array_values(array_filter(array_column($list, 'id'), static fn(int $id): bool => in_array($id, $ids, true)));
    assertSame($ids, $moved, 'moved tasks appear at the end of today in old due-date order');
});

test('bulk overdue actions tomorrow, date, clear and delete each change all overdue tasks', function (TestClient $_c): void {
    // tomorrow
    $client = todayDbClient('overdue_bulk_tomorrow');
    $ids = [];
    for ($i = 2; $i >= 1; $i--) {
        $ids[] = (int)$client->json($client->post('/api/tasks', [
            'title'    => 'tomorrow ' . $i . ' ' . TEST_RUN_ID,
            'quadrant' => 1,
            'due_date' => date('Y-m-d', strtotime('-' . $i . ' day')),
        ]))['task']['id'];
    }
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $res = $client->json($client->post('/api/tasks/overdue', ['action' => 'tomorrow']));
    assertSame(2, $res['count'] ?? 0);
    $list = $client->json($client->get('/api/planner/todos?date=' . $tomorrow))['todos'] ?? [];
    assertSame($ids, array_values(array_intersect(array_column($list, 'id'), $ids)));

    // date
    $client2 = todayDbClient('overdue_bulk_date');
    $ids2 = [];
    for ($i = 2; $i >= 1; $i--) {
        $ids2[] = (int)$client2->json($client2->post('/api/tasks', [
            'title'    => 'date ' . $i . ' ' . TEST_RUN_ID,
            'quadrant' => 1,
            'due_date' => date('Y-m-d', strtotime('-' . $i . ' day')),
        ]))['task']['id'];
    }
    $target = date('Y-m-d', strtotime('+7 day'));
    $res2 = $client2->json($client2->post('/api/tasks/overdue', ['action' => 'date', 'date' => $target]));
    assertSame(2, $res2['count'] ?? 0);
    $list2 = $client2->json($client2->get('/api/planner/todos?date=' . $target))['todos'] ?? [];
    assertSame($ids2, array_values(array_intersect(array_column($list2, 'id'), $ids2)));

    // clear
    $client3 = todayDbClient('overdue_bulk_clear');
    $ids3 = [];
    for ($i = 0; $i < 2; $i++) {
        $ids3[] = (int)$client3->json($client3->post('/api/tasks', [
            'title'    => 'clear ' . $i . ' ' . TEST_RUN_ID,
            'quadrant' => 1,
            'due_date' => date('Y-m-d', strtotime('-' . ($i + 1) . ' day')),
        ]))['task']['id'];
    }
    $res3 = $client3->json($client3->post('/api/tasks/overdue', ['action' => 'clear']));
    assertSame(2, $res3['count'] ?? 0);
    $tasks = $client3->json($client3->get('/api/tasks'))['quadrants'] ?? [];
    $cleared = [];
    foreach ($tasks as $quadrant) {
        foreach ($quadrant as $t) {
            if (in_array((int)$t['id'], $ids3, true)) {
                $cleared[] = $t;
            }
        }
    }
    assertSame(2, count($cleared));
    foreach ($cleared as $t) {
        assertSame(null, $t['due_date'] ?? null, 'cleared task has no due_date');
    }

    // delete
    $client4 = todayDbClient('overdue_bulk_delete');
    $ids4 = [];
    for ($i = 0; $i < 2; $i++) {
        $ids4[] = (int)$client4->json($client4->post('/api/tasks', [
            'title'    => 'delete ' . $i . ' ' . TEST_RUN_ID,
            'quadrant' => 1,
            'due_date' => date('Y-m-d', strtotime('-' . ($i + 1) . ' day')),
        ]))['task']['id'];
    }
    $res4 = $client4->json($client4->post('/api/tasks/overdue', ['action' => 'delete']));
    assertSame(2, $res4['count'] ?? 0);
    $after = $client4->json($client4->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    foreach ($ids4 as $id) {
        assertNotContains($id, array_merge(array_column($after['overdue_items'], 'id'), array_column($after['today_items'], 'id')));
    }

    // bad date
    assertSame(422, $client2->post('/api/tasks/overdue', ['action' => 'date', 'date' => 'not-a-date'])['status']);

    // zero overdue
    $zero = todayDbClient('overdue_bulk_zero');
    $zeroRes = $zero->json($zero->post('/api/tasks/overdue', ['action' => 'today']));
    assertTrue($zeroRes['ok'] ?? false);
    assertSame(0, $zeroRes['count'] ?? 0);
});

test('bulk overdue actions do not touch another user\'s tasks', function (TestClient $_c): void {
    $client = todayDbClient('overdue_bulk_mine');
    $other  = todayDbClient('overdue_bulk_other');

    $mine = (int)$client->json($client->post('/api/tasks', [
        'title'    => 'ของฉัน ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => date('Y-m-d', strtotime('-1 day')),
    ]))['task']['id'];

    $otherId = (int)$other->json($other->post('/api/tasks', [
        'title'    => 'ของคนอื่น ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => date('Y-m-d', strtotime('-2 day')),
    ]))['task']['id'];

    $client->json($client->post('/api/tasks/overdue', ['action' => 'today']));

    $otherTasks = $other->json($other->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertContains($otherId, array_column($otherTasks['overdue_items'], 'id'), 'other user overdue task is untouched');

    $myTasks = $client->json($client->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertContains($mine, array_column($myTasks['today_items'], 'id'), 'my task moved to today');
});

test('bulk overdue delete and clear do not touch another user\'s tasks', function (TestClient $_c): void {
    $client = todayDbClient('overdue_delete_mine');
    $other  = todayDbClient('overdue_delete_other');

    $other->json($other->post('/api/tasks', [
        'title'    => 'ของคนอื่นรอด ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => date('Y-m-d', strtotime('-2 day')),
    ]));

    $client->json($client->post('/api/tasks', [
        'title'    => 'ของฉันลบ ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => date('Y-m-d', strtotime('-1 day')),
    ]));

    $res = $client->json($client->post('/api/tasks/overdue', ['action' => 'delete']));
    assertSame(1, $res['count'] ?? 0);

    $otherTasks = $other->json($other->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertSame(1, count($otherTasks['overdue_items'] ?? []), 'other user overdue task survives bulk delete');

    $client2 = todayDbClient('overdue_clear_mine');
    $client2->json($client2->post('/api/tasks', [
        'title'    => 'ของฉันเคลียร์ ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => date('Y-m-d', strtotime('-1 day')),
    ]));

    $res2 = $client2->json($client2->post('/api/tasks/overdue', ['action' => 'clear']));
    assertSame(1, $res2['count'] ?? 0);

    $otherTasks2 = $other->json($other->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertSame(1, count($otherTasks2['overdue_items'] ?? []), 'other user overdue task survives bulk clear');
});

test('my_project_items lists assigned project tasks due today or earlier', function (TestClient $_c): void {
    $run = 'dashproj_' . TEST_RUN_ID;
    $ownerU = 'dash_owner_' . $run;
    $memberAU = 'dash_member_a_' . $run;
    $memberBU = 'dash_member_b_' . $run;
    dashboardCreateUser($ownerU);
    $memberAId = dashboardCreateUser($memberAU);
    dashboardCreateUser($memberBU);

    $owner = new TestClient(TEST_BASE_URL);
    $owner->login($ownerU, 'TestPass123!');
    $memberA = new TestClient(TEST_BASE_URL);
    $memberA->login($memberAU, 'TestPass123!');
    $memberB = new TestClient(TEST_BASE_URL);
    $memberB->login($memberBU, 'TestPass123!');

    $project = $owner->json($owner->post('/api/projects', [
        'name'     => 'โครงการทดสอบหน้าวันนี้ ' . $run,
        'status'   => 'Planning',
        'priority' => 'Medium',
    ]));
    $projectId = (int)($project['id'] ?? ($project['project']['id'] ?? 0));
    assertTrue($projectId > 0, 'project was created');

    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $memberAU, 'role' => 'Editor']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $memberBU, 'role' => 'Editor']);

    $todayTask = $owner->json($owner->post("/api/projects/{$projectId}/tasks", [
        'title'    => 'งานวันนี้ ' . $run,
        'status'   => 'To Do',
        'priority' => 'Medium',
        'due_date' => date('Y-m-d'),
    ]));
    $todayTaskId = (int)($todayTask['id'] ?? ($todayTask['task']['id'] ?? 0));

    $owner->request('PUT', '/api/projects/tasks/' . $todayTaskId, ['assignee_user_id' => $memberAId]);

    $aSummary = $memberA->json($memberA->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    $aItems = $aSummary['my_project_items'] ?? [];
    assertSame(1, count($aItems), 'member A sees the assigned task');
    assertSame($todayTaskId, (int)$aItems[0]['id']);
    assertSame('โครงการทดสอบหน้าวันนี้ ' . $run, $aItems[0]['project_name']);
    assertTrue((bool)($aItems[0]['can_edit'] ?? false), 'member A can edit as Editor');

    $bSummary = $memberB->json($memberB->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertSame(0, count($bSummary['my_project_items'] ?? []), 'member B does not see A task');

    // Done task is not listed
    $owner->request('PUT', '/api/projects/tasks/' . $todayTaskId, ['status' => 'Done']);
    $aAfterDone = $memberA->json($memberA->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertSame(0, count($aAfterDone['my_project_items'] ?? []), 'Done task disappears');

    // Task due tomorrow is not listed
    $owner->request('PUT', '/api/projects/tasks/' . $todayTaskId, ['status' => 'To Do', 'due_date' => date('Y-m-d', strtotime('+1 day'))]);
    $aAfterTomorrow = $memberA->json($memberA->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertSame(0, count($aAfterTomorrow['my_project_items'] ?? []), 'future task is not listed');

    // After removing A from the project the task is not listed
    $owner->request('PUT', '/api/projects/tasks/' . $todayTaskId, ['due_date' => date('Y-m-d')]);
    $owner->request('DELETE', "/api/projects/{$projectId}/members/{$memberAId}", []);
    $aAfterRemove = $memberA->json($memberA->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertSame(0, count($aAfterRemove['my_project_items'] ?? []), 'removed member no longer sees task');

    // Viewer role: can_edit is false and direct API update is refused
    $viewerProject = $owner->json($owner->post('/api/projects', [
        'name'     => 'โครงการ Viewer ' . $run,
        'status'   => 'Planning',
        'priority' => 'Medium',
    ]));
    $viewerProjectId = (int)($viewerProject['id'] ?? ($viewerProject['project']['id'] ?? 0));
    $owner->post("/api/projects/{$viewerProjectId}/members", ['email_or_username' => $memberAU, 'role' => 'Viewer']);
    $viewerTask = $owner->json($owner->post("/api/projects/{$viewerProjectId}/tasks", [
        'title'    => 'งาน Viewer ' . $run,
        'status'   => 'To Do',
        'priority' => 'Medium',
        'due_date' => date('Y-m-d'),
    ]));
    $viewerTaskId = (int)($viewerTask['id'] ?? ($viewerTask['task']['id'] ?? 0));
    $owner->request('PUT', '/api/projects/tasks/' . $viewerTaskId, ['assignee_user_id' => $memberAId]);

    $aViewerSummary = $memberA->json($memberA->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    $viewerItems = $aViewerSummary['my_project_items'] ?? [];
    assertSame(1, count($viewerItems), 'viewer sees the assigned task');
    assertFalse((bool)($viewerItems[0]['can_edit'] ?? false), 'viewer cannot edit');

    $forbidden = $memberA->request('PUT', '/api/projects/tasks/' . $viewerTaskId, ['status' => 'Done']);
    assertSame(403, $forbidden['status'], 'viewer cannot mark task done via API');
});

test('my_project_items excludes tasks assigned to a non-member', function (TestClient $_c): void {
    $run = 'dashproj_nonmember_' . TEST_RUN_ID;
    $ownerU = 'dash_owner_nm_' . $run;
    $strangerU = 'dash_stranger_nm_' . $run;
    dashboardCreateUser($ownerU);
    $strangerId = dashboardCreateUser($strangerU);

    $owner = new TestClient(TEST_BASE_URL);
    $owner->login($ownerU, 'TestPass123!');
    $stranger = new TestClient(TEST_BASE_URL);
    $stranger->login($strangerU, 'TestPass123!');

    $project = $owner->json($owner->post('/api/projects', [
        'name'     => 'โครงการทดสอบ non-member ' . $run,
        'status'   => 'Planning',
        'priority' => 'Medium',
    ]));
    $projectId = (int)($project['id'] ?? ($project['project']['id'] ?? 0));
    assertTrue($projectId > 0, 'project was created');

    $task = $owner->json($owner->post("/api/projects/{$projectId}/tasks", [
        'title'    => 'งาน non-member ' . $run,
        'status'   => 'To Do',
        'priority' => 'Medium',
        'due_date' => date('Y-m-d'),
    ]));
    $taskId = (int)($task['id'] ?? ($task['task']['id'] ?? 0));

    // Bypass the API guard that rejects non-members: set assignee directly in DB.
    DB::run('UPDATE project_tasks SET assignee_user_id = ? WHERE id = ?', [$strangerId, $taskId]);

    $strangerSummary = $stranger->json($stranger->get('/api/dashboard/summary?modules=tasks'))['tasks'];
    assertSame(0, count($strangerSummary['my_project_items'] ?? []), 'a non-member does not see an assigned task');
});

test('habits report today\'s tick and the streak', function (TestClient $_c): void {
    $client = todayClient('habits');
    $created = $client->json($client->post('/api/habits', ['name' => 'ดื่มน้ำ ' . TEST_RUN_ID, 'color' => '#3b82f6', 'target_days' => 7]));
    $id = (int)($created['id'] ?? $created['habit']['id'] ?? 0);
    assertTrue($id > 0, 'the habit was created: ' . json_encode($created));

    $find = function () use ($client, $id): array {
        $habits = $client->json($client->get('/api/dashboard/summary?modules=habits'))['habits'];
        foreach ($habits['items'] as $item) {
            if ($item['id'] === $id) return $item + ['_done' => $habits['done'], '_total' => $habits['total']];
        }
        throw new RuntimeException('the habit is missing from the summary');
    };

    $before = $find();
    assertFalse($before['done_today']);
    assertSame(0, $before['streak']);

    $client->post('/api/habits/' . $id . '/toggle', []);
    $ticked = $find();
    assertTrue($ticked['done_today'], 'ticked today');
    assertSame(1, $ticked['streak'], 'a first tick is a streak of one');
    assertTrue($ticked['_done'] >= 1 && $ticked['_total'] >= 1, 'the totals count it');

    $client->post('/api/habits/' . $id . '/toggle', []);
    assertFalse($find()['done_today'], 'unticking brings it back');
});

test('focus minutes and the new sections are in the summary and the layout', function (TestClient $_c): void {
    $client = todayClient('focus');
    $data = $client->json($client->get('/api/dashboard/summary?modules=focus'));

    assertSame(0, $data['focus']['today_minutes'], 'a new account has focused for no time');
    assertSame(0, $data['focus']['today_sessions']);

    // A section added later (habits) appears in the layout of an account that predates it.
    $settings = str_replace(' ', '', $client->get('/settings')['body']);
    assertStringContains('"widget_key":"habits"', $settings, 'the layout offers the habits section');
});

// =====================================================
// Habits: a week at a time
// =====================================================

test('a habit can be ticked for an earlier day, not a coming one', function (TestClient $_c): void {
    $client = todayClient('habitweek');
    $created = $client->json($client->post('/api/habits', ['name' => 'อ่านหนังสือ ' . TEST_RUN_ID, 'color' => '#10b981', 'target_days' => 5]));
    $id = (int)$created['id'];

    $day = fn(int $offset): string => date('Y-m-d', strtotime($offset . ' day'));

    foreach ([-1, -2, -3] as $offset) {
        $response = $client->post('/api/habits/' . $id . '/toggle', ['date' => $day($offset)]);
        assertSame(200, $response['status'], 'a past day should be tickable');
        assertTrue($client->json($response)['completed'], 'ticked');
    }

    // Three days in a row ending yesterday is a streak of three: today is not over.
    $list = $client->json($client->get('/api/habits?from=' . $day(-6) . '&to=' . $day(0)));
    $habit = array_values(array_filter($list['habits'], fn($h) => (int)$h['id'] === $id))[0];
    assertSame(3, $habit['streak'], 'the run up to yesterday counts');
    assertSame([$day(-3), $day(-2), $day(-1)], $list['logs'][$id] ?? $list['logs'][(string)$id] ?? [], 'the window lists the ticked days');

    // Ticking the same day again takes it away.
    $off = $client->json($client->post('/api/habits/' . $id . '/toggle', ['date' => $day(-2)]));
    assertFalse($off['completed'], 'a second tick unticks');

    assertSame(422, $client->post('/api/habits/' . $id . '/toggle', ['date' => $day(1)])['status'], 'tomorrow cannot be ticked');
    assertSame(422, $client->post('/api/habits/' . $id . '/toggle', ['date' => '2026-02-30'])['status'], 'not a real date');
    assertSame(422, $client->post('/api/habits/' . $id . '/toggle', ['date' => $day(-400)])['status'], 'more than a year back');

    // Without a date it is still today, as it always was.
    $today = $client->json($client->post('/api/habits/' . $id . '/toggle', []));
    assertTrue($today['completed'] && $today['completed_today']);
});

test('the week window is checked, and another account\'s habit is not reachable', function (TestClient $_c): void {
    $client = todayClient('habitwin');
    assertSame(422, $client->get('/api/habits?from=2026-01-01&to=2026-06-30')['status'], 'a window over 42 days');
    assertSame(422, $client->get('/api/habits?from=nope&to=2026-06-30')['status'], 'a bad date');
    assertSame(422, $client->get('/api/habits?from=2026-06-30&to=2026-06-01')['status'], 'end before start');
    assertSame(200, $client->get('/api/habits')['status'], 'no window is still fine');

    $mine = $client->json($client->post('/api/habits', ['name' => 'ของฉัน ' . TEST_RUN_ID, 'color' => '#10b981', 'target_days' => 3]));
    $other = todayClient('habitother');
    assertSame(404, $other->post('/api/habits/' . $mine['id'] . '/toggle', ['date' => date('Y-m-d', strtotime('-1 day'))])['status'], 'not theirs to tick');
});
