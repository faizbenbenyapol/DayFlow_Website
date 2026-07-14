<?php

class Habit
{
    public static function listForUser(int $userId): array
    {
        return DB::run(
            'SELECT h.*, EXISTS(
                SELECT 1 FROM habit_logs l
                WHERE l.habit_id = h.id AND l.user_id = h.user_id AND l.log_date = CURDATE()
             ) AS completed_today,
             (SELECT COUNT(*) FROM habit_logs l2 WHERE l2.habit_id = h.id AND l2.user_id = h.user_id
                AND l2.log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS completed_30d
             FROM habits h WHERE h.user_id = ? AND h.is_archived = 0 ORDER BY h.created_at DESC',
            [$userId]
        )->fetchAll();
    }

    public static function get(int $id, int $userId): ?array
    {
        return DB::run('SELECT * FROM habits WHERE id = ? AND user_id = ?', [$id, $userId])->fetch() ?: null;
    }

    public static function create(int $userId, string $name, string $color, int $targetDays): int
    {
        DB::run('INSERT INTO habits (user_id, name, color, target_days) VALUES (?, ?, ?, ?)',
            [$userId, $name, $color, $targetDays]);
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, string $name, string $color, int $targetDays): bool
    {
        return DB::run('UPDATE habits SET name = ?, color = ?, target_days = ? WHERE id = ? AND user_id = ?',
            [$name, $color, $targetDays, $id, $userId])->rowCount() > 0;
    }

    public static function archive(int $id, int $userId): bool
    {
        return DB::run('UPDATE habits SET is_archived = 1 WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0;
    }

    public static function toggleToday(int $id, int $userId): bool
    {
        if (!self::get($id, $userId)) return false;
        $exists = DB::run('SELECT id FROM habit_logs WHERE habit_id = ? AND user_id = ? AND log_date = CURDATE()', [$id, $userId])->fetchColumn();
        if ($exists) {
            DB::run('DELETE FROM habit_logs WHERE id = ? AND user_id = ?', [(int)$exists, $userId]);
            return false;
        }
        DB::run('INSERT INTO habit_logs (habit_id, user_id, log_date) VALUES (?, ?, CURDATE())', [$id, $userId]);
        return true;
    }
}
