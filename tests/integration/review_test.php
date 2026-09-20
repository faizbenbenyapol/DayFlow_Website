<?php
// =====================================================
// tests/integration/review_test.php — cross-module summary
// =====================================================

declare(strict_types=1);

test('the weekly review covers Monday to Sunday of the current week', function (TestClient $client): void {
    $data = $client->json($client->get('/api/review?period=week'));

    $start = new DateTimeImmutable($data['period']['start']);
    $end   = new DateTimeImmutable($data['period']['end']);

    assertSame('week', $data['period']['key']);
    assertSame('Mon', $start->format('D'), 'the week must start on Monday');
    assertSame(7, (int)$start->diff($end)->days, 'the range is a whole week');
    assertTrue($start <= new DateTimeImmutable('today'), 'the current week must include today');
});

test('the monthly review covers the calendar month', function (TestClient $client): void {
    $data = $client->json($client->get('/api/review?period=month'));

    assertSame('month', $data['period']['key']);
    assertSame(date('Y-m-01'), $data['period']['start']);
    assertSame(
        (new DateTimeImmutable('first day of next month'))->format('Y-m-d'),
        $data['period']['end'],
        'the end bound is exclusive: the first of next month'
    );
});

test('an unknown period falls back to the week', function (TestClient $client): void {
    assertSame('week', $client->json($client->get('/api/review?period=decade'))['period']['key']);
    assertSame('week', $client->json($client->get('/api/review?period=../../etc/passwd'))['period']['key']);
});

test('every section is present and shaped for the view', function (TestClient $client): void {
    $data = $client->json($client->get('/api/review?period=week'));

    foreach (['tasks', 'focus', 'habits', 'exercise', 'finance', 'notes'] as $section) {
        assertArrayHasKey($section, $data);
    }

    foreach (['created', 'completed', 'open', 'overdue'] as $key) {
        assertArrayHasKey($key, $data['tasks']);
    }
    foreach (['sessions', 'minutes', 'by_day'] as $key) {
        assertArrayHasKey($key, $data['focus']);
    }
    foreach (['income', 'expense', 'balance', 'top_categories'] as $key) {
        assertArrayHasKey($key, $data['finance']);
    }
    foreach (['items', 'done_days', 'target_days'] as $key) {
        assertArrayHasKey($key, $data['habits']);
    }

    assertSame([], $data['meta']['warnings'], 'no section should have failed');
});

test('a task completed this week is counted', function (TestClient $client): void {
    $before = $client->json($client->get('/api/review?period=week'))['tasks']['completed'];

    $task = $client->json($client->post('/api/tasks', [
        'title'    => 'งานสำหรับสรุปผล ' . TEST_RUN_ID,
        'quadrant' => 1,
        'due_date' => date('Y-m-d'),
    ]))['task'];
    $client->request('PUT', '/api/tasks/' . (int)$task['id'], ['status' => 'done']);

    $after = $client->json($client->get('/api/review?period=week'))['tasks'];

    assertSame($before + 1, $after['completed'], 'completing a task must show up in the review');
    assertTrue($after['created'] >= 1);
});

test('finance totals add up and the balance is derived', function (TestClient $client): void {
    $finance = $client->json($client->get('/api/review?period=month'))['finance'];

    assertSame(
        round((float)$finance['income'] - (float)$finance['expense'], 2),
        round((float)$finance['balance'], 2),
        'balance must equal income minus expense'
    );
    assertTrue(count($finance['top_categories']) <= 5, 'at most five categories are listed');
});

test('the review requires a session', function (TestClient $_client): void {
    $anonymous = new TestClient(TEST_BASE_URL);
    assertContains($anonymous->get('/api/review?period=week')['status'], [401, 302]);
});

test('the review page renders for a signed-in user', function (TestClient $client): void {
    $page = $client->get('/review');

    assertSame(200, $page['status']);
    assertStringContains('id="reviewStrip"', $page['body']);
    assertStringContains('review.js', $page['body'], 'the page must load its own script');
    assertStringContains('modules/review.css', $page['body']);
});
