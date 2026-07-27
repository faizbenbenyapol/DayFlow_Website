<?php
// =====================================================
// models/Workout.php
// =====================================================

class Workout
{
    public static function listForUser(int $userId, int $limit = 50, string $month = ''): array
    {
        $sql = 'SELECT id, workout_date, type, duration_min, sets, reps, weight_kg, notes
                FROM workouts WHERE user_id = ?';
        $params = [$userId];

        if ($month) {
            $sql .= ' AND DATE_FORMAT(workout_date, "%Y-%m") = ?';
            $params[] = $month;
        }

        $sql .= ' ORDER BY workout_date DESC, id DESC LIMIT ?';
        $params[] = $limit;

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
        // Stats for current month
        $month = date('Y-m');

        $monthSessions = (int)DB::run(
            'SELECT COUNT(*) FROM workouts WHERE user_id = ? AND DATE_FORMAT(workout_date, "%Y-%m") = ?',
            [$userId, $month]
        )->fetchColumn();

        $totalMin = (int)DB::run(
            'SELECT COALESCE(SUM(duration_min), 0) FROM workouts
             WHERE user_id = ? AND DATE_FORMAT(workout_date, "%Y-%m") = ?',
            [$userId, $month]
        )->fetchColumn();

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

class ExerciseCategory
{
    public static function checkTable(): void
    {
        DB::run("CREATE TABLE IF NOT EXISTS `exercise_categories` (
          `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT UNSIGNED NOT NULL,
          `name`    VARCHAR(80) NOT NULL,
          UNIQUE KEY `uq_user_ex_cat` (`user_id`, `name`),
          FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public static function listForUser(int $userId): array
    {
        self::checkTable();
        $cats = DB::run(
            'SELECT id, name FROM exercise_categories WHERE user_id = ? ORDER BY name ASC',
            [$userId]
        )->fetchAll();

        if (empty($cats)) {
            $defaultTypes = ['วิ่ง', 'ยกน้ำหนัก', 'ว่ายน้ำ', 'ปั่นจักรยาน', 'โยคะ', 'HIIT', 'เดิน', 'กระโดดเชือก'];
            foreach ($defaultTypes as $name) {
                DB::run(
                    'INSERT IGNORE INTO exercise_categories (user_id, name) VALUES (?, ?)',
                    [$userId, $name]
                );
            }
            $cats = DB::run(
                'SELECT id, name FROM exercise_categories WHERE user_id = ? ORDER BY name ASC',
                [$userId]
            )->fetchAll();
        }
        return $cats;
    }

    public static function create(int $userId, string $name): int
    {
        self::checkTable();
        DB::run(
            'INSERT IGNORE INTO exercise_categories (user_id, name) VALUES (?, ?)',
            [$userId, $name]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, string $name): bool
    {
        self::checkTable();
        return DB::run(
            'UPDATE exercise_categories SET name = ? WHERE id = ? AND user_id = ?',
            [$name, $id, $userId]
        )->rowCount() >= 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        self::checkTable();
        return DB::run(
            'DELETE FROM exercise_categories WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }
}
