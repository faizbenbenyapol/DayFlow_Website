<?php
// =====================================================
// models/FileShare.php
// =====================================================

class FileShare
{
    // ------------------------------------------------------------------ //
    // Create a new share link
    // ------------------------------------------------------------------ //
    public static function create(
        int    $userId,
        int    $fileId,
        string $label,
        string $permission,
        ?string $expiresAt
    ): string {
        $token = bin2hex(random_bytes(32)); // 64-char hex token

        DB::run(
            'INSERT INTO file_shares (user_id, file_id, token, label, permission, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $fileId, $token, $label, $permission, $expiresAt]
        );

        return $token;
    }

    // ------------------------------------------------------------------ //
    // Get share by token (with file info joined)
    // ------------------------------------------------------------------ //
    public static function getByToken(string $token): ?array
    {
        return DB::run(
            'SELECT s.*, f.name AS file_name, f.type AS file_type,
                    f.mime_type, f.file_path, f.file_size, f.parent_id
             FROM file_shares s
             JOIN files f ON f.id = s.file_id
             WHERE s.token = ?',
            [$token]
        )->fetch() ?: null;
    }

    // ------------------------------------------------------------------ //
    // List all shares for a user (with file info)
    // ------------------------------------------------------------------ //
    public static function listByUser(int $userId): array
    {
        return DB::run(
            'SELECT s.*, f.name AS file_name, f.type AS file_type
             FROM file_shares s
             JOIN files f ON f.id = s.file_id
             WHERE s.user_id = ?
             ORDER BY s.created_at DESC',
            [$userId]
        )->fetchAll();
    }

    // ------------------------------------------------------------------ //
    // Update share settings
    // ------------------------------------------------------------------ //
    public static function update(
        int    $id,
        int    $userId,
        string $label,
        string $permission,
        ?string $expiresAt
    ): bool {
        return DB::run(
            'UPDATE file_shares
             SET label = ?, permission = ?, expires_at = ?
             WHERE id = ? AND user_id = ?',
            [$label, $permission, $expiresAt, $id, $userId]
        )->rowCount() > 0;
    }

    // ------------------------------------------------------------------ //
    // Delete a share
    // ------------------------------------------------------------------ //
    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM file_shares WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }

    // ------------------------------------------------------------------ //
    // Validate that a token is still active (not expired)
    // ------------------------------------------------------------------ //
    public static function isValid(array $share): bool
    {
        if (!$share['expires_at']) return true;
        return strtotime($share['expires_at']) > time();
    }
}
