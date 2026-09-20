<?php
// =====================================================
// tests/unit/ics_test.php — core/Ics.php
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/Ics.php';

test('export produces a well-formed VCALENDAR', function (): void {
    $ics = Ics::export([[
        'id' => 7, 'title' => 'ประชุมทีม', 'description' => 'วาระประจำเดือน',
        'start_datetime' => '2026-03-10 09:00:00', 'end_datetime' => '2026-03-10 10:30:00',
        'is_all_day' => 0, 'repeat_rule' => 'none', 'repeat_until' => null,
    ]]);

    assertStringContains('BEGIN:VCALENDAR', $ics);
    assertStringContains('VERSION:2.0', $ics);
    assertStringContains('BEGIN:VEVENT', $ics);
    assertStringContains('DTSTART:20260310T090000', $ics);
    assertStringContains('DTEND:20260310T103000', $ics);
    assertStringContains('END:VCALENDAR', $ics);
    assertStringContains("\r\n", $ics, 'the spec requires CRLF line endings');
});

test('export writes an RRULE for a repeating event', function (): void {
    $ics = Ics::export([[
        'id' => 8, 'title' => 'ยืดเส้น', 'start_datetime' => '2026-03-10 07:00:00',
        'end_datetime' => null, 'is_all_day' => 0,
        'repeat_rule' => 'weekly', 'repeat_until' => '2026-06-30',
    ]]);

    assertStringContains('RRULE:FREQ=WEEKLY;UNTIL=20260630T235959', $ics);
});

test('export writes an all-day event with an exclusive end date', function (): void {
    $ics = Ics::export([[
        'id' => 9, 'title' => 'วันหยุด', 'start_datetime' => '2026-04-13 00:00:00',
        'end_datetime' => null, 'is_all_day' => 1, 'repeat_rule' => 'none',
    ]]);

    assertStringContains('DTSTART;VALUE=DATE:20260413', $ics);
    // A one-day event ends on the 14th: DTEND is exclusive for DATE values.
    assertStringContains('DTEND;VALUE=DATE:20260414', $ics);
});

test('export escapes the characters the format reserves', function (): void {
    $ics = Ics::export([[
        'id' => 10, 'title' => 'A; B, C\\D', 'description' => "line one\nline two",
        'start_datetime' => '2026-03-10 09:00:00', 'end_datetime' => null,
        'is_all_day' => 0, 'repeat_rule' => 'none',
    ]]);

    assertStringContains('SUMMARY:A\\; B\\, C\\\\D', $ics);
    assertStringContains('DESCRIPTION:line one\\nline two', $ics);
});

test('parse reads a simple event back', function (): void {
    $events = Ics::parse(
        "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\n" .
        "SUMMARY:ประชุมทีม\r\nDESCRIPTION:วาระ\r\n" .
        "DTSTART:20260310T090000\r\nDTEND:20260310T103000\r\n" .
        "END:VEVENT\r\nEND:VCALENDAR\r\n"
    );

    assertSame(1, count($events));
    assertSame('ประชุมทีม', $events[0]['title']);
    assertSame('2026-03-10 09:00:00', $events[0]['start_datetime']);
    assertSame('2026-03-10 10:30:00', $events[0]['end_datetime']);
    assertSame(0, $events[0]['is_all_day']);
});

test('parse understands an all-day event and its exclusive end', function (): void {
    $events = Ics::parse(
        "BEGIN:VEVENT\r\nSUMMARY:วันหยุด\r\n" .
        "DTSTART;VALUE=DATE:20260413\r\nDTEND;VALUE=DATE:20260414\r\nEND:VEVENT\r\n"
    );

    assertSame(1, $events[0]['is_all_day']);
    assertSame('2026-04-13 00:00:00', $events[0]['start_datetime']);
    assertSame('2026-04-13 00:00:00', $events[0]['end_datetime'], 'the exclusive end maps back to the last real day');
});

test('parse maps RRULE frequencies onto the app rules', function (): void {
    $events = Ics::parse(
        "BEGIN:VEVENT\r\nSUMMARY:ยืดเส้น\r\nDTSTART:20260310T070000\r\n" .
        "RRULE:FREQ=WEEKLY;UNTIL=20260630T235959\r\nEND:VEVENT\r\n"
    );

    assertSame('weekly', $events[0]['repeat_rule']);
    assertSame('2026-06-30', $events[0]['repeat_until']);
});

test('parse ignores an interval it cannot represent', function (): void {
    // "every other week" has no equivalent, and importing it as plain weekly
    // would put the event on twice as many days as the source calendar.
    $events = Ics::parse(
        "BEGIN:VEVENT\r\nSUMMARY:สลับสัปดาห์\r\nDTSTART:20260310T070000\r\n" .
        "RRULE:FREQ=WEEKLY;INTERVAL=2\r\nEND:VEVENT\r\n"
    );

    assertSame('none', $events[0]['repeat_rule']);
});

test('parse converts a UTC timestamp into local time', function (): void {
    $events = Ics::parse(
        "BEGIN:VEVENT\r\nSUMMARY:ประชุมข้ามโซน\r\nDTSTART:20260310T020000Z\r\nEND:VEVENT\r\n"
    );

    // The app runs on Asia/Bangkok (UTC+7), so 02:00Z is 09:00 local.
    assertSame('2026-03-10 09:00:00', $events[0]['start_datetime']);
});

test('parse rejoins folded lines', function (): void {
    $events = Ics::parse(
        "BEGIN:VEVENT\r\nSUMMARY:หัวข้อที่ยาว\r\n มากจนต้องตัดบรรทัด\r\n" .
        "DTSTART:20260310T090000\r\nEND:VEVENT\r\n"
    );

    assertSame('หัวข้อที่ยาวมากจนต้องตัดบรรทัด', $events[0]['title']);
});

test('parse unescapes reserved characters', function (): void {
    $events = Ics::parse(
        "BEGIN:VEVENT\r\nSUMMARY:A\\; B\\, C\\\\D\r\nDESCRIPTION:line one\\nline two\r\n" .
        "DTSTART:20260310T090000\r\nEND:VEVENT\r\n"
    );

    assertSame('A; B, C\\D', $events[0]['title']);
    assertSame("line one\nline two", $events[0]['description']);
});

test('parse skips entries with no start or no title', function (): void {
    $events = Ics::parse(
        "BEGIN:VEVENT\r\nSUMMARY:ไม่มีวันเริ่ม\r\nEND:VEVENT\r\n" .
        "BEGIN:VEVENT\r\nDTSTART:20260310T090000\r\nEND:VEVENT\r\n" .
        "BEGIN:VEVENT\r\nSUMMARY:ครบถ้วน\r\nDTSTART:20260311T090000\r\nEND:VEVENT\r\n"
    );

    assertSame(1, count($events));
    assertSame('ครบถ้วน', $events[0]['title']);
});

test('parse tolerates junk and other components', function (): void {
    assertSame([], Ics::parse('this is not a calendar at all'));
    assertSame([], Ics::parse(''));

    $events = Ics::parse(
        "BEGIN:VCALENDAR\r\nBEGIN:VTIMEZONE\r\nTZID:Asia/Bangkok\r\nEND:VTIMEZONE\r\n" .
        "BEGIN:VEVENT\r\nSUMMARY:งานจริง\r\nDTSTART:20260310T090000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    );
    assertSame(1, count($events));
});

test('export then parse returns the same event', function (): void {
    $original = [
        'id' => 11, 'title' => 'ทบทวนประจำเดือน; รอบที่ 2',
        'description' => "หัวข้อ\nรายละเอียด",
        'start_datetime' => '2026-03-10 14:30:00', 'end_datetime' => '2026-03-10 16:00:00',
        'is_all_day' => 0, 'repeat_rule' => 'monthly', 'repeat_until' => '2026-12-31',
    ];

    $round = Ics::parse(Ics::export([$original]))[0];

    assertSame($original['title'], $round['title']);
    assertSame($original['description'], $round['description']);
    assertSame($original['start_datetime'], $round['start_datetime']);
    assertSame($original['end_datetime'], $round['end_datetime']);
    assertSame('monthly', $round['repeat_rule']);
    assertSame('2026-12-31', $round['repeat_until']);
});

test('a long summary survives folding and unfolding', function (): void {
    $long = str_repeat('รายละเอียดกิจกรรมที่ยาวมาก ', 12);

    $round = Ics::parse(Ics::export([[
        'id' => 12, 'title' => $long, 'start_datetime' => '2026-03-10 09:00:00',
        'end_datetime' => null, 'is_all_day' => 0, 'repeat_rule' => 'none',
    ]]))[0];

    assertSame(mb_substr($long, 0, 255), $round['title']);
});
