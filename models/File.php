<?php
// =====================================================
// models/File.php
// =====================================================

class FileModel
{
    // ------------------------------------------------------------------ //
    // PRIVATE (authenticated) helpers
    // ------------------------------------------------------------------ //

    public static function listFolder(int $userId, ?int $parentId): array
    {
        if ($parentId === null) {
            $stmt = DB::run(
                'SELECT id, name, type, mime_type, file_size, file_path, drive_id, parent_id, created_at
                 FROM files WHERE user_id = ? AND parent_id IS NULL
                 ORDER BY type DESC, name ASC',
                [$userId]
            );
        } else {
            $stmt = DB::run(
                'SELECT id, name, type, mime_type, file_size, file_path, drive_id, parent_id, created_at
                 FROM files WHERE user_id = ? AND parent_id = ?
                 ORDER BY type DESC, name ASC',
                [$userId, $parentId]
            );
        }
        return $stmt->fetchAll();
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM files WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    // ------------------------------------------------------------------ //
    // PUBLIC (no auth) helpers — used by share links
    // ------------------------------------------------------------------ //

    /**
     * Get a file row without requiring user ownership (for public share links)
     */
    public static function getByIdPublic(int $id): ?array
    {
        return DB::run(
            'SELECT * FROM files WHERE id = ?',
            [$id]
        )->fetch() ?: null;
    }

    /**
     * List children of a folder without auth (for public folder shares)
     */
    public static function listFolderPublic(int $folderId): array
    {
        return DB::run(
            'SELECT id, name, type, mime_type, file_size, file_path, drive_id, parent_id, created_at
             FROM files WHERE parent_id = ?
             ORDER BY type DESC, name ASC',
            [$folderId]
        )->fetchAll();
    }

    // ------------------------------------------------------------------ //
    // Writes
    // ------------------------------------------------------------------ //

    /** เพิ่มโฟลเดอร์ในสารบัญ @param string|null $driveId id ใน Google Drive ถ้าสร้างใน Drive แล้ว */
    public static function createFolder(int $userId, string $name, ?int $parentId, ?string $driveId = null): int
    {
        DB::run(
            'INSERT INTO files (user_id, parent_id, name, type, drive_id) VALUES (?, ?, ?, "folder", ?)',
            [$userId, $parentId, $name, $driveId]
        );
        return (int)DB::conn()->lastInsertId();
    }

    /**
     * เพิ่มไฟล์ในสารบัญ ไฟล์อยู่ที่ใดที่หนึ่ง: $filePath (บนเซิร์ฟเวอร์) หรือ $driveId (ใน Google Drive)
     */
    public static function createFile(int $userId, string $name, string $mimeType, ?string $filePath, int $fileSize, ?int $parentId, ?string $driveId = null): int
    {
        DB::run(
            'INSERT INTO files (user_id, parent_id, name, type, mime_type, file_path, drive_id, file_size)
             VALUES (?, ?, ?, "file", ?, ?, ?, ?)',
            [$userId, $parentId, $name, $mimeType, $filePath, $driveId, $fileSize]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function rename(int $id, int $userId, string $newName): bool
    {
        return DB::run(
            'UPDATE files SET name = ? WHERE id = ? AND user_id = ?',
            [$newName, $id, $userId]
        )->rowCount() > 0;
    }

    /** ย้ายไปไว้ใต้ $newParentId จะทำให้โฟลเดอร์อยู่ในตัวเองหรือในลูกของมันไหม */
    public static function wouldCreateCycle(int $id, int $userId, ?int $newParentId): bool
    {
        return $newParentId !== null && ($id === $newParentId || in_array($id, self::getAncestorIds($newParentId, $userId), true));
    }

    /** ย้ายไฟล์หรือโฟลเดอร์ไปโฟลเดอร์อื่น (null = ราก) @return bool false เมื่อเกิดวงวน */
    public static function move(int $id, int $userId, ?int $newParentId): bool
    {
        if (self::wouldCreateCycle($id, $userId, $newParentId)) return false;
        return DB::run(
            'UPDATE files SET parent_id = ? WHERE id = ? AND user_id = ?',
            [$newParentId, $id, $userId]
        )->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        $file = self::getById($id, $userId);
        if (!$file) return false;

        // If file, delete physical file
        if ($file['type'] === 'file' && $file['file_path']) {
            $physPath = UPLOAD_DIR . $file['file_path'];
            if (file_exists($physPath)) {
                @unlink($physPath);
            }
        }

        // If folder, recursively delete children
        if ($file['type'] === 'folder') {
            self::deleteChildren($id, $userId);
        }

        DB::run('DELETE FROM files WHERE id = ? AND user_id = ?', [$id, $userId]);
        return true;
    }

    /**
     * บันทึกว่าแถวนี้อยู่ใน Google Drive แล้ว ไฟล์ล้าง file_path ด้วย (ผู้เรียกลบไฟล์บนดิสก์เอง หลังบันทึกแล้ว)
     * ไม่ใช่การแก้ของผู้ใช้ จึงไม่มีเวลาแก้ไขให้เปลี่ยน
     */
    public static function setDriveId(int $id, string $driveId): void
    {
        DB::run('UPDATE files SET drive_id = ?, file_path = NULL WHERE id = ?', [$driveId, $id]);
    }

    // ------------------------------------------------------------------ //
    // Navigation
    // ------------------------------------------------------------------ //

    public static function getBreadcrumbs(int $folderId, int $userId): array
    {
        $crumbs = [];
        $id = $folderId;

        while ($id) {
            $row = self::getById($id, $userId);
            if (!$row) break;
            array_unshift($crumbs, ['id' => $row['id'], 'name' => $row['name']]);
            $id = $row['parent_id'];
        }
        return $crumbs;
    }

    /**
     * Get all ancestor IDs of a folder (for cycle detection during move)
     */
    private static function getAncestorIds(int $folderId, int $userId): array
    {
        $ids = [];
        $id  = $folderId;
        while ($id) {
            $row = self::getById($id, $userId);
            if (!$row) break;
            $ids[] = (int)$row['id'];
            $id    = $row['parent_id'];
        }
        return $ids;
    }

    /**
     * Get all direct folders of a user (for move dialog)
     */
    public static function allFolders(int $userId): array
    {
        return DB::run(
            'SELECT id, name, parent_id FROM files
             WHERE user_id = ? AND type = "folder"
             ORDER BY name ASC',
            [$userId]
        )->fetchAll();
    }

    // ------------------------------------------------------------------ //
    // Private helpers
    // ------------------------------------------------------------------ //

    private static function deleteChildren(int $folderId, int $userId): void
    {
        $children = DB::run(
            'SELECT id, type, file_path FROM files WHERE parent_id = ? AND user_id = ?',
            [$folderId, $userId]
        )->fetchAll();

        foreach ($children as $child) {
            if ($child['type'] === 'folder') {
                self::deleteChildren((int)$child['id'], $userId);
            } elseif ($child['file_path']) {
                $physPath = UPLOAD_DIR . $child['file_path'];
                if (file_exists($physPath)) @unlink($physPath);
            }
        }

        DB::run('DELETE FROM files WHERE parent_id = ? AND user_id = ?', [$folderId, $userId]);
    }
}
