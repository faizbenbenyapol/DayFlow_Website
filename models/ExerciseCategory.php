<?php
// =====================================================
// models/ExerciseCategory.php — per-user workout types
// =====================================================

class ExerciseCategory
{
    public static function listForUser(int $userId): array
    {
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
        DB::run(
            'INSERT IGNORE INTO exercise_categories (user_id, name) VALUES (?, ?)',
            [$userId, $name]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, string $name): bool
    {
        return DB::run(
            'UPDATE exercise_categories SET name = ? WHERE id = ? AND user_id = ?',
            [$name, $id, $userId]
        )->rowCount() >= 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM exercise_categories WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }
}
