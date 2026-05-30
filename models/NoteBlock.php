<?php
// =====================================================
// models/NoteBlock.php
// =====================================================

class NoteBlock
{
    /**
     * Encrypt block content using AES-256-CBC
     */
    public static function encrypt(string $content, string $password, string $salt): string
    {
        $key = openssl_pbkdf2($password, $salt, 10000, 32, 'sha256');
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($content, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $enc);
    }

    /**
     * Decrypt block content
     */
    public static function decrypt(string $encrypted, string $password, string $salt): ?string
    {
        try {
            $key    = openssl_pbkdf2($password, $salt, 10000, 32, 'sha256');
            $raw    = base64_decode($encrypted);
            $iv     = substr($raw, 0, 16);
            $data   = substr($raw, 16);
            $result = openssl_decrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            return $result === false ? null : $result;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function getForNote(int $noteId): array
    {
        return DB::run(
            'SELECT id, type, content, position FROM note_blocks
             WHERE note_id = ? ORDER BY position ASC',
            [$noteId]
        )->fetchAll();
    }

    public static function getById(int $id, int $noteId): ?array
    {
        return DB::run(
            'SELECT * FROM note_blocks WHERE id = ? AND note_id = ?',
            [$id, $noteId]
        )->fetch() ?: null;
    }

    public static function create(int $noteId, string $type, string $content): int
    {
        $maxPos = (int)DB::run(
            'SELECT COALESCE(MAX(position), -1) FROM note_blocks WHERE note_id = ?',
            [$noteId]
        )->fetchColumn();

        DB::run(
            'INSERT INTO note_blocks (note_id, type, content, position) VALUES (?, ?, ?, ?)',
            [$noteId, $type, $content, $maxPos + 1]
        );
        $newId = (int)DB::conn()->lastInsertId();

        // Touch parent note (must come AFTER capturing lastInsertId — PDO resets it to 0 on UPDATE)
        DB::run('UPDATE notes SET updated_at = NOW() WHERE id = ?', [$noteId]);

        return $newId;
    }

    public static function update(int $id, int $noteId, string $type, string $content): bool
    {
        $stmt = DB::run(
            'UPDATE note_blocks SET type = ?, content = ? WHERE id = ? AND note_id = ?',
            [$type, $content, $id, $noteId]
        );
        if ($stmt->rowCount() > 0) {
            DB::run('UPDATE notes SET updated_at = NOW() WHERE id = ?', [$noteId]);
            return true;
        }
        return false;
    }

    public static function delete(int $id, int $noteId): bool
    {
        $stmt = DB::run('DELETE FROM note_blocks WHERE id = ? AND note_id = ?', [$id, $noteId]);
        return $stmt->rowCount() > 0;
    }

    public static function reorder(int $noteId, array $items): void
    {
        $db   = DB::conn();
        $stmt = $db->prepare('UPDATE note_blocks SET position = ? WHERE id = ? AND note_id = ?');
        foreach ($items as $item) {
            $stmt->execute([(int)$item['position'], (int)$item['id'], $noteId]);
        }
        DB::run('UPDATE notes SET updated_at = NOW() WHERE id = ?', [$noteId]);
    }
}
