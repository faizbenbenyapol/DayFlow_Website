<?php
// =====================================================
// models/DailyTodo.php
// =====================================================

class DailyTodo
{
    public static function getForDate(int $userId, string $date): array
    {
        return DB::run(
            'SELECT id, title, is_done, position FROM daily_todos
             WHERE user_id = ? AND todo_date = ?
             ORDER BY position ASC, id ASC',
            [$userId, $date]
        )->fetchAll();
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM daily_todos WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    public static function create(int $userId, string $date, string $title): int
    {
        $maxPos = (int)DB::run(
            'SELECT COALESCE(MAX(position), -1) FROM daily_todos WHERE user_id = ? AND todo_date = ?',
            [$userId, $date]
        )->fetchColumn();

        DB::run(
            'INSERT INTO daily_todos (user_id, todo_date, title, is_done, position)
             VALUES (?, ?, ?, 0, ?)',
            [$userId, $date, $title, $maxPos + 1]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        $fields = [];
        $params = [];

        if (array_key_exists('title', $data)) {
            $fields[] = 'title = ?';
            $params[] = $data['title'];
        }
        if (array_key_exists('is_done', $data)) {
            $fields[] = 'is_done = ?';
            $params[] = (int)$data['is_done'];
        }

        if (empty($fields)) return false;

        $params[] = $id;
        $params[] = $userId;

        return DB::run(
            'UPDATE daily_todos SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?',
            $params
        )->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM daily_todos WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }

    public static function reorder(int $userId, array $items): void
    {
        $db   = DB::conn();
        $stmt = $db->prepare('UPDATE daily_todos SET position = ? WHERE id = ? AND user_id = ?');
        foreach ($items as $item) {
            $stmt->execute([(int)$item['position'], (int)$item['id'], $userId]);
        }
    }
}
