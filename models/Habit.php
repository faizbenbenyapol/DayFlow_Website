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

    /**
     * Consecutive days each habit has been done, as habit id => days.
     *
     * A habit not yet done today still counts yesterday's run: the day is not
     * over, so the streak is not broken until it ends without a tick.
     */
    public static function streaks(int $userId): array
    {
        $today = (string)DB::run('SELECT CURDATE()')->fetchColumn();
        $rows = DB::run(
            'SELECT habit_id, log_date FROM habit_logs
             WHERE user_id = ? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 400 DAY)',
            [$userId]
        )->fetchAll();

        $days = [];
        foreach ($rows as $row) {
            $days[(int)$row['habit_id']][(string)$row['log_date']] = true;
        }

        $streaks = [];
        foreach ($days as $habitId => $done) {
            $cursor = new DateTimeImmutable($today);
            if (!isset($done[$cursor->format('Y-m-d')])) $cursor = $cursor->modify('-1 day');

            $count = 0;
            while (isset($done[$cursor->format('Y-m-d')])) {
                $count++;
                $cursor = $cursor->modify('-1 day');
            }
            $streaks[$habitId] = $count;
        }
        return $streaks;
    }

    /** The database's own idea of today, which is what CURDATE() means everywhere else here. */
    public static function today(): string
    {
        return (string)DB::run('SELECT CURDATE()')->fetchColumn();
    }

    /**
     * Which days each habit was done between two dates, inclusive.
     *
     * @return array<int, list<string>> habit id => 'Y-m-d' dates, oldest first
     */
    public static function logsBetween(int $userId, string $from, string $to): array
    {
        $rows = DB::run(
            'SELECT habit_id, log_date FROM habit_logs
             WHERE user_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date ASC',
            [$userId, $from, $to]
        )->fetchAll();

        $logs = [];
        foreach ($rows as $row) {
            $logs[(int)$row['habit_id']][] = (string)$row['log_date'];
        }
        return $logs;
    }

    /** Ticks the habit for a day, or takes the tick away. Returns whether it is now ticked. */
    public static function toggleOn(int $id, int $userId, string $date): bool
    {
        if (!self::get($id, $userId)) return false;
        $exists = DB::run('SELECT id FROM habit_logs WHERE habit_id = ? AND user_id = ? AND log_date = ?', [$id, $userId, $date])->fetchColumn();
        if ($exists) {
            DB::run('DELETE FROM habit_logs WHERE id = ? AND user_id = ?', [(int)$exists, $userId]);
            return false;
        }
        DB::run('INSERT INTO habit_logs (habit_id, user_id, log_date) VALUES (?, ?, ?)', [$id, $userId, $date]);
        return true;
    }

    public static function toggleToday(int $id, int $userId): bool
    {
        return self::toggleOn($id, $userId, self::today());
    }
}
