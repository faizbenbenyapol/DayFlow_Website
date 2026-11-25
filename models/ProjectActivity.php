<?php
// =====================================================
// models/ProjectActivity.php — Activity Logging Model
// =====================================================

class ProjectActivity
{
    /**
     * บันทึกกิจกรรมใหม่ลงในฐานข้อมูล
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $userId รหัสผู้ใช้ (<=0 หมายถึงแขก)
     * @param string $action ข้อความกิจกรรม
     * @param int|null $taskId รหัสงาน หรือ null สำหรับกิจกรรมระดับโปรเจค
     * @param bool $private true = บรรทัดลับที่แขกจากลิงก์แชร์ไม่ควรเห็น (D-5)
     */
    public static function log(int $projectId, int $userId, string $action, ?int $taskId = null, bool $private = false): bool
    {
        // action เป็น VARCHAR(255); กันชื่องานยาวทำให้บันทึกล้ม
        $action = mb_substr($action, 0, 255);
        $insertUserId = ($userId > 0) ? $userId : null;
        $guestName = !empty($_SESSION['guest_name']) ? $_SESSION['guest_name'] : null;

        // task_id ทำให้บรรทัดนี้ขึ้นในแท็บประวัติของงานนั้นด้วย (ไม่ใส่ = กิจกรรมระดับโปรเจค)
        return DB::run(
            'INSERT INTO project_activities (project_id, task_id, user_id, guest_name, action, `private`) VALUES (?, ?, ?, ?, ?, ?)',
            [$projectId, $taskId, $insertUserId, $guestName, $action, $private ? 1 : 0]
        )->rowCount() > 0;
    }

    /**
     * ประวัติการเปลี่ยนของงานหนึ่งงาน ใหม่สุดก่อน
     *
     * @param int $taskId รหัสงาน (ผู้เรียกตรวจสิทธิ์ของโปรเจคมาแล้ว)
     * @param int $limit จำนวนบรรทัดสูงสุด
     * @param bool $withPrivate true = รวมบรรทัดลับ; false = ซ่อนบรรทัดลับสำหรับแขกที่เปิดลิงก์แชร์ (D-5)
     * @return array<int, array<string, mixed>> id, action, created_at, display_name
     */
    public static function forTask(int $taskId, int $limit = 50, bool $withPrivate = true): array
    {
        $sql = "SELECT pa.id, pa.action, pa.created_at, COALESCE(NULLIF(u.display_name, ''), u.username, pa.guest_name) AS display_name
             FROM project_activities pa
             LEFT JOIN users u ON pa.user_id = u.id
             WHERE pa.task_id = ?"
             . ($withPrivate ? '' : ' AND pa.private = 0')
             . " ORDER BY pa.id DESC LIMIT " . max(1, $limit);

        return DB::run($sql, [$taskId])->fetchAll();
    }

    /**
     * ดึงข้อมูลประวัติกิจกรรมล่าสุดของโครงการ (รวมทุกสมาชิกพร้อมดึงชื่อแสดงผล)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $limit จำนวนบรรทัดสูงสุด
     * @param bool $withPrivate true = รวมบรรทัดลับ; false = ซ่อนบรรทัดลับสำหรับแขกที่เปิดลิงก์แชร์ (D-5)
     * @return array<int, array<string, mixed>>
     */
    public static function getRecent(int $projectId, int $limit = 10, bool $withPrivate = true): array
    {
        $sql = 'SELECT pa.*, COALESCE(u.display_name, pa.guest_name) AS display_name
             FROM project_activities pa
             LEFT JOIN users u ON pa.user_id = u.id
             WHERE pa.project_id = ?'
             . ($withPrivate ? '' : ' AND pa.private = 0')
             . ' ORDER BY pa.id DESC LIMIT ?';

        return DB::run($sql, [$projectId, $limit])->fetchAll();
    }
}
