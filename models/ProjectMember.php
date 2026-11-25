<?php
// =====================================================
// models/ProjectMember.php — Collaboration Members Model
// =====================================================

class ProjectMember
{
    /**
     * บทบาทที่แถวสมาชิกถือได้ (เจ้าของมาจาก projects.user_id ไม่ใช่แถวนี้)
     */
    public const MEMBER_ROLES = ['Admin', 'Editor', 'Viewer'];

    /**
     * บทบาทที่ลิงก์แชร์สาธารณะให้ได้ — ลิงก์สาธารณะเป็นผู้ดูแลไม่ได้
     */
    public const SHARE_ROLES = ['Editor', 'Viewer'];

    /**
     * แปลงค่าบทบาทในฐานข้อมูลเป็นชื่อไทยสำหรับบันทึกกิจกรรม
     *
     * รับรหัสบทบาท เช่น Admin และคืนชื่อไทย ผู้ดูแล / สมาชิก / ดูอย่างเดียว
     * ค่าที่ไม่รู้จักคืนตามเดิม ไม่มีผลข้างเคียง
     *
     * @param string $role บทบาทในฐานข้อมูล
     * @return string ชื่อไทย
     */
    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'Owner'  => 'เจ้าของ',
            'Admin'  => 'ผู้ดูแล',
            'Editor' => 'สมาชิก',
            'Viewer' => 'ดูอย่างเดียว',
            default  => $role,
        };
    }

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
     * ดึงรายชื่อสมาชิกทั้งหมดในโครงการ (ชื่อ รูป และบทบาท)
     *
     * จงใจไม่คืนอีเมล เพราะรายการนี้ถูกอ่านโดยสมาชิกทุกคนและแขกที่เปิดลิงก์แชร์
     * ส่วนหน้าจอไม่เคยแสดงอีเมลอยู่แล้ว
     */
    public static function getMembers(int $projectId): array
    {
        // ดึงตัวเจ้าของโครงการก่อน
        $owner = DB::run(
            'SELECT p.user_id AS id, u.username, u.display_name, u.avatar_path, "Owner" AS role
             FROM projects p
             JOIN users u ON p.user_id = u.id
             WHERE p.id = ?',
            [$projectId]
        )->fetch();

        // ดึงผู้ร่วมงานคนอื่นๆ
        $members = DB::run(
            'SELECT pm.user_id AS id, u.username, u.display_name, u.avatar_path, pm.role, pm.created_at
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
     * เปลี่ยนบทบาทของสมาชิกในโครงการ
     *
     * รับรหัสโปรเจค รหัสผู้ใช้ และบทบาทใหม่ แล้วอัปเดตแถวสมาชิกของโปรเจคนี้
     * คืน true เมื่อเจอแถว (แม้ค่าเดิม) และ false เมื่อไม่ใช่สมาชิกของโปรเจคนี้
     */
    public static function updateRole(int $projectId, int $userId, string $role): bool
    {
        return DB::run(
            'UPDATE project_members SET role = ? WHERE project_id = ? AND user_id = ?',
            [$role, $projectId, $userId]
        )->rowCount() > 0;
    }

    /**
     * ลบสมาชิกออกจากโครงการ
     *
     * งานโปรเจคใดที่มอบหมายให้สมาชิกคนนี้จะถูกปลดผู้รับผิดชอบในการเรียกเดียวกัน
     * และคงเวลาแก้ไขล่าสุดของงานไว้ (updated_at = updated_at) เพราะผู้ใช้ไม่ได้แก้งาน
     */
    public static function removeMember(int $projectId, int $userId): bool
    {
        $db = DB::conn();
        $db->beginTransaction();

        try {
            DB::run(
                'UPDATE project_tasks SET assignee_user_id = NULL, assignee = NULL, updated_at = updated_at
                 WHERE project_id = ? AND assignee_user_id = ?',
                [$projectId, $userId]
            );

            $deleted = DB::run(
                'DELETE FROM project_members WHERE project_id = ? AND user_id = ?',
                [$projectId, $userId]
            )->rowCount();

            $db->commit();
            return $deleted > 0;
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('removeMember failed: ' . $e->getMessage());
            return false;
        }
    }
}
