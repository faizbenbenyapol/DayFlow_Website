<?php
// =====================================================
// models/ProjectActivity.php — Activity Logging Model
// =====================================================

class ProjectActivity
{
    /**
     * บันทึกกิจกรรมใหม่ลงในฐานข้อมูล
     */
    public static function log(int $projectId, int $userId, string $action): bool
    {
        $insertUserId = ($userId > 0) ? $userId : null;
        $guestName = !empty($_SESSION['guest_name']) ? $_SESSION['guest_name'] : null;

        return DB::run(
            'INSERT INTO project_activities (project_id, user_id, guest_name, action) VALUES (?, ?, ?, ?)',
            [$projectId, $insertUserId, $guestName, $action]
        )->rowCount() > 0;
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
