<?php
// =====================================================
// models/ProjectActivity.php — Activity Logging Model
// =====================================================

class ProjectActivity
{
    /**
     * บันทึกกิจกรรมใหม่ลงในฐานข้อมูล
     */
    public static function log(int $projectId, int $userId, string $action, ?int $taskId = null): bool
    {
        $insertUserId = ($userId > 0) ? $userId : null;
        $guestName = !empty($_SESSION['guest_name']) ? $_SESSION['guest_name'] : null;

        // task_id ทำให้บรรทัดนี้ขึ้นในแท็บประวัติของงานนั้นด้วย (ไม่ใส่ = กิจกรรมระดับโปรเจค)
        return DB::run(
            'INSERT INTO project_activities (project_id, task_id, user_id, guest_name, action) VALUES (?, ?, ?, ?, ?)',
            [$projectId, $taskId, $insertUserId, $guestName, $action]
        )->rowCount() > 0;
    }

    /**
     * ประวัติการเปลี่ยนของงานหนึ่งงาน ใหม่สุดก่อน
     *
     * @param int $taskId รหัสงาน (ผู้เรียกตรวจสิทธิ์ของโปรเจคมาแล้ว)
     * @param int $limit จำนวนบรรทัดสูงสุด
     * @return array<int, array<string, mixed>> id, action, created_at, display_name
     */
    public static function forTask(int $taskId, int $limit = 50): array
    {
        return DB::run(
            "SELECT pa.id, pa.action, pa.created_at, COALESCE(NULLIF(u.display_name, ''), u.username, pa.guest_name) AS display_name
             FROM project_activities pa
             LEFT JOIN users u ON pa.user_id = u.id
             WHERE pa.task_id = ?
             ORDER BY pa.id DESC LIMIT " . max(1, $limit),
            [$taskId]
        )->fetchAll();
    }

    /**
     * ดึงข้อมูลประวัติกิจกรรมล่าสุดของโครงการ (รวมทุกสมาชิกพร้อมดึงชื่อแสดงผล)
     */
    public static function getRecent(int $projectId, int $limit = 10): array
    {
        return DB::run(
            'SELECT pa.*, COALESCE(u.display_name, pa.guest_name) AS display_name
             FROM project_activities pa
             LEFT JOIN users u ON pa.user_id = u.id
             WHERE pa.project_id = ? 
             ORDER BY pa.id DESC LIMIT ?',
            [$projectId, $limit]
        )->fetchAll();
    }
}
