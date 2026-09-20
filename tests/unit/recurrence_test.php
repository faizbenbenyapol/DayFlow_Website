<?php
// =====================================================
// tests/unit/recurrence_test.php — core/Recurrence.php
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/Recurrence.php';

test('next() advances by one cycle', function (): void {
    assertSame('2026-03-11', Recurrence::next('2026-03-10', 'daily'));
    assertSame('2026-03-17', Recurrence::next('2026-03-10', 'weekly'));
    assertSame('2026-04-10', Recurrence::next('2026-03-10', 'monthly'));
    assertSame('2027-03-10', Recurrence::next('2026-03-10', 'yearly'));
});

test('next() returns null for a rule that does not repeat', function (): void {
    assertSame(null, Recurrence::next('2026-03-10', 'none'));
    assertSame(null, Recurrence::next('2026-03-10', 'fortnightly'), 'an unknown rule is treated as none');
    assertSame(null, Recurrence::next('not-a-date', 'daily'));
});

test('monthly from the 31st lands on the last day of a short month', function (): void {
    // PHP's own "+1 month" would give 2026-03-03 here, which is not what
    // "every month" means to anyone.
    assertSame('2026-02-28', Recurrence::next('2026-01-31', 'monthly'));
    assertSame('2026-04-30', Recurrence::next('2026-03-31', 'monthly'));
});

test('monthly keeps the original day once a longer month comes round', function (): void {
    // Only when stepping from the original date; a rolled-forward task carries
    // its own (clamped) date, which is the documented trade-off.
    assertSame('2024-02-29', Recurrence::next('2024-01-31', 'monthly'), 'leap February');
});

test('yearly from 29 February falls back to the 28th', function (): void {
    assertSame('2025-02-28', Recurrence::next('2024-02-29', 'yearly'));
});

test('occurrencesBetween expands a weekly series inside a month', function (): void {
    $dates = Recurrence::occurrencesBetween('2026-03-02', 'weekly', null, '2026-03-01', '2026-03-31');
    assertSame(['2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30'], $dates);
});

test('occurrencesBetween stops at repeat_until', function (): void {
    $dates = Recurrence::occurrencesBetween('2026-03-02', 'weekly', '2026-03-16', '2026-03-01', '2026-03-31');
    assertSame(['2026-03-02', '2026-03-09', '2026-03-16'], $dates);
});

test('occurrencesBetween skips ahead instead of walking from the start date', function (): void {
    // A daily event begun in 2015 must still resolve a 2026 month; stepping a
    // day at a time would be about 4,000 iterations.
    $dates = Recurrence::occurrencesBetween('2015-01-01', 'daily', null, '2026-03-01', '2026-03-05');
    assertSame(['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'], $dates);
});

test('occurrencesBetween aligns a long-running weekly series to the right weekday', function (): void {
    // 2015-01-01 was a Thursday, so every occurrence must be a Thursday.
    $dates = Recurrence::occurrencesBetween('2015-01-01', 'weekly', null, '2026-03-01', '2026-03-31');
    foreach ($dates as $date) {
        assertSame('Thu', date('D', strtotime($date)), $date . ' is not a Thursday');
    }
    assertSame(4, count($dates), 'March 2026 has four Thursdays from this series');
});

test('occurrencesBetween handles a monthly series started long ago', function (): void {
    $dates = Recurrence::occurrencesBetween('2020-01-15', 'monthly', null, '2026-03-01', '2026-03-31');
    assertSame(['2026-03-15'], $dates);
});

test('occurrencesBetween returns a single date for a one-off in range', function (): void {
    assertSame(['2026-03-10'], Recurrence::occurrencesBetween('2026-03-10', 'none', null, '2026-03-01', '2026-03-31'));
    assertSame([], Recurrence::occurrencesBetween('2026-04-10', 'none', null, '2026-03-01', '2026-03-31'));
});

test('occurrencesBetween returns nothing when the series ended before the range', function (): void {
    assertSame([], Recurrence::occurrencesBetween('2026-01-05', 'weekly', '2026-02-01', '2026-03-01', '2026-03-31'));
});

test('normalise and label cover every rule', function (): void {
    assertSame('none', Recurrence::normalise('nonsense'));
    assertSame('none', Recurrence::normalise(null));
    assertSame('weekly', Recurrence::normalise('weekly'));
    assertSame('ทุกเดือน', Recurrence::label('monthly'));
    assertSame('ไม่ทำซ้ำ', Recurrence::label('bogus'));
});
