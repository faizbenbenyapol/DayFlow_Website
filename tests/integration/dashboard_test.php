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

    assertArrayHasKey('overdue', $data['tasks']);
    assertArrayHasKey('items', $data['tasks']);
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

function todayClient(string $label): TestClient
{
    $client = new TestClient(TEST_BASE_URL);
    $client->login('today_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
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
