<?php
// =====================================================
// models/Workout.php
// =====================================================

class Workout
{
    /** How many workouts match, so the page can say what it is showing. */
    public static function countForUser(int $userId, string $month = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM workouts WHERE user_id = ?';
        $params = [$userId];
        if ($month) {
            [$monthStart, $monthEnd] = monthBounds($month);
            $sql .= ' AND workout_date >= ? AND workout_date < ?';
            $params[] = $monthStart;
            $params[] = $monthEnd;
        }
        return (int)DB::run($sql, $params)->fetchColumn();
    }

    public static function listForUser(int $userId, int $limit = 50, string $month = '', int $offset = 0): array
    {
        $sql = 'SELECT id, workout_date, type, duration_min, sets, reps, weight_kg, notes
                FROM workouts WHERE user_id = ?';
        $params = [$userId];

        if ($month) {
            [$monthStart, $monthEnd] = monthBounds($month);
            $sql .= ' AND workout_date >= ? AND workout_date < ?';
            $params[] = $monthStart;
            $params[] = $monthEnd;
        }

        $sql .= ' ORDER BY workout_date DESC, id DESC'
              . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        return DB::run($sql, $params)->fetchAll();
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM workouts WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    public static function create(int $userId, array $data): int
    {
        DB::run(
            'INSERT INTO workouts (user_id, workout_date, type, duration_min, sets, reps, weight_kg, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['workout_date'],
                $data['type'],
                $data['duration_min'] ?: null,
                $data['sets'] ?: null,
                $data['reps'] ?: null,
                $data['weight_kg'] ?: null,
                $data['notes'] ?? null,
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        return DB::run(
            'UPDATE workouts SET workout_date=?, type=?, duration_min=?, sets=?, reps=?, weight_kg=?, notes=?
             WHERE id = ? AND user_id = ?',
            [
                $data['workout_date'],
                $data['type'],
                $data['duration_min'] ?: null,
                $data['sets'] ?: null,
                $data['reps'] ?: null,
                $data['weight_kg'] ?: null,
                $data['notes'] ?? null,
                $id,
                $userId,
            ]
        )->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM workouts WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }

    public static function getStats(int $userId): array
    {
        // Stats for current month. Session count and total minutes come from the
        // same rows, so they are one aggregate rather than two scans.
        [$monthStart, $monthEnd] = monthBounds(date('Y-m'));

        $monthRow = DB::run(
            'SELECT COUNT(*) AS sessions, COALESCE(SUM(duration_min), 0) AS total_min
             FROM workouts
             WHERE user_id = ? AND workout_date >= ? AND workout_date < ?',
            [$userId, $monthStart, $monthEnd]
        )->fetch();

        $monthSessions = (int)($monthRow['sessions'] ?? 0);
        $totalMin      = (int)($monthRow['total_min'] ?? 0);

        // Per-type breakdown (all time)
        $byType = DB::run(
            'SELECT type, COUNT(*) AS sessions, COALESCE(SUM(duration_min), 0) AS total_min
             FROM workouts WHERE user_id = ?
             GROUP BY type ORDER BY sessions DESC LIMIT 10',
            [$userId]
        )->fetchAll();

        // Monthly sessions for past 6 months
        $monthlyCounts = DB::run(
            'SELECT DATE_FORMAT(workout_date, "%Y-%m") AS month, COUNT(*) AS sessions
             FROM workouts WHERE user_id = ?
               AND workout_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY month ORDER BY month ASC',
            [$userId]
        )->fetchAll();

        return [
            'month_sessions' => $monthSessions,
            'month_minutes'  => $totalMin,
            'by_type'        => $byType,
            'monthly'        => $monthlyCounts,
        ];
    }
}
