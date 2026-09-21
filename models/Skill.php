<?php

class Skill
{
    public static function all(int $userId): array
    {
        $sql = "SELECT * FROM skills WHERE user_id = ? ORDER BY created_at DESC";
        return DB::run($sql, [$userId])->fetchAll();
    }

    public static function find(string $id, int $userId)
    {
        $sql = "SELECT * FROM skills WHERE id = ? AND user_id = ?";
        return DB::run($sql, [$id, $userId])->fetch();
    }

    public static function create(int $userId, string $name, int $targetHours, string $color): string
    {
        $id = uuid4();
        $sql = "INSERT INTO skills (id, user_id, name, target_hours, color) VALUES (?, ?, ?, ?, ?)";
        DB::run($sql, [$id, $userId, $name, $targetHours, $color]);
        return $id;
    }

    public static function update(string $id, int $userId, string $name, int $targetHours, string $color): void
    {
        $sql = "UPDATE skills SET name = ?, target_hours = ?, color = ? WHERE id = ? AND user_id = ?";
        DB::run($sql, [$name, $targetHours, $color, $id, $userId]);
    }

    /** Whether this skill exists and belongs to the given account. */
    public static function belongsTo(string $id, int $userId): bool
    {
        return (bool)DB::run(
            'SELECT 1 FROM skills WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetchColumn();
    }

    /** True when a row was actually removed. */
    public static function delete(string $id, int $userId): bool
    {
        $sql = "DELETE FROM skills WHERE id = ? AND user_id = ?";
        return DB::run($sql, [$id, $userId])->rowCount() > 0;
    }
}
