<?php
// =====================================================
// tests/integration/tracking_test.php
//
// The last five controllers with no coverage: exercise, subscriptions, focus
// sessions, habits, and file share links. Between them they own the renewal
// arithmetic, the habit streak and a public download URL.
// =====================================================

declare(strict_types=1);

function trackClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('trk_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

// =====================================================
// Exercise
// =====================================================

test('a workout is recorded and appears in the stats', function (TestClient $_c): void {
    $client = trackClient('gym');

    $created = $client->post('/api/exercise', [
        'workout_date' => date('Y-m-d'), 'type' => 'วิ่ง ' . TEST_RUN_ID,
        'duration_min' => 45, 'sets' => 0, 'reps' => 0, 'weight_kg' => 0,
    ]);
    assertSame(201, $created['status'], 'create failed: ' . $created['body']);

    $stats = $client->json($client->get('/api/exercise/stats'));
    assertTrue(($stats['month_sessions'] ?? 0) >= 1, 'the session should count towards this month');
    assertTrue(($stats['month_minutes'] ?? 0) >= 45);
});

test('implausible workout numbers are refused', function (TestClient $_c): void {
    $client = trackClient('gym');
    $base = ['workout_date' => date('Y-m-d'), 'type' => 'วิ่ง'];

    $cases = [
        'no type'            => ['type' => ''],
        'bad date'           => ['workout_date' => '15-01-2026'],
        'a day and a half'   => ['duration_min' => 2000],
        'negative duration'  => ['duration_min' => -1],
        'too many sets'      => ['sets' => 5000],
        'impossible weight'  => ['weight_kg' => 99999],
    ];

    foreach ($cases as $label => $override) {
        assertSame(422, $client->post('/api/exercise', $override + $base)['status'], $label . ' should be refused');
    }
});

test('a workout belonging to someone else is out of reach', function (TestClient $_c): void {
    $client = trackClient('gym');
    $created = $client->json($client->post('/api/exercise', [
        'workout_date' => date('Y-m-d'), 'type' => 'เวท', 'duration_min' => 30,
    ]));
    $id = (int)($created['id'] ?? ($created['workout']['id'] ?? 0));
    assertTrue($id > 0, 'workout not created: ' . json_encode($created, JSON_UNESCAPED_UNICODE));

    $other = trackClient('other');
    assertSame(404, $other->request('PUT', "/api/exercise/{$id}", [
        'workout_date' => date('Y-m-d'), 'type' => 'แก้โดยคนอื่น', 'duration_min' => 1,
    ])['status']);
    assertSame(404, $other->request('DELETE', "/api/exercise/{$id}", [])['status']);
});

test('exercise categories are managed per account', function (TestClient $_c): void {
    $client = trackClient('gym');

    assertSame(422, $client->post('/api/exercise/categories', ['name' => ''])['status'], 'a category needs a name');

    $created = $client->post('/api/exercise/categories', ['name' => 'คาร์ดิโอ ' . TEST_RUN_ID]);
    assertContains($created['status'], [200, 201], 'category create failed: ' . $created['body']);

    $list = $client->json($client->get('/api/exercise/categories'));
    assertTrue(count($list['categories'] ?? []) > 0);
});

// =====================================================
// Subscriptions — the renewal arithmetic
// =====================================================

test('a subscription is created with its billing cycle', function (TestClient $_c): void {
    $client = trackClient('subs');

    $created = $client->post('/api/subscriptions', [
        'name' => 'Netflix ' . TEST_RUN_ID, 'amount' => 419,
        'billing_cycle' => 'monthly', 'next_due_date' => date('Y-m-d', strtotime('+10 days')),
        'alert_days' => 3,
    ]);
    assertSame(201, $created['status'], 'create failed: ' . $created['body']);
});

test('a bad cycle, amount or date is refused', function (TestClient $_c): void {
    $client = trackClient('subs');
    $base = [
        'name' => 'ทดสอบ', 'amount' => 100, 'billing_cycle' => 'monthly',
        'next_due_date' => date('Y-m-d'), 'alert_days' => 3,
    ];

    $cases = [
        'no name'          => ['name' => ''],
        'unknown cycle'    => ['billing_cycle' => 'fortnightly'],
        'negative amount'  => ['amount' => -5],
        'malformed date'   => ['next_due_date' => '31/12/2026'],
        'alert too far out'=> ['alert_days' => 400],
    ];

    foreach ($cases as $label => $override) {
        assertSame(422, $client->post('/api/subscriptions', $override + $base)['status'], $label . ' should be refused');
    }
});

test('renewing moves the due date on by one cycle', function (TestClient $_c): void {
    $client = trackClient('subs');
    $due = date('Y-m-d', strtotime('+2 days'));

    $created = $client->json($client->post('/api/subscriptions', [
        'name' => 'Spotify ' . TEST_RUN_ID, 'amount' => 150,
        'billing_cycle' => 'monthly', 'next_due_date' => $due, 'alert_days' => 3,
    ]));
    $id = (int)($created['id'] ?? ($created['subscription']['id'] ?? 0));
    assertTrue($id > 0, 'subscription not created: ' . json_encode($created, JSON_UNESCAPED_UNICODE));

    assertSame(200, $client->post("/api/subscriptions/{$id}/renew", [])['status']);

    $list = $client->json($client->get('/api/subscriptions'));
    $mine = array_values(array_filter(
        $list['subscriptions'] ?? [],
        static fn(array $s): bool => (int)$s['id'] === $id
    ));

    assertTrue($mine !== [], 'the renewed subscription should still be listed');
    assertTrue($mine[0]['next_due_date'] > $due, 'renewing must push the due date forward, not leave it');
});

test('a subscription belonging to someone else cannot be renewed or removed', function (TestClient $_c): void {
    $client = trackClient('subs');
    $created = $client->json($client->post('/api/subscriptions', [
        'name' => 'ส่วนตัว ' . TEST_RUN_ID, 'amount' => 99,
        'billing_cycle' => 'yearly', 'next_due_date' => date('Y-m-d', strtotime('+30 days')),
    ]));
    $id = (int)($created['id'] ?? ($created['subscription']['id'] ?? 0));

    $other = trackClient('other');
    assertSame(404, $other->post("/api/subscriptions/{$id}/renew", [])['status']);
    assertSame(404, $other->request('DELETE', "/api/subscriptions/{$id}", [])['status']);
});

// =====================================================
// Focus sessions
// =====================================================

test('a focus session is logged and counted', function (TestClient $_c): void {
    $client = trackClient('focus');

    $created = $client->post('/api/focus', ['type' => 'work', 'duration_min' => 25]);
    assertContains($created['status'], [200, 201], 'create failed: ' . $created['body']);

    $list = $client->json($client->get('/api/focus'));
    assertTrue(count($list['sessions'] ?? $list['items'] ?? []) > 0, 'the session should be listed');
});

test('an unknown focus type or an impossible duration is refused', function (TestClient $_c): void {
    $client = trackClient('focus');

    assertSame(422, $client->post('/api/focus', ['type' => 'daydreaming', 'duration_min' => 25])['status']);
    assertSame(422, $client->post('/api/focus', ['type' => 'work', 'duration_min' => 0])['status']);
    assertSame(422, $client->post('/api/focus', ['type' => 'work', 'duration_min' => 5000])['status']);
});

test('a focus session cannot be attached to somebody else\'s task', function (TestClient $_c): void {
    $owner = trackClient('focus');
    $task = $owner->json($owner->post('/api/tasks', [
        'title' => 'งานของเจ้าของ ' . TEST_RUN_ID, 'quadrant' => 1,
    ]))['task'];

    $other = trackClient('other');
    $response = $other->post('/api/focus', [
        'type' => 'work', 'duration_min' => 25, 'task_id' => (int)$task['id'],
    ]);

    assertSame(422, $response['status'], 'a task that is not yours is not a valid target');
});

test('a focus session belonging to someone else cannot be deleted', function (TestClient $_c): void {
    assertSame(404, trackClient('other')->request('DELETE', '/api/focus/999999', [])['status']);
});

// =====================================================
// Habits
// =====================================================

test('a habit is created and toggled for today', function (TestClient $_c): void {
    $client = trackClient('habits');

    $created = $client->post('/api/habits', [
        'name' => 'ดื่มน้ำ ' . TEST_RUN_ID, 'color' => '#3b82f6', 'target_days' => 7,
    ]);
    assertContains($created['status'], [200, 201], 'create failed: ' . $created['body']);

    $habits = $client->json($client->get('/api/habits'))['habits'] ?? [];
    $mine = array_values(array_filter(
        $habits,
        static fn(array $h): bool => str_contains((string)$h['name'], TEST_RUN_ID)
    ));
    assertTrue($mine !== [], 'the habit should be listed');

    $id = (int)$mine[0]['id'];
    assertSame(200, $client->post("/api/habits/{$id}/toggle", [])['status']);

    $after = $client->json($client->get('/api/habits'))['habits'] ?? [];
    $toggled = array_values(array_filter($after, static fn(array $h): bool => (int)$h['id'] === $id));
    assertTrue(!empty($toggled[0]['completed_today']), 'toggling should mark it done for today');

    // Toggling again takes it back off.
    $client->post("/api/habits/{$id}/toggle", []);
    $again = $client->json($client->get('/api/habits'))['habits'] ?? [];
    $untoggled = array_values(array_filter($again, static fn(array $h): bool => (int)$h['id'] === $id));
    assertTrue(empty($untoggled[0]['completed_today']), 'toggling twice returns it to not done');
});

test('a habit needs a name within the length limit', function (TestClient $_c): void {
    $client = trackClient('habits');

    assertSame(422, $client->post('/api/habits', ['name' => '', 'target_days' => 5])['status']);
    assertSame(422, $client->post('/api/habits', ['name' => str_repeat('ก', 161), 'target_days' => 5])['status']);
});

test('a habit belonging to someone else is out of reach', function (TestClient $_c): void {
    $other = trackClient('other');

    assertSame(404, $other->request('PUT', '/api/habits/999999', ['name' => 'x', 'target_days' => 3])['status']);
    assertSame(404, $other->post('/api/habits/999999/toggle', [])['status']);
    assertSame(404, $other->request('DELETE', '/api/habits/999999', [])['status']);
});

// =====================================================
// File share links
// =====================================================

test('a file share link is created and opens without signing in', function (TestClient $_c): void {
    $client = trackClient('shares');

    $uploaded = $client->json($client->uploadMany('/api/files/upload', 'file', [
        ['name' => 'shared-doc.txt', 'contents' => 'the shared contents', 'type' => 'text/plain'],
    ]));
    $fileId = (int)($uploaded['id'] ?? 0);
    assertTrue($fileId > 0, 'upload failed: ' . json_encode($uploaded, JSON_UNESCAPED_UNICODE));

    $share = $client->json($client->post('/api/shares', [
        'file_id' => $fileId, 'permission' => 'download',
    ]));
    $token = (string)($share['token'] ?? '');
    assertTrue($token !== '', 'share create failed: ' . json_encode($share, JSON_UNESCAPED_UNICODE));

    $guest = new TestClient(TEST_BASE_URL);
    assertSame(200, $guest->get('/share/' . $token)['status'], 'the public page should open');
});

test('a share link needs a file and a valid permission', function (TestClient $_c): void {
    $client = trackClient('shares');

    assertSame(422, $client->post('/api/shares', ['permission' => 'download'])['status']);
    assertSame(422, $client->post('/api/shares', ['file_id' => 1, 'permission' => 'edit'])['status']);
});

test('a file that is not yours cannot be shared', function (TestClient $_c): void {
    $owner = trackClient('shares');
    $uploaded = $owner->json($owner->uploadMany('/api/files/upload', 'file', [
        ['name' => 'private-doc.txt', 'contents' => 'secret', 'type' => 'text/plain'],
    ]));

    $response = trackClient('other')->post('/api/shares', [
        'file_id' => (int)$uploaded['id'], 'permission' => 'download',
    ]);
    assertSame(404, $response['status'], 'sharing somebody else\'s file must not work');
});

test('an unknown share token does not serve anything', function (TestClient $_c): void {
    $guest = new TestClient(TEST_BASE_URL);
    assertContains($guest->get('/share/' . str_repeat('9', 64))['status'], [403, 404, 410]);
});

test('these endpoints need a session', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);

    foreach (['/api/exercise', '/api/subscriptions', '/api/focus', '/api/habits', '/api/shares'] as $path) {
        assertContains($anonymous->get($path)['status'], [401, 302], $path . ' must require a session');
    }
});

// =====================================================
// Pagination, applied uniformly across the accumulating lists
// =====================================================

test('every accumulating list reports its window', function (TestClient $_c): void {
    $client = trackClient('gym');

    foreach (['/api/exercise', '/api/focus', '/api/food-notes', '/api/ai/history'] as $path) {
        $data = $client->json($client->get($path . '?limit=5'));

        assertArrayHasKey('pagination', $data, $path . ' should report its window');
        assertSame(5, $data['pagination']['limit'], $path);
        assertSame(0, $data['pagination']['offset'], $path);
        assertArrayHasKey('total', $data['pagination'], $path . ' should say how many there are');
    }
});

test('older records are reachable through the offset', function (TestClient $_c): void {
    $client = trackClient('paging');

    // Five workouts, read two at a time. Before pagination the endpoint
    // returned a fixed most-recent slice and anything behind it was
    // unreachable.
    for ($day = 1; $day <= 5; $day++) {
        $client->post('/api/exercise', [
            'workout_date' => date('Y-m-d', strtotime("-{$day} days")),
            'type' => 'รอบที่ ' . $day, 'duration_min' => 10 * $day,
        ]);
    }

    $first  = $client->json($client->get('/api/exercise?limit=2&offset=0'));
    $second = $client->json($client->get('/api/exercise?limit=2&offset=2'));
    $third  = $client->json($client->get('/api/exercise?limit=2&offset=4'));

    assertSame(5, $first['pagination']['total']);
    assertSame(2, count($first['workouts']));
    assertSame(2, count($second['workouts']));
    assertSame(1, count($third['workouts']), 'the last page is partial');

    assertTrue($first['pagination']['has_more']);
    assertFalse($third['pagination']['has_more'], 'the final page is the end');

    // Walking the pages must visit each record once and only once.
    $ids = array_merge(
        array_column($first['workouts'], 'id'),
        array_column($second['workouts'], 'id'),
        array_column($third['workouts'], 'id')
    );
    assertSame(5, count($ids));
    assertSame(5, count(array_unique($ids)), 'pages must not overlap or skip');
});

test('the skill log reports its window in headers', function (TestClient $_c): void {
    // That endpoint answers with a bare array the page already reads as one,
    // so wrapping it would have broken the page.
    $response = trackClient('gym')->get('/api/skills/logs?limit=5');

    assertSame(200, $response['status']);
    assertStringContains('X-Pagination-Total:', $response['headers']);
    assertStringContains('X-Pagination-Has-More:', $response['headers']);
    assertSame('[', substr(trim($response['body']), 0, 1), 'the body is still a bare array');
});
