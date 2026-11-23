<?php
// =====================================================
// models/TaskSubtask.php
// =====================================================

class TaskSubtask
{
    /**
     * คืนรายการงานย่อยของงานหนึ่งใบ เรียงตามลำดับที่บันทึกไว้
     *
     * @param int $taskId รหัสงานหลัก
     * @param int $userId รหัสผู้ใช้
     * @return array<int, array<string, mixed>> แถวงานย่อย
     */
    public static function listForTask(int $taskId, int $userId): array
    {
        return DB::run(
            'SELECT id, task_id, user_id, title, is_done, position, created_at
             FROM task_subtasks
             WHERE task_id = ? AND user_id = ?
             ORDER BY position ASC, id ASC',
            [$taskId, $userId]
        )->fetchAll();
    }

    /**
     * นับจำนวนงานย่อยของงานหนึ่งใบ
     *
     * @param int $taskId รหัสงานหลัก
     * @param int $userId รหัสผู้ใช้
     * @return int จำนวนงานย่อย
     */
    public static function countForTask(int $taskId, int $userId): int
    {
        return (int)DB::run(
            'SELECT COUNT(*) FROM task_subtasks WHERE task_id = ? AND user_id = ?',
            [$taskId, $userId]
        )->fetchColumn();
    }

    /**
     * ค้นหางานย่อยหนึ่งรายการของผู้ใช้ คืน null ถ้าไม่พบหรือไม่ใช่ของผู้ใช้
     *
     * @param int $id รหัสงานย่อย
     * @param int $userId รหัสผู้ใช้
     * @return array<string, mixed>|null แถวงานย่อย
     */
    public static function find(int $id, int $userId): ?array
    {
        return DB::run('SELECT * FROM task_subtasks WHERE id = ? AND user_id = ?', [$id, $userId])->fetch() ?: null;
    }

    /**
     * สร้างงานย่อยใหม่ต่อท้ายรายการเดิมของงาน จำกัดไม่เกิน 100 รายการต่องาน
     *
     * @param int $taskId รหัสงานหลัก
     * @param int $userId รหัสผู้ใช้
     * @param string $title ชื่องานย่อย
     * @return array<string, mixed>|null แถวที่สร้าง
     */
    public static function create(int $taskId, int $userId, string $title): ?array
    {
        $maxPos = (int)DB::run(
            'SELECT COALESCE(MAX(position), -1) FROM task_subtasks WHERE task_id = ? AND user_id = ?',
            [$taskId, $userId]
        )->fetchColumn();

        DB::run(
            'INSERT INTO task_subtasks (task_id, user_id, title, is_done, position)
             VALUES (?, ?, ?, 0, ?)',
            [$taskId, $userId, $title, $maxPos + 1]
        );

        $id = (int)DB::conn()->lastInsertId();
        return self::find($id, $userId);
    }

    /**
     * อัปเดตชื่อหรือสถานะของงานย่อย คืนแถวใหม่หรือ null ถ้าไม่ใช่ของผู้ใช้
     *
     * @param int $id รหัสงานย่อย
     * @param int $userId รหัสผู้ใช้
     * @param array<string, mixed> $data ข้อมูลที่ต้องการอัปเดต (title, is_done)
     * @return array<string, mixed>|null แถวที่อัปเดต
     */
    public static function update(int $id, int $userId, array $data): ?array
    {
        $fields = [];
        $params = [];

        if (array_key_exists('title', $data)) {
            $fields[] = 'title = ?';
            $params[] = $data['title'];
        }
        if (array_key_exists('is_done', $data)) {
            $fields[] = 'is_done = ?';
            $params[] = (int)(bool)$data['is_done'];
        }

        if ($fields === []) {
            return self::find($id, $userId);
        }

        $params[] = $id;
        $params[] = $userId;

        $stmt = DB::run(
            'UPDATE task_subtasks SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?',
            $params
        );

        if ($stmt->rowCount() === 0) return null;
        return self::find($id, $userId);
    }

    /**
     * ลบงานย่อย คืน true เมื่อพบและเป็นของผู้ใช้
     *
     * @param int $id รหัสงานย่อย
     * @param int $userId รหัสผู้ใช้
     * @return bool ลบสำเร็จหรือไม่
     */
    public static function delete(int $id, int $userId): bool
    {
        $stmt = DB::run('DELETE FROM task_subtasks WHERE id = ? AND user_id = ?', [$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * จัดลำดับงานย่อยตาม array ids ที่ส่งมา โดยข้าม id ที่ไม่ใช่ของผู้ใช้หรือไม่ใช่ของงานนี้
     *
     * @param int $taskId รหัสงานหลัก
     * @param int $userId รหัสผู้ใช้
     * @param array<int, int> $ids ลำดับ id ที่ต้องการ
     */
    public static function reorder(int $taskId, int $userId, array $ids): void
    {
        if ($ids === []) return;

        $db = DB::conn();
        $stmt = $db->prepare(
            'UPDATE task_subtasks SET position = ?
             WHERE id = ? AND task_id = ? AND user_id = ?'
        );

        foreach ($ids as $position => $id) {
            $stmt->execute([(int)$position, (int)$id, $taskId, $userId]);
        }
    }
}
