<?php
// =====================================================
// models/Task.php
// =====================================================

class Task
{
    public static function getAllForUser(int $userId): array
    {
        return DB::run(
            'SELECT id, title, description, quadrant, status, due_date, position
             FROM tasks
             WHERE user_id = ?
             ORDER BY quadrant ASC, position ASC, id ASC',
            [$userId]
        )->fetchAll();
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM tasks WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    public static function create(int $userId, array $data): int
    {
        $maxPos = (int)DB::run(
            'SELECT COALESCE(MAX(position), -1) FROM tasks WHERE user_id = ? AND quadrant = ?',
            [$userId, (int)($data['quadrant'] ?? 1)]
        )->fetchColumn();

        DB::run(
            'INSERT INTO tasks (user_id, title, description, quadrant, status, due_date, position)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['title'],
                $data['description'] ?? null,
                (int)($data['quadrant'] ?? 1),
                $data['status'] ?? 'open',
                $data['due_date'] ?: null,
                $maxPos + 1
            ]
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
        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[] = $data['description'];
        }
        if (array_key_exists('quadrant', $data)) {
            $fields[] = 'quadrant = ?';
            $params[] = (int)$data['quadrant'];
        }
        if (array_key_exists('status', $data)) {
            $fields[] = 'status = ?';
            $params[] = $data['status'];
        }
        if (array_key_exists('due_date', $data)) {
            $fields[] = 'due_date = ?';
            $params[] = $data['due_date'] ?: null;
        }
        if (array_key_exists('position', $data)) {
            $fields[] = 'position = ?';
            $params[] = (int)$data['position'];
        }

        if (empty($fields)) return false;

        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $userId;

        $stmt = DB::run(
            'UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?',
            $params
        );
        return $stmt->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        $stmt = DB::run('DELETE FROM tasks WHERE id = ? AND user_id = ?', [$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function reorder(int $userId, array $items): void
    {
        // $items = [['id' => 1, 'quadrant' => 1, 'position' => 0], ...]
        $db = DB::conn();
        $stmt = $db->prepare(
            'UPDATE tasks SET quadrant = ?, position = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        foreach ($items as $item) {
            $stmt->execute([
                (int)$item['quadrant'],
                (int)$item['position'],
                (int)$item['id'],
                $userId
            ]);
        }
    }
}
