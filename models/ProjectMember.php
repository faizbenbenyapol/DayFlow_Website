<?php
// =====================================================
// models/ProjectMember.php — Collaboration Members Model
// =====================================================

class ProjectMember
{
    /**
     * ดึงสิทธิ์/บทบาทของสมาชิกในโปรเจค
     */
    public static function getRole(int $projectId, int $userId): ?string
    {
        // ตรวจสอบว่าก่อนอื่นเป็นเจ้าของโครงการเองหรือไม่
        $project = DB::run('SELECT user_id FROM projects WHERE id = ?', [$projectId])->fetch();
        if ($project && (int)$project['user_id'] === $userId) {
            return 'Owner';
        }

        // หากไม่ใช่ ค้นหาในตารางสมาชิก
        $stmt = DB::run(
            'SELECT role FROM project_members WHERE project_id = ? AND user_id = ?',
            [$projectId, $userId]
        );
        $row = $stmt->fetch();
        return $row ? $row['role'] : null;
    }

    /**
     * ตรวจสอบว่าผู้ใช้มีสิทธิ์เข้าถึงโปรเจคหรือไม่ (เป็นเจ้าของหรือสมาชิก)
     */
    public static function hasAccess(int $projectId, int $userId): bool
    {
        return self::getRole($projectId, $userId) !== null;
    }

    /**
     * ดึงรายชื่อสมาชิกทั้งหมดในโครงการ (รวมรายละเอียดผู้ใช้)
     */
    public static function getMembers(int $projectId): array
    {
        // ดึงตัวเจ้าของโครงการก่อน
        $owner = DB::run(
            'SELECT p.user_id AS id, u.username, u.email, u.display_name, u.avatar_path, "Owner" AS role
             FROM projects p
             JOIN users u ON p.user_id = u.id
             WHERE p.id = ?',
            [$projectId]
        )->fetch();

        // ดึงผู้ร่วมงานคนอื่นๆ
        $members = DB::run(
            'SELECT pm.user_id AS id, u.username, u.email, u.display_name, u.avatar_path, pm.role, pm.created_at
             FROM project_members pm
             JOIN users u ON pm.user_id = u.id
             WHERE pm.project_id = ?
             ORDER BY pm.created_at ASC',
            [$projectId]
        )->fetchAll();

        if ($owner) {
            array_unshift($members, $owner);
        }

        return $members;
    }

    /**
     * เพิ่มสมาชิกใหม่เข้าโครงการ
     */
    public static function addMember(int $projectId, int $userId, string $role = 'Editor'): bool
    {
        // หากผู้ใช้รายนี้มีบทบาทใดๆ อยู่แล้ว ไม่ให้เพิ่มซ้ำ
        if (self::hasAccess($projectId, $userId)) {
            return false;
        }

        return DB::run(
            'INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)',
            [$projectId, $userId, $role]
        )->rowCount() > 0;
    }

    /**
     * ลบสมาชิกออกจากโครงการ
     */
    public static function removeMember(int $projectId, int $userId): bool
    {
        return DB::run(
            'DELETE FROM project_members WHERE project_id = ? AND user_id = ?',
            [$projectId, $userId]
        )->rowCount() > 0;
    }
}
