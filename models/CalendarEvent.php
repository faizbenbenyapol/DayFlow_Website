<?php
// =====================================================
// models/CalendarEvent.php
// =====================================================

class CalendarEvent
{
    public static function getForMonth(int $userId, int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        return DB::run(
            'SELECT id, title, description, start_datetime, end_datetime, is_all_day, color
             FROM calendar_events
             WHERE user_id = ?
               AND DATE(start_datetime) BETWEEN ? AND ?
             ORDER BY start_datetime ASC',
            [$userId, $start, $end]
        )->fetchAll();
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
            'INSERT INTO calendar_events (user_id, title, description, start_datetime, end_datetime, is_all_day, color)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['title'],
                $data['description'] ?? null,
                $data['start_datetime'],
                $data['end_datetime'] ?: null,
                (int)($data['is_all_day'] ?? 0),
                $data['color'] ?? '#555555',
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        $stmt = DB::run(
            'UPDATE calendar_events
             SET title = ?, description = ?, start_datetime = ?, end_datetime = ?, is_all_day = ?, color = ?
             WHERE id = ? AND user_id = ?',
            [
                $data['title'],
                $data['description'] ?? null,
                $data['start_datetime'],
                $data['end_datetime'] ?: null,
                (int)($data['is_all_day'] ?? 0),
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
