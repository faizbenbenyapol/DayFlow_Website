<?php
class QuickItem {
    public static function listForUser(int $userId): array { return DB::run('SELECT * FROM quick_items WHERE user_id = ? ORDER BY is_done ASC, created_at DESC', [$userId])->fetchAll(); }
    public static function create(int $userId, string $content): int { DB::run('INSERT INTO quick_items (user_id, content) VALUES (?, ?)', [$userId, $content]); return (int)DB::conn()->lastInsertId(); }
    public static function toggle(int $id, int $userId): bool { return DB::run('UPDATE quick_items SET is_done = 1 - is_done WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0; }
    public static function delete(int $id, int $userId): bool { return DB::run('DELETE FROM quick_items WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0; }
}
