<?php
// =====================================================
// models/Note.php
// =====================================================

class Note
{
    public static function listForUser(int $userId, string $search = '', int $tagId = 0): array
    {
        $sql = 'SELECT n.id, n.title, n.is_encrypted, n.pinned, n.created_at, n.updated_at,
                       (SELECT content FROM note_blocks WHERE note_id = n.id AND type = "text"
                        ORDER BY position ASC LIMIT 1) AS preview,
                       (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ",")
                        FROM note_tags t
                        JOIN note_tag_relations r ON r.tag_id = t.id
                        WHERE r.note_id = n.id) AS tags_list
                FROM notes n
                WHERE n.user_id = ?';
        $params = [$userId];

        if ($tagId > 0) {
            $sql .= ' AND EXISTS (SELECT 1 FROM note_tag_relations ntr WHERE ntr.note_id = n.id AND ntr.tag_id = ?)';
            $params[] = $tagId;
        }

        if ($search) {
            $sql .= ' AND n.title LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY n.pinned DESC, n.updated_at DESC';
        return DB::run($sql, $params)->fetchAll();
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM notes WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    public static function create(int $userId, string $title, bool $encrypted, string $password = ''): int
    {
        $hash = null;
        $salt = null;
        if ($encrypted && $password) {
            $salt = bin2hex(random_bytes(16));
            $hash = password_hash($password, PASSWORD_BCRYPT);
        }

        DB::run(
            'INSERT INTO notes (user_id, title, is_encrypted, password_hash, encrypt_salt)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $title ?: 'ไม่มีชื่อ', (int)$encrypted, $hash, $salt]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        $fields = [];
        $params = [];

        if (array_key_exists('title', $data)) {
            $fields[] = 'title = ?';
            $params[] = $data['title'] ?: 'ไม่มีชื่อ';
        }
        if (array_key_exists('pinned', $data)) {
            $fields[] = 'pinned = ?';
            $params[] = (int)$data['pinned'];
        }

        if (empty($fields)) return false;

        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $userId;

        $stmt = DB::run(
            'UPDATE notes SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?',
            $params
        );
        return $stmt->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        $stmt = DB::run('DELETE FROM notes WHERE id = ? AND user_id = ?', [$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function verifyPassword(array $note, string $password): bool
    {
        if (!$note['password_hash']) return false;
        return password_verify($password, $note['password_hash']);
    }

    public static function getTagsForNote(int $noteId): array
    {
        return DB::run(
            'SELECT t.id, t.name FROM note_tags t
             JOIN note_tag_relations r ON r.tag_id = t.id
             WHERE r.note_id = ?
             ORDER BY t.name ASC',
            [$noteId]
        )->fetchAll();
    }

    public static function syncTags(int $noteId, array $tagNames, int $userId): void
    {
        // Remove all existing tag relations
        DB::run('DELETE FROM note_tag_relations WHERE note_id = ?', [$noteId]);

        foreach ($tagNames as $name) {
            $name = trim($name);
            if (!$name) continue;

            // Upsert tag
            DB::run(
                'INSERT IGNORE INTO note_tags (user_id, name) VALUES (?, ?)',
                [$userId, $name]
            );
            $tagId = (int)DB::run(
                'SELECT id FROM note_tags WHERE user_id = ? AND name = ?',
                [$userId, $name]
            )->fetchColumn();

            DB::run(
                'INSERT IGNORE INTO note_tag_relations (note_id, tag_id) VALUES (?, ?)',
                [$noteId, $tagId]
            );
        }

        // Touch note updated_at
        DB::run('UPDATE notes SET updated_at = NOW() WHERE id = ?', [$noteId]);
    }

    public static function getUserTags(int $userId): array
    {
        return DB::run(
            'SELECT t.id, t.name, COUNT(r.note_id) AS note_count
             FROM note_tags t
             LEFT JOIN note_tag_relations r ON r.tag_id = t.id
             WHERE t.user_id = ?
             GROUP BY t.id, t.name
             ORDER BY t.name ASC',
            [$userId]
        )->fetchAll();
    }
}

class NoteTag
{
    public static function create(int $userId, string $name): int
    {
        $name = trim($name);
        if ($name === '') return 0;

        // Check duplicate
        $existing = (int)DB::run(
            'SELECT id FROM note_tags WHERE user_id = ? AND name = ?',
            [$userId, $name]
        )->fetchColumn();
        if ($existing > 0) return $existing;

        DB::run(
            'INSERT INTO note_tags (user_id, name) VALUES (?, ?)',
            [$userId, $name]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, string $name): bool
    {
        $name = trim($name);
        if ($name === '') return false;

        // Skip if same name would collide with another of user's tag
        $dup = (int)DB::run(
            'SELECT id FROM note_tags WHERE user_id = ? AND name = ? AND id <> ?',
            [$userId, $name, $id]
        )->fetchColumn();
        if ($dup > 0) return false;

        return DB::run(
            'UPDATE note_tags SET name = ? WHERE id = ? AND user_id = ?',
            [$name, $id, $userId]
        )->rowCount() >= 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM note_tags WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }
}
