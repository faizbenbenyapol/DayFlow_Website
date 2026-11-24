<?php
// =====================================================
// models/ProjectAttachment.php — ไฟล์แนบของงาน = ลิงก์ไปที่ไฟล์ในคลังของคนแนบ (D-4)
//
// ตัวไฟล์ยังเป็นของคนแนบ (files.user_id) คนในโปรเจคเปิดได้ผ่าน endpoint ของงานเท่านั้น
// controller ตรวจสิทธิ์ของโปรเจคมาก่อนเรียกไฟล์นี้
// =====================================================

final class ProjectAttachment
{
    /** ไฟล์แนบได้สูงสุดต่องาน */
    public const MAX_PER_TASK = 20;

    /**
     * ไฟล์แนบของงาน เรียงตามที่แนบ
     *
     * @return array<int, array<string, mixed>> file_id, name, mime_type, file_size, added_by, created_at, added_by_name
     */
    public static function listForTask(int $taskId): array
    {
        return DB::run(
            "SELECT f.id AS file_id, f.name, f.mime_type, f.file_size, a.added_by, a.created_at,
                    COALESCE(NULLIF(u.display_name, ''), u.username, 'ผู้ใช้ที่ลบแล้ว') AS added_by_name
             FROM project_task_files a
             JOIN files f ON f.id = a.file_id
             LEFT JOIN users u ON u.id = a.added_by
             WHERE a.task_id = ?
             ORDER BY a.id ASC",
            [$taskId]
        )->fetchAll();
    }

    /** จำนวนไฟล์แนบของงาน */
    public static function countForTask(int $taskId): int
    {
        return (int)DB::run('SELECT COUNT(*) FROM project_task_files WHERE task_id = ?', [$taskId])->fetchColumn();
    }

    /**
     * จำนวนงานที่ไฟล์นี้แนบอยู่ (เมนูไฟล์เตือนก่อนลบ)
     */
    public static function countForFile(int $fileId): int
    {
        return (int)DB::run('SELECT COUNT(*) FROM project_task_files WHERE file_id = ?', [$fileId])->fetchColumn();
    }

    /**
     * ผูกไฟล์กับงาน ผูกซ้ำไม่เกิดแถวใหม่
     */
    public static function link(int $taskId, int $fileId, int $addedBy): void
    {
        DB::run(
            'INSERT IGNORE INTO project_task_files (task_id, file_id, added_by) VALUES (?, ?, ?)',
            [$taskId, $fileId, $addedBy]
        );
    }

    /**
     * แถวไฟล์ (ครบทุกคอลัมน์ ใช้ส่งเนื้อไฟล์) ที่ผูกกับงานนี้จริง พร้อมคนแนบ
     *
     * @return array<string, mixed>|null แถว files + added_by หรือ null เมื่อไฟล์ไม่ได้ผูกกับงานนี้
     */
    public static function findOnTask(int $taskId, int $fileId): ?array
    {
        return DB::run(
            "SELECT f.*, a.added_by FROM project_task_files a JOIN files f ON f.id = a.file_id
             WHERE a.task_id = ? AND a.file_id = ? AND f.type = 'file'",
            [$taskId, $fileId]
        )->fetch() ?: null;
    }

    /** ถอดไฟล์ออกจากงาน (ตัวไฟล์ในคลังของคนแนบยังอยู่) */
    public static function unlink(int $taskId, int $fileId): void
    {
        DB::run('DELETE FROM project_task_files WHERE task_id = ? AND file_id = ?', [$taskId, $fileId]);
    }

    /**
     * โฟลเดอร์ "ไฟล์แนบ · <ชื่อโปรเจค>" ในคลังของคนแนบ หาเจอใช้ของเดิม ไม่เจอสร้างใหม่ (บนเซิร์ฟเวอร์หรือ Drive ตามที่เขาใช้)
     *
     * @return int id ของโฟลเดอร์
     */
    public static function folderFor(FileStore $store, int $userId, string $projectName): int
    {
        $name = 'ไฟล์แนบ · ' . mb_substr($projectName, 0, 200);
        $id = DB::run(
            "SELECT id FROM files WHERE user_id = ? AND parent_id IS NULL AND type = 'folder' AND name COLLATE utf8mb4_bin = ? LIMIT 1",
            [$userId, $name]
        )->fetchColumn();
        return $id !== false ? (int)$id : $store->createFolder($name, null);
    }
}
