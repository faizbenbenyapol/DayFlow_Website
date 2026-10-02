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
