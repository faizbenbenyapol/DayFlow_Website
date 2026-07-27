<?php
// =====================================================
// models/FileTransfer.php — File Transfer (Send-Anywhere style)
// =====================================================

class FileTransfer
{
    /**
     * Create a new file transfer and return the record
     */
    public static function create(int $userId, string $filesJson, int $totalSize, string $expiresAt, int $maxDownloads = 0): array
    {
        $code  = self::generateUniqueCode();
        $token = bin2hex(random_bytes(32));

        DB::run(
            'INSERT INTO file_transfers (user_id, code, token, files_json, total_size, max_downloads, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $code, $token, $filesJson, $totalSize, $maxDownloads, $expiresAt]
        );

        $id = (int) DB::conn()->lastInsertId();
        return self::getById($id);
    }

    /**
     * Generate a unique 6-digit numeric code
     */
    private static function generateUniqueCode(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $exists = DB::run(
                'SELECT id FROM file_transfers WHERE code = ? AND expires_at > NOW()',
                [$code]
            )->fetch();
            if (!$exists) return $code;
        }
        // Extremely unlikely fallback
        throw new \RuntimeException('Cannot generate unique transfer code');
    }

    /**
     * Get transfer by ID
     */
    public static function getById(int $id): ?array
    {
        $row = DB::run('SELECT * FROM file_transfers WHERE id = ?', [$id])->fetch();
        return $row ?: null;
    }

    /**
     * Find a transfer by its 6-digit code (only non-expired)
     */
    public static function getByCode(string $code): ?array
    {
        $row = DB::run(
            'SELECT * FROM file_transfers WHERE code = ? AND expires_at > NOW()',
            [$code]
        )->fetch();
        return $row ?: null;
    }

    /**
     * Find a transfer by its download token (only non-expired)
     */
    public static function getByToken(string $token): ?array
    {
        $row = DB::run(
            'SELECT * FROM file_transfers WHERE token = ?',
            [$token]
        )->fetch();
        return $row ?: null;
    }

    /**
     * Check if a transfer is still valid (not expired, not over download limit)
     */
    public static function isValid(?array $transfer): bool
    {
        if (!$transfer) return false;
        if (strtotime($transfer['expires_at']) <= time()) return false;
        if ($transfer['max_downloads'] > 0 && $transfer['download_count'] >= $transfer['max_downloads']) return false;
        return true;
    }

    /**
     * Increment the download counter
     */
    public static function incrementDownload(int $id): void
    {
        DB::run('UPDATE file_transfers SET download_count = download_count + 1 WHERE id = ?', [$id]);
    }

    /**
     * List all transfers for a user (newest first)
     */
    public static function listByUser(int $userId): array
    {
        return DB::run(
            'SELECT id, code, token, files_json, total_size, download_count, max_downloads, expires_at, created_at
             FROM file_transfers
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 50',
            [$userId]
        )->fetchAll();
    }

    /**
     * Delete a transfer and its files from disk
     */
    public static function delete(int $id, int $userId): bool
    {
        $transfer = DB::run(
            'SELECT * FROM file_transfers WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch();

        if (!$transfer) return false;

        // Delete physical files
        self::deleteFiles($transfer);

        DB::run('DELETE FROM file_transfers WHERE id = ?', [$id]);
        return true;
    }

    /**
     * Cleanup expired transfers (delete records + files)
     */
    public static function cleanup(): int
    {
        $expired = DB::run(
            'SELECT * FROM file_transfers WHERE expires_at <= NOW()'
        )->fetchAll();

        foreach ($expired as $transfer) {
            self::deleteFiles($transfer);
        }

        $stmt = DB::run('DELETE FROM file_transfers WHERE expires_at <= NOW()');
        return $stmt->rowCount();
    }

    /**
     * Delete physical files for a transfer
     */
    private static function deleteFiles(array $transfer): void
    {
        $files = json_decode($transfer['files_json'], true) ?: [];
        foreach ($files as $f) {
            if (!empty($f['path'])) {
                $fullPath = UPLOAD_DIR . $f['path'];
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }

        // Try to remove the transfer directory
        if (!empty($files[0]['path'])) {
            $dir = UPLOAD_DIR . dirname($files[0]['path']);
            if (is_dir($dir)) {
                @rmdir($dir); // Only removes if empty
            }
        }
    }
}
