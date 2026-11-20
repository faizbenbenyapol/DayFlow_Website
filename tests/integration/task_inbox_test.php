<?php
// =====================================================
// tests/integration/task_inbox_test.php — inbox quadrant (0 = NULL)
// =====================================================

declare(strict_types=1);

test('creating a task with quadrant 0 stores it as NULL and returns it under key 0', function (TestClient $client): void {
    $tag = 'inbox-create-' . TEST_RUN_ID;
    $res = $client->post('/api/tasks', ['title' => $tag, 'quadrant' => 0]);
    assertSame(201, $res['status']);

    $data = $client->json($client->get('/api/tasks'));
    $inbox = $data['quadrants'][0] ?? [];
    $found = array_filter($inbox, static fn(array $t): bool => $t['title'] === $tag);
    assertTrue($found !== [], 'task created with quadrant 0 should appear in inbox group');
    $task = array_values($found)[0];
    assertSame(null, $task['quadrant'], 'inbox task must be stored with quadrant NULL');
});

test('updating a task to quadrant 0 moves it to inbox and back to quadrant 2 moves it out', function (TestClient $client): void {
    $tag = 'inbox-update-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 1]));
    $id = (int)($created['task']['id'] ?? 0);
    assertTrue($id > 0, 'task should be created');

    $toInbox = $client->request('PUT', '/api/tasks/' . $id, ['quadrant' => 0]);
    assertSame(200, $toInbox['status']);

    $data = $client->json($client->get('/api/tasks'));
    assertTrue(
        in_array($tag, array_column($data['quadrants'][0] ?? [], 'title'), true),
        'task should be in inbox after updating quadrant to 0'
    );
    assertFalse(
        in_array($tag, array_column($data['quadrants'][2] ?? [], 'title'), true),
        'task should not be in quadrant 2 yet'
    );

    $toTwo = $client->request('PUT', '/api/tasks/' . $id, ['quadrant' => 2]);
    assertSame(200, $toTwo['status']);

    $data = $client->json($client->get('/api/tasks'));
    assertTrue(
        in_array($tag, array_column($data['quadrants'][2] ?? [], 'title'), true),
        'task should be in quadrant 2 after updating back'
    );
    assertFalse(
        in_array($tag, array_column($data['quadrants'][0] ?? [], 'title'), true),
        'task should no longer be in inbox'
    );
});

test('reorder can move a task into inbox and out again', function (TestClient $client): void {
    $tag = 'inbox-reorder-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 1]));
    $id = (int)($created['task']['id'] ?? 0);
    assertTrue($id > 0, 'task should be created');

    $res = $client->post('/api/tasks/reorder', ['items' => [['id' => $id, 'quadrant' => 0, 'position' => 0]]]);
    assertSame(200, $res['status']);

    $data = $client->json($client->get('/api/tasks'));
    assertTrue(
        in_array($tag, array_column($data['quadrants'][0] ?? [], 'title'), true),
        'task should be moved to inbox by reorder'
    );

    $res = $client->post('/api/tasks/reorder', ['items' => [['id' => $id, 'quadrant' => 3, 'position' => 0]]]);
    assertSame(200, $res['status']);

    $data = $client->json($client->get('/api/tasks'));
    assertTrue(
        in_array($tag, array_column($data['quadrants'][3] ?? [], 'title'), true),
        'task should be moved out of inbox by reorder'
    );
});

test('quadrant 5 and -1 are rejected with 422', function (TestClient $client): void {
    $tag = 'inbox-invalid-' . TEST_RUN_ID;
    assertSame(422, $client->post('/api/tasks', ['title' => $tag, 'quadrant' => 5])['status']);
    assertSame(422, $client->post('/api/tasks', ['title' => $tag, 'quadrant' => -1])['status']);
});

test('GET /quick-notes redirects to /tasks#inbox', function (TestClient $client): void {
    $res = $client->get('/quick-notes');
    assertSame(302, $res['status']);
    assertTrue(
        str_contains($res['headers'], 'Location: ') && str_contains($res['headers'], '/tasks#inbox'),
        'redirect location should end with /tasks#inbox, got ' . describeValue($res['headers'])
    );
});

test('creating and updating a task accepts today and tomorrow keywords resolved by the server', function (TestClient $client): void {
    $tag = 'today-keyword-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', [
        'title'    => $tag,
        'quadrant' => 1,
        'due_date' => 'today',
    ]));
    $id = (int)($created['task']['id'] ?? 0);
    assertTrue($id > 0, 'task should be created');
    assertSame(date('Y-m-d'), $created['task']['due_date'], 'today keyword resolves to server date');

    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $updated = $client->json($client->request('PUT', '/api/tasks/' . $id, ['due_date' => 'tomorrow']));
    assertSame($tomorrow, $updated['task']['due_date'], 'tomorrow keyword resolves to server date + 1');
});

test('search results from tasks link to /tasks#task-<id> while notes stay unchanged', function (TestClient $client): void {
    $tag = 'inbox-search-' . TEST_RUN_ID;
    $created = $client->json($client->post('/api/tasks', ['title' => $tag, 'quadrant' => 0]));
    $taskId = (int)($created['task']['id'] ?? 0);

    $note = $client->json($client->post('/api/notes', ['title' => $tag, 'is_encrypted' => false]));
    $noteId = (int)($note['note']['id'] ?? 0);

    $results = $client->json($client->get('/api/search?q=' . urlencode($tag)))['results'] ?? [];

    $taskHits = array_filter($results, static fn(array $r): bool => $r['type'] === 'งาน');
    assertTrue($taskHits !== [], 'task should be found in search');
    foreach ($taskHits as $hit) {
        assertTrue(
            str_ends_with($hit['url'], '/tasks#task-' . $taskId),
            'task search URL should end with /tasks#task-<id>, got ' . describeValue($hit['url'])
        );
    }

    $noteHits = array_filter($results, static fn(array $r): bool => str_contains($r['url'], '/notes'));
    foreach ($noteHits as $hit) {
        assertFalse(
            str_contains($hit['url'], '#task-'),
            'notes search URL must not contain #task-, got ' . describeValue($hit['url'])
        );
    }
});
