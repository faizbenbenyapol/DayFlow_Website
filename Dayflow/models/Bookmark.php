<?php
class Bookmark {
    public static function listForUser(int $userId): array { return DB::run('SELECT * FROM bookmarks WHERE user_id = ? ORDER BY category ASC, created_at DESC', [$userId])->fetchAll(); }
    public static function create(int $userId, string $title, string $url, string $category): int { DB::run('INSERT INTO bookmarks (user_id, title, url, category) VALUES (?, ?, ?, ?)', [$userId, $title, $url, $category]); return (int)DB::conn()->lastInsertId(); }
    public static function delete(int $id, int $userId): bool { return DB::run('DELETE FROM bookmarks WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0; }
}
