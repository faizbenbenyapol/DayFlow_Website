<?php
// =====================================================
// tests/integration/recurrence_test.php
//
// Tasks roll forward when completed; calendar events expand on read. Both go
// through the HTTP API here, the way the UI drives them.
// =====================================================

declare(strict_types=1);

test('a repeating task schedules its next occurrence when completed', function (TestClient $client): void {
    $due = date('Y-m-d', strtotime('+2 days'));

    $created = $client->json($client->post('/api/tasks', [
        'title'       => 'รดน้ำต้นไม้',
        'quadrant'    => 2,
        'due_date'    => $due,
        'repeat_rule' => 'weekly',
    ]))['task'];

    assertSame('weekly', $created['repeat_rule']);

    $done = $client->json($client->request('PUT', '/api/tasks/' . (int)$created['id'], ['status' => 'done']));

    assertArrayHasKey('next_task', $done, 'completing a repeating task must create the next one');
    assertSame(date('Y-m-d', strtotime($due . ' +1 week')), $done['next_task']['due_date']);
    assertSame('open', $done['next_task']['status']);
    assertSame('weekly', $done['next_task']['repeat_rule'], 'the series must carry on');
    assertSame('done', $done['task']['status'], 'the completed occurrence stays as history');
});

test('a one-off task schedules nothing when completed', function (TestClient $client): void {
    $created = $client->json($client->post('/api/tasks', [
        'title'    => 'งานครั้งเดียว',
        'quadrant' => 1,
        'due_date' => date('Y-m-d', strtotime('+1 day')),
    ]))['task'];

    $done = $client->json($client->request('PUT', '/api/tasks/' . (int)$created['id'], ['status' => 'done']));
    assertArrayNotHasKey('next_task', $done);
});

test('a repeating series stops at repeat_until', function (TestClient $client): void {
    $due = date('Y-m-d', strtotime('+1 day'));

    $created = $client->json($client->post('/api/tasks', [
        'title'        => 'งานที่มีวันสิ้นสุด',
        'quadrant'     => 2,
        'due_date'     => $due,
        'repeat_rule'  => 'weekly',
        'repeat_until' => $due, // the first occurrence is also the last
    ]))['task'];

    $done = $client->json($client->request('PUT', '/api/tasks/' . (int)$created['id'], ['status' => 'done']));
    assertArrayNotHasKey('next_task', $done, 'nothing should follow the end of the series');
});

test('a repeating task must have a due date', function (TestClient $client): void {
    $response = $client->post('/api/tasks', [
        'title'       => 'ทำซ้ำแต่ไม่มีวัน',
        'quadrant'    => 1,
        'repeat_rule' => 'daily',
    ]);
    assertSame(422, $response['status']);
});

test('an unknown repeat rule is rejected', function (TestClient $client): void {
    $response = $client->post('/api/tasks', [
        'title'       => 'กฎมั่ว',
        'quadrant'    => 1,
        'due_date'    => date('Y-m-d'),
        'repeat_rule' => 'every-blue-moon',
    ]);
    assertSame(422, $response['status']);
});

test('a weekly calendar event appears on every matching day of the month', function (TestClient $client): void {
    $title = 'ประชุมทีมประจำสัปดาห์ ' . TEST_RUN_ID;

    // Anchor on the first Monday of next month so the assertion does not
    // depend on when the suite happens to run.
    // modify('monday ...') resets the clock, so the time is re-applied after.
    $firstOfNext = new DateTimeImmutable('first day of next month');
    $monday      = ($firstOfNext->format('D') === 'Mon' ? $firstOfNext : $firstOfNext->modify('next monday'))
        ->setTime(9, 0);

    $created = $client->post('/api/planner/events', [
        'title'          => $title,
        'start_datetime' => $monday->format('Y-m-d H:i:s'),
        'end_datetime'   => $monday->modify('+1 hour')->format('Y-m-d H:i:s'),
        'is_all_day'     => 0,
        'repeat_rule'    => 'weekly',
    ]);
    assertSame(201, $created['status'], 'event creation failed: ' . $created['body']);

    $month = $client->json($client->get(
        '/api/planner/events?year=' . $monday->format('Y') . '&month=' . (int)$monday->format('n')
    ));

    $mine = array_values(array_filter(
        $month['events'] ?? [],
        static fn(array $e): bool => $e['title'] === $title
    ));

    assertTrue(count($mine) >= 4, 'a weekly series should yield at least four days in a month, got ' . count($mine));

    foreach ($mine as $occurrence) {
        assertSame('Mon', date('D', strtotime($occurrence['start_datetime'])), 'every occurrence must be a Monday');
        assertSame('09:00:00', date('H:i:s', strtotime($occurrence['start_datetime'])), 'the time of day must be kept');
    }
});

test('calendar occurrences keep the parent id and duration', function (TestClient $client): void {
    $title = 'ทบทวนรายเดือน ' . TEST_RUN_ID;
    $start = (new DateTimeImmutable('first day of next month'))->setTime(14, 30);

    $created = $client->json($client->post('/api/planner/events', [
        'title'          => $title,
        'start_datetime' => $start->format('Y-m-d H:i:s'),
        'end_datetime'   => $start->modify('+90 minutes')->format('Y-m-d H:i:s'),
        'is_all_day'     => 0,
        'repeat_rule'    => 'daily',
    ]));

    $month = $client->json($client->get(
        '/api/planner/events?year=' . $start->format('Y') . '&month=' . (int)$start->format('n')
    ));

    $mine = array_values(array_filter(
        $month['events'] ?? [],
        static fn(array $e): bool => $e['title'] === $title
    ));

    assertTrue(count($mine) > 1, 'a daily series should produce many days');
    foreach ($mine as $occurrence) {
        assertSame((int)$created['event']['id'], (int)$occurrence['id'], 'occurrences point back at the stored event');
        $minutes = (strtotime($occurrence['end_datetime']) - strtotime($occurrence['start_datetime'])) / 60;
        assertSame(90.0, (float)$minutes, 'each occurrence keeps the original duration');
    }
});

test('a non-repeating event still appears exactly once', function (TestClient $client): void {
    $title = 'กิจกรรมครั้งเดียว ' . TEST_RUN_ID;
    $start = (new DateTimeImmutable('first day of next month'))->setTime(8, 0);

    $client->post('/api/planner/events', [
        'title'          => $title,
        'start_datetime' => $start->format('Y-m-d H:i:s'),
        'is_all_day'     => 0,
    ]);

    $month = $client->json($client->get(
        '/api/planner/events?year=' . $start->format('Y') . '&month=' . (int)$start->format('n')
    ));

    $mine = array_filter($month['events'] ?? [], static fn(array $e): bool => $e['title'] === $title);
    assertSame(1, count($mine));
});
