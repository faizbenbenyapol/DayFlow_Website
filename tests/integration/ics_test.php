<?php
// =====================================================
// tests/integration/ics_test.php — calendar export and import over HTTP
// =====================================================

declare(strict_types=1);

test('export returns a calendar file containing the user\'s events', function (TestClient $client): void {
    $title = 'กิจกรรมสำหรับส่งออก ' . TEST_RUN_ID;
    $start = (new DateTimeImmutable('first day of next month'))->setTime(10, 0);

    $client->post('/api/planner/events', [
        'title'          => $title,
        'description'    => 'รายละเอียดการส่งออก',
        'start_datetime' => $start->format('Y-m-d H:i:s'),
        'end_datetime'   => $start->modify('+1 hour')->format('Y-m-d H:i:s'),
        'is_all_day'     => 0,
    ]);

    $response = $client->get('/api/planner/events/export.ics');

    assertSame(200, $response['status']);
    assertStringContains('text/calendar', $response['headers']);
    assertStringContains('attachment; filename="dayflow-calendar.ics"', $response['headers']);
    assertStringContains('BEGIN:VCALENDAR', $response['body']);
    assertStringContains('DTSTART:' . $start->format('Ymd\THis'), $response['body']);
});

test('export sends a repeating event as one VEVENT with an RRULE', function (TestClient $client): void {
    $title = 'ประชุมซ้ำสำหรับส่งออก ' . TEST_RUN_ID;
    $start = (new DateTimeImmutable('first day of next month'))->setTime(11, 0);

    $client->post('/api/planner/events', [
        'title'          => $title,
        'start_datetime' => $start->format('Y-m-d H:i:s'),
        'is_all_day'     => 0,
        'repeat_rule'    => 'weekly',
    ]);

    $body = $client->get('/api/planner/events/export.ics')['body'];

    // One VEVENT, not one per occurrence — the RRULE carries the repetition.
    $events = Ics::parse($body);
    $mine   = array_values(array_filter($events, static fn(array $e): bool => $e['title'] === $title));

    assertSame(1, count($mine), 'a repeating event must export as a single entry');
    assertSame('weekly', $mine[0]['repeat_rule']);
});

test('export requires a session', function (TestClient $_client): void {
    $anonymous = new TestClient(TEST_BASE_URL);
    assertContains($anonymous->get('/api/planner/events/export.ics')['status'], [401, 302]);
});

test('importing a calendar creates its events', function (TestClient $client): void {
    $title = 'กิจกรรมนำเข้า ' . TEST_RUN_ID;
    $start = (new DateTimeImmutable('first day of next month'))->setTime(15, 0);

    $ics = Ics::export([[
        'id'             => 1,
        'title'          => $title,
        'description'    => 'มาจากไฟล์',
        'start_datetime' => $start->format('Y-m-d H:i:s'),
        'end_datetime'   => $start->modify('+45 minutes')->format('Y-m-d H:i:s'),
        'is_all_day'     => 0,
        'repeat_rule'    => 'none',
    ]]);

    $result = $client->json($client->upload('/api/planner/events/import', 'file', 'import.ics', $ics));

    assertSame(1, $result['imported'] ?? 0, 'import failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

    $month = $client->json($client->get(
        '/api/planner/events?year=' . $start->format('Y') . '&month=' . (int)$start->format('n')
    ));
    $mine = array_values(array_filter($month['events'] ?? [], static fn(array $e): bool => $e['title'] === $title));

    assertSame(1, count($mine));
    assertSame($start->format('Y-m-d H:i:s'), $mine[0]['start_datetime']);
});

test('importing the same calendar twice does not duplicate events', function (TestClient $client): void {
    $title = 'กิจกรรมนำเข้าซ้ำ ' . TEST_RUN_ID;
    $start = (new DateTimeImmutable('first day of next month'))->setTime(16, 0);

    $ics = Ics::export([[
        'id' => 1, 'title' => $title, 'start_datetime' => $start->format('Y-m-d H:i:s'),
        'end_datetime' => null, 'is_all_day' => 0, 'repeat_rule' => 'none',
    ]]);

    $first  = $client->json($client->upload('/api/planner/events/import', 'file', 'import.ics', $ics));
    $second = $client->json($client->upload('/api/planner/events/import', 'file', 'import.ics', $ics));

    assertSame(1, $first['imported'] ?? 0);
    assertSame(0, $second['imported'] ?? -1, 'the second import must add nothing');
    assertSame(1, $second['skipped'] ?? 0);
});

test('a repeating event survives an export/import round trip', function (TestClient $client): void {
    $title = 'ทำซ้ำไปกลับ ' . TEST_RUN_ID;
    $start = (new DateTimeImmutable('first day of next month'))->setTime(17, 0);

    $ics = Ics::export([[
        'id' => 1, 'title' => $title, 'start_datetime' => $start->format('Y-m-d H:i:s'),
        'end_datetime' => null, 'is_all_day' => 0,
        'repeat_rule' => 'monthly', 'repeat_until' => $start->modify('+6 months')->format('Y-m-d'),
    ]]);

    $client->upload('/api/planner/events/import', 'file', 'import.ics', $ics);

    $month = $client->json($client->get(
        '/api/planner/events?year=' . $start->format('Y') . '&month=' . (int)$start->format('n')
    ));
    $mine = array_values(array_filter($month['events'] ?? [], static fn(array $e): bool => $e['title'] === $title));

    assertSame(1, count($mine), 'monthly means one occurrence in this month');
    assertSame('monthly', $mine[0]['repeat_rule']);
    assertSame($start->modify('+6 months')->format('Y-m-d'), $mine[0]['repeat_until']);
});

test('a file with no events is rejected', function (TestClient $client): void {
    $response = $client->upload('/api/planner/events/import', 'file', 'junk.ics', 'this is not a calendar');
    assertSame(422, $response['status']);
});

test('import requires a file', function (TestClient $client): void {
    $response = $client->post('/api/planner/events/import', []);
    assertSame(422, $response['status']);
});
