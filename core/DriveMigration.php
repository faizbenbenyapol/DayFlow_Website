<?php
// =====================================================
// core/DriveMigration.php — ย้ายไฟล์เดิมบนเซิร์ฟเวอร์ขึ้น Google Drive ของเจ้าของ (REQ-22)
//
// ทำทีละไฟล์ภายในเวลาที่ให้ (step) จึงเรียกซ้ำได้จากหน้าตั้งค่าระหว่างเปิดอยู่ และจาก cron
// เมื่อปิดหน้าไปแล้ว (EDGE-13) แถวที่มี drive_id แล้วถูกข้ามเสมอ จึงไม่ย้ายซ้ำ
// ลำดับต่อไฟล์: อัปโหลด → เทียบขนาด → บันทึก drive_id → ลบไฟล์บนเซิร์ฟเวอร์ (ขั้นสุดท้ายเสมอ)
// =====================================================

require_once ROOT . '/core/FileStore.php';

final class DriveMigration
{
    /**
     * ย้ายต่อจนครบหรือหมดเวลา แล้วคืนสถานะ ถ้ามีอีกตัวกำลังย้ายของผู้ใช้คนนี้อยู่ (หน้าเว็บกับ cron) คืนสถานะเฉย ๆ
     * ผลข้างเคียง: อัปโหลดไป Drive, แก้ files, ลบไฟล์บนดิสก์, เปลี่ยนสถานะใน drive_links
     *   ครบ → connected · Drive เต็ม → paused_full (EDGE-12) · สิทธิ์หลุด → revoked (EDGE-11)
     * @return array{state: string, pending: int, done: int}
     */
    public static function step(int $userId, int $budgetSeconds): array
    {
        $lockName = 'dayflow-drive-migrate-' . $userId;
        if ((int)DB::run('SELECT GET_LOCK(?, 0)', [$lockName])->fetchColumn() !== 1) return self::status($userId);
        try {
            self::work($userId, time() + $budgetSeconds);
        } finally {
            DB::run('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
        return self::status($userId);
    }

    /** ไฟล์ที่ยังรอย้าย (มีไฟล์บนเซิร์ฟเวอร์) และที่อยู่ใน Drive แล้ว พร้อมสถานะการเชื่อม */
    public static function status(int $userId): array
    {
        $counts = DB::run(
            'SELECT SUM(drive_id IS NULL AND file_path IS NOT NULL) AS pending, SUM(drive_id IS NOT NULL) AS done
             FROM files WHERE user_id = ? AND type = "file"',
            [$userId]
        )->fetch();
        return ['state' => DriveLink::find($userId)['state'] ?? 'none', 'pending' => (int)$counts['pending'], 'done' => (int)$counts['done']];
    }

    /** cron: ย้ายต่อให้ทุกคนที่ยังย้ายไม่ครบ รวมกันไม่เกิน $budgetSeconds วินาที (คนที่ไม่ทันได้ต่อรอบหน้า) */
    public static function runAll(int $budgetSeconds): void
    {
        $deadline = time() + $budgetSeconds;
        foreach (DriveLink::migratingUserIds() as $userId) {
            if (time() >= $deadline) return;
            self::step($userId, $deadline - time());
        }
    }

    /**
     * ย้ายโฟลเดอร์ก่อน (โครงเดิมรวมโฟลเดอร์ว่าง) แล้วไฟล์ทีละไฟล์ ภายใต้ lock ของผู้ใช้แล้ว
     * ทุกการเปลี่ยนสถานะทำได้จาก migrating เท่านั้น ผู้ใช้กดยกเลิกระหว่างทาง การย้ายหยุดที่ไฟล์ถัดไปและสถานะคงเป็น disconnected
     * ไฟล์ที่ล้มด้วยเหตุอื่น (ไม่ใช่เต็มหรือสิทธิ์หลุด) ถูกข้ามไปไฟล์ถัดไป แล้วคงสถานะ migrating ให้ cron ลองใหม่
     */
    private static function work(int $userId, int $deadline): void
    {
        $link = DriveLink::find($userId);
        if (!$link || $link['state'] !== 'migrating') return;
        $store = FileStore::forUser($userId);
        $lastError = null;

        try {
            foreach (DB::run('SELECT id FROM files WHERE user_id = ? AND type = "folder" AND drive_id IS NULL ORDER BY id', [$userId])->fetchAll() as $folder) {
                $store->ensureDriveFolder((int)$folder['id']);
            }
            // ไล่ตาม id ทีละแถว join กับสถานะ: ยกเลิกการเชื่อมแล้วไม่ได้แถวถัดไป ไฟล์ที่หายจากดิสก์ถูกข้าม
            $afterId = 0;
            while (time() < $deadline) {
                $row = DB::run(
                    'SELECT f.* FROM files f JOIN drive_links d ON d.user_id = f.user_id AND d.state = "migrating"
                     WHERE f.user_id = ? AND f.type = "file" AND f.drive_id IS NULL AND f.file_path IS NOT NULL AND f.id > ? ORDER BY f.id LIMIT 1',
                    [$userId, $afterId]
                )->fetch();
                if (!$row) {
                    DriveLink::setState($userId, ['migrating'], $lastError === null ? 'connected' : 'migrating', $lastError);
                    return;
                }
                $afterId = (int)$row['id'];
                try {
                    self::moveFile($store, $row);
                } catch (DriveError $e) {
                    if ($e->kind === DriveError::QUOTA || $e->kind === DriveError::AUTH) throw $e;
                    $lastError = $e->getMessage();
                }
            }
            if ($lastError !== null) DriveLink::setState($userId, ['migrating'], 'migrating', $lastError);
        } catch (DriveError $e) {
            match ($e->kind) {
                DriveError::QUOTA => DriveLink::setState($userId, ['migrating'], 'paused_full', 'Google Drive is full'),
                DriveError::AUTH  => $store->noteFailure($e),
                default           => DriveLink::setState($userId, ['migrating'], 'migrating', $e->getMessage()),   // โฟลเดอร์ล้ม ลองใหม่รอบหน้า
            };
        }
    }

    /**
     * ย้ายไฟล์หนึ่งไฟล์ ลบไฟล์บนเซิร์ฟเวอร์เป็นขั้นสุดท้าย หลังขนาดใน Drive ตรงและบันทึก drive_id แล้ว
     * @throws DriveError จาก Google (เต็ม สิทธิ์หลุด) ไฟล์บนเซิร์ฟเวอร์ยังอยู่ครบ
     */
    private static function moveFile(FileStore $store, array $row): void
    {
        $path = UPLOAD_DIR . $row['file_path'];
        if (!is_file($path)) return;
        $size = filesize($path);

        $parent = $store->ensureDriveFolder($row['parent_id'] !== null ? (int)$row['parent_id'] : null);
        $stored = $store->client()->upload($path, (string)$row['name'], (string)($row['mime_type'] ?: 'application/octet-stream'), $parent);
        if ($stored['size'] !== $size) {
            $store->client()->trash($stored['id']);   // สำเนาไม่ครบ ทิ้งไป ไฟล์บนเซิร์ฟเวอร์ยังอยู่ ลองใหม่รอบหน้า
            throw new DriveError(DriveError::OTHER, "Google Drive stored {$stored['size']} of {$size} bytes for file {$row['id']}");
        }
        FileModel::setDriveId((int)$row['id'], $stored['id']);
        unlink($path);
    }
}
