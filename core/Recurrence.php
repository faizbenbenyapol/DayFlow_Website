<?php
// =====================================================
// core/Recurrence.php — repeat rules shared by tasks and calendar events
//
// Deliberately a small fixed set rather than full RRULE: these are the cycles
// the UI offers, and keeping the logic in one place means a task and an event
// advance a date the same way.
// =====================================================

final class Recurrence
{
    public const RULES = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

    private const INTERVALS = [
        'daily'   => '+1 day',
        'weekly'  => '+1 week',
        'monthly' => '+1 month',
        'yearly'  => '+1 year',
    ];

    public static function isValid(string $rule): bool
    {
        return in_array($rule, self::RULES, true);
    }

    public static function normalise(mixed $rule): string
    {
        $rule = is_string($rule) ? $rule : 'none';
        return self::isValid($rule) ? $rule : 'none';
    }

    /**
     * The occurrence after $date, or null when the rule does not repeat.
     *
     * Monthly needs care: PHP's "+1 month" turns 31 January into 3 March. A
     * monthly task due on the 31st should land on the last day of the next
     * month instead, which is what a person means by "every month".
     */
    public static function next(string $date, string $rule): ?string
    {
        $rule = self::normalise($rule);
        if ($rule === 'none') return null;

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
        if ($start === false) return null;

        if ($rule === 'monthly' || $rule === 'yearly') {
            $day      = (int)$start->format('j');
            $anchor   = $start->modify('first day of this month')->modify(self::INTERVALS[$rule]);
            $lastDay  = (int)$anchor->format('t');
            return $anchor->setDate(
                (int)$anchor->format('Y'),
                (int)$anchor->format('n'),
                min($day, $lastDay)
            )->format('Y-m-d');
        }

        return $start->modify(self::INTERVALS[$rule])->format('Y-m-d');
    }

    /**
     * Every occurrence of $startDate that falls inside [$rangeStart, $rangeEnd],
     * as Y-m-d strings. A non-repeating date yields itself when it is in range.
     *
     * $repeatUntil, when set, ends the series on that day.
     */
    public static function occurrencesBetween(
        string $startDate,
        string $rule,
        ?string $repeatUntil,
        string $rangeStart,
        string $rangeEnd
    ): array {
        $rule    = self::normalise($rule);
        $current = substr($startDate, 0, 10);

        if ($rule === 'none') {
            return ($current >= $rangeStart && $current <= $rangeEnd) ? [$current] : [];
        }

        $limit = $repeatUntil !== null && $repeatUntil !== ''
            ? min($rangeEnd, substr($repeatUntil, 0, 10))
            : $rangeEnd;

        // Jump straight to the first occurrence in range. Stepping there one
        // cycle at a time would take thousands of iterations for a daily event
        // that started years ago.
        $current = self::firstOnOrAfter($current, $rule, $rangeStart);
        if ($current === null) return [];

        $occurrences = [];
        while ($current <= $limit) {
            $occurrences[] = $current;

            $next = self::next($current, $rule);
            if ($next === null || $next <= $current) break;
            $current = $next;
        }

        return $occurrences;
    }

    /**
     * The first occurrence of a series on or after $boundary, computed rather
     * than iterated.
     */
    private static function firstOnOrAfter(string $startDate, string $rule, string $boundary): ?string
    {
        if ($startDate >= $boundary) return $startDate;

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $edge  = DateTimeImmutable::createFromFormat('!Y-m-d', $boundary);
        if ($start === false || $edge === false) return null;

        if ($rule === 'daily' || $rule === 'weekly') {
            $step = $rule === 'daily' ? 1 : 7;
            $days = (int)$start->diff($edge)->days;
            $cycles = intdiv($days, $step);
            $candidate = $start->modify('+' . ($cycles * $step) . ' days');
            if ($candidate->format('Y-m-d') < $boundary) {
                $candidate = $candidate->modify('+' . $step . ' days');
            }
            return $candidate->format('Y-m-d');
        }

        // Monthly and yearly keep the original day-of-month, clamped to the
        // length of the target month, so "the 31st" means "the last day".
        $step = $rule === 'monthly' ? 1 : 12;
        $months = ((int)$edge->format('Y') - (int)$start->format('Y')) * 12
                + ((int)$edge->format('n') - (int)$start->format('n'));
        $cycles = intdiv(max(0, $months), $step);

        $day = (int)$start->format('j');
        for ($i = 0; $i < 3; $i++) {
            $anchor  = $start->modify('first day of this month')->modify('+' . (($cycles + $i) * $step) . ' months');
            $clamped = $anchor->setDate(
                (int)$anchor->format('Y'),
                (int)$anchor->format('n'),
                min($day, (int)$anchor->format('t'))
            )->format('Y-m-d');
            if ($clamped >= $boundary) return $clamped;
        }
        return null;
    }

    /** Thai label for a rule, for notifications and list rows. */
    public static function label(string $rule): string
    {
        return [
            'none'    => 'ไม่ทำซ้ำ',
            'daily'   => 'ทุกวัน',
            'weekly'  => 'ทุกสัปดาห์',
            'monthly' => 'ทุกเดือน',
            'yearly'  => 'ทุกปี',
        ][self::normalise($rule)];
    }
}
