<?php
// =====================================================
// tests/unit/legacy_task_mapper_test.php — models/LegacyTaskMapper.php
// =====================================================

declare(strict_types=1);

require_once ROOT . '/models/LegacyTaskMapper.php';

test('open daily todo maps to open task', function (): void {
    $row = [
        'id'        => 1,
        'user_id'   => 2,
        'todo_date' => '2026-03-10',
        'title'     => 'ซื้อนม',
        'is_done'   => 0,
        'position'  => 3,
    ];

    $mapped = LegacyTaskMapper::fromDailyTodo($row);

    assertSame(2, $mapped['user_id']);
    assertSame('ซื้อนม', $mapped['title']);
    assertSame(null, $mapped['description']);
    assertSame(null, $mapped['quadrant']);
    assertSame('open', $mapped['status']);
    assertSame(null, $mapped['completed_at']);
    assertSame('2026-03-10', $mapped['due_date']);
    assertSame('none', $mapped['repeat_rule']);
    assertSame(3, $mapped['position']);
    assertSame(3, $mapped['day_position']);
    assertSame('2026-03-10 00:00:00', $mapped['created_at']);
    assertSame('2026-03-10 00:00:00', $mapped['updated_at']);
    assertSame('daily_todo', $mapped['legacy_source']);
    assertSame(1, $mapped['legacy_id']);
});

test('done daily todo maps to done task', function (): void {
    $row = [
        'id'        => 5,
        'user_id'   => 2,
        'todo_date' => '2026-03-11',
        'title'     => 'ออกกำลังกาย',
        'is_done'   => 1,
        'position'  => 0,
    ];

    $mapped = LegacyTaskMapper::fromDailyTodo($row);

    assertSame('done', $mapped['status']);
    assertSame('2026-03-11 00:00:00', $mapped['completed_at']);
    assertSame('2026-03-11 00:00:00', $mapped['created_at']);
    assertSame('2026-03-11 00:00:00', $mapped['updated_at']);
    assertSame('ออกกำลังกาย', $mapped['title']);
});

test('whitespace-only daily todo title becomes untitled', function (): void {
    $row = [
        'id'        => 9,
        'user_id'   => 3,
        'todo_date' => '2026-03-12',
        'title'     => '   ',
        'is_done'   => 0,
        'position'  => 1,
    ];

    $mapped = LegacyTaskMapper::fromDailyTodo($row);

    assertSame('(ไม่มีชื่อ)', $mapped['title']);
});

test('short single-line quick item uses content as title with no description', function (): void {
    $row = [
        'id'         => 10,
        'user_id'    => 4,
        'content'    => 'โทรหาแม่',
        'is_done'    => 0,
        'created_at' => '2026-03-10 09:00:00',
        'updated_at' => '2026-03-10 09:00:00',
    ];

    $mapped = LegacyTaskMapper::fromQuickItem($row);

    assertSame('โทรหาแม่', $mapped['title']);
    assertSame(null, $mapped['description']);
    assertSame('open', $mapped['status']);
    assertSame(null, $mapped['completed_at']);
    assertSame(null, $mapped['due_date']);
    assertSame(0, $mapped['position']);
    assertSame(0, $mapped['day_position']);
    assertSame('none', $mapped['repeat_rule']);
    assertSame('quick_item', $mapped['legacy_source']);
    assertSame(10, $mapped['legacy_id']);
    assertSame('2026-03-10 09:00:00', $mapped['created_at']);
    assertSame('2026-03-10 09:00:00', $mapped['updated_at']);
});

test('multi-line quick item takes first line as title and keeps full text in description', function (): void {
    $row = [
        'id'         => 11,
        'user_id'    => 4,
        'content'    => "หัวข้อ\nรายละเอียดบรรทัดสอง\nรายละเอียดบรรทัดสาม",
        'is_done'    => 0,
        'created_at' => '2026-03-10 10:00:00',
        'updated_at' => '2026-03-10 10:00:00',
    ];

    $mapped = LegacyTaskMapper::fromQuickItem($row);

    assertSame('หัวข้อ', $mapped['title']);
    assertSame("หัวข้อ\nรายละเอียดบรรทัดสอง\nรายละเอียดบรรทัดสาม", $mapped['description']);
});

test('very long single-line Thai quick item truncates title and preserves full text', function (): void {
    $long = str_repeat('ก', 400);
    $row = [
        'id'         => 12,
        'user_id'    => 4,
        'content'    => $long,
        'is_done'    => 0,
        'created_at' => '2026-03-10 11:00:00',
        'updated_at' => '2026-03-10 11:00:00',
    ];

    $mapped = LegacyTaskMapper::fromQuickItem($row);

    assertSame(str_repeat('ก', 255), $mapped['title']);
    assertSame($long, $mapped['description']);
    assertSame(400, mb_strlen($mapped['description']));
});

test('done quick item maps to done task', function (): void {
    $row = [
        'id'         => 13,
        'user_id'    => 5,
        'content'    => 'จ่ายบิล',
        'is_done'    => 1,
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ];

    $mapped = LegacyTaskMapper::fromQuickItem($row);

    assertSame('done', $mapped['status']);
    assertSame('2026-03-10 12:00:00', $mapped['completed_at']);
    assertSame('จ่ายบิล', $mapped['title']);
});

test('empty quick item becomes untitled with null description', function (): void {
    $row = [
        'id'         => 15,
        'user_id'    => 5,
        'content'    => '   ',
        'is_done'    => 0,
        'created_at' => '2026-03-10 14:00:00',
        'updated_at' => '2026-03-10 14:00:00',
    ];

    $mapped = LegacyTaskMapper::fromQuickItem($row);

    assertSame('(ไม่มีชื่อ)', $mapped['title']);
    assertSame(null, $mapped['description']);
    assertSame(null, $mapped['completed_at']);
});

test('quick item with leading blank lines skips to the first non-empty line', function (): void {
    $row = [
        'id'         => 14,
        'user_id'    => 5,
        'content'    => "\n\n  บรรทัดแรกจริง  \n\nบรรทัดถัดไป",
        'is_done'    => 0,
        'created_at' => '2026-03-10 13:00:00',
        'updated_at' => '2026-03-10 13:00:00',
    ];

    $mapped = LegacyTaskMapper::fromQuickItem($row);

    assertSame('บรรทัดแรกจริง', $mapped['title']);
    assertSame("บรรทัดแรกจริง  \n\nบรรทัดถัดไป", $mapped['description']);
});
