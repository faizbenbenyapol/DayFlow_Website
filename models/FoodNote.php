<?php
// =====================================================
// models/FoodNote.php
// =====================================================

class FoodNote
{
    public static function listForUser(int $userId, string $type = '', string $reaction = ''): array
    {
        $where  = 'WHERE user_id = ?';
        $params = [$userId];

        if ($type) {
            $where   .= ' AND type = ?';
            $params[] = $type;
        }
        if ($reaction) {
            $where   .= ' AND reaction = ?';
            $params[] = $reaction;
        }

        return DB::run(
            "SELECT id, name, type, reaction, severity, symptoms, notes, created_at
             FROM food_notes
             $where
             ORDER BY reaction ASC, severity DESC, name ASC",
            $params
        )->fetchAll();
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM food_notes WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    public static function create(int $userId, array $data): int
    {
        DB::run(
            'INSERT INTO food_notes (user_id, name, type, reaction, severity, symptoms, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['name'],
                $data['type']     ?? 'food',
                $data['reaction'] ?? 'avoid',
                $data['severity'] ?? 'moderate',
                $data['symptoms'] ?? null,
                $data['notes']    ?? null,
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        $stmt = DB::run(
            'UPDATE food_notes
             SET name=?, type=?, reaction=?, severity=?, symptoms=?, notes=?
             WHERE id = ? AND user_id = ?',
            [
                $data['name'],
                $data['type']     ?? 'food',
                $data['reaction'] ?? 'avoid',
                $data['severity'] ?? 'moderate',
                $data['symptoms'] ?? null,
                $data['notes']    ?? null,
                $id,
                $userId,
            ]
        );
        return $stmt->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM food_notes WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }

    public static function summary(int $userId): array
    {
        $rows = DB::run(
            'SELECT reaction, COUNT(*) as cnt FROM food_notes WHERE user_id = ? GROUP BY reaction',
            [$userId]
        )->fetchAll();
        $result = ['allergy' => 0, 'intolerance' => 0, 'avoid' => 0, 'caution' => 0];
        foreach ($rows as $r) {
            $result[$r['reaction']] = (int)$r['cnt'];
        }
        return $result;
    }
}
