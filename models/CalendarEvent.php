<?php
// =====================================================
// models/CalendarEvent.php
// =====================================================

class CalendarEvent
{
    /**
     * Every event that shows up in the given month, with repeating series
     * expanded into one entry per occurrence.
     *
     * Occurrences are generated rather than stored, so a weekly meeting is a
     * single row that the calendar renders on every matching day. Generated
     * entries carry the parent's id plus an `occurrence_date`, and are flagged
     * with `is_occurrence` so the UI can tell them apart from the original.
     */
    public static function getForMonth(int $userId, int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));
        $dayAfterEnd = date('Y-m-d', strtotime($end . ' +1 day'));

        // Two reads rather than one: non-repeating events are limited to the
        // month by index, while repeating ones have to be considered whatever
        // their start date, because the series may reach into this month.
        $rows = DB::run(
            'SELECT id, title, description, start_datetime, end_datetime, is_all_day, color,
                    repeat_rule, repeat_until
             FROM calendar_events
             WHERE user_id = ?
               AND repeat_rule = "none"
               AND start_datetime >= ? AND start_datetime < ?
             ORDER BY start_datetime ASC',
            [$userId, $start, $dayAfterEnd]
        )->fetchAll();

        $repeating = DB::run(
            'SELECT id, title, description, start_datetime, end_datetime, is_all_day, color,
                    repeat_rule, repeat_until
             FROM calendar_events
             WHERE user_id = ?
               AND repeat_rule <> "none"
               AND start_datetime < ?
               AND (repeat_until IS NULL OR repeat_until >= ?)
             ORDER BY start_datetime ASC',
            [$userId, $dayAfterEnd, $start]
        )->fetchAll();

        foreach ($repeating as $event) {
            $dates = Recurrence::occurrencesBetween(
                (string)$event['start_datetime'],
                (string)$event['repeat_rule'],
                $event['repeat_until'] ?? null,
                $start,
                $end
            );

            foreach ($dates as $date) {
                $rows[] = self::shiftToDate($event, $date);
            }
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['start_datetime'], $b['start_datetime']));
        return $rows;
    }

    /**
     * Copies an event onto another day, keeping its time of day and duration.
     */
    private static function shiftToDate(array $event, string $date): array
    {
        $originalStart = new DateTimeImmutable((string)$event['start_datetime']);
        $newStart      = new DateTimeImmutable($date . ' ' . $originalStart->format('H:i:s'));

        // The stored start is kept alongside the shifted one: editing a series
        // must save against the original date, not whichever occurrence the
        // user happened to click.
        $event['series_start']    = $originalStart->format('Y-m-d H:i:s');
        $event['series_end']      = $event['end_datetime'] ?: null;
        $event['occurrence_date'] = $date;
        $event['is_occurrence']   = $date !== $originalStart->format('Y-m-d') ? 1 : 0;
        $event['start_datetime']  = $newStart->format('Y-m-d H:i:s');

        if (!empty($event['end_datetime'])) {
            $originalEnd = new DateTimeImmutable((string)$event['end_datetime']);
            $event['end_datetime'] = $newStart
                ->add($originalStart->diff($originalEnd))
                ->format('Y-m-d H:i:s');
        }

        return $event;
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM calendar_events WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    public static function create(int $userId, array $data): int
    {
        DB::run(
            'INSERT INTO calendar_events (user_id, title, description, start_datetime, end_datetime,
                                          is_all_day, repeat_rule, repeat_until, color)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['title'],
                $data['description'] ?? null,
                $data['start_datetime'],
                $data['end_datetime'] ?: null,
                (int)($data['is_all_day'] ?? 0),
                Recurrence::normalise($data['repeat_rule'] ?? 'none'),
                $data['repeat_until'] ?: null,
                $data['color'] ?? '#555555',
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        $stmt = DB::run(
            'UPDATE calendar_events
             SET title = ?, description = ?, start_datetime = ?, end_datetime = ?, is_all_day = ?,
                 repeat_rule = ?, repeat_until = ?, color = ?
             WHERE id = ? AND user_id = ?',
            [
                $data['title'],
                $data['description'] ?? null,
                $data['start_datetime'],
                $data['end_datetime'] ?: null,
                (int)($data['is_all_day'] ?? 0),
                Recurrence::normalise($data['repeat_rule'] ?? 'none'),
                $data['repeat_until'] ?: null,
                $data['color'] ?? '#555555',
                $id,
                $userId,
            ]
        );
        return $stmt->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM calendar_events WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }
}
