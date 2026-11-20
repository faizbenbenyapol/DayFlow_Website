<?php
// =====================================================
// models/ProjectTask.php — Kanban Task Model
// =====================================================

class ProjectTask
{
    /**
     * ดึงข้อมูลงานทั้งหมดในโครงการ จัดกลุ่มและเรียงตามตำแหน่งการลากวาง (สำหรับผู้ใช้ร่วมทีมทุกคน รวมถึงแขกสาธารณะ)
     *
     * แถวที่ได้มี assignee_name = ชื่อที่แสดงของผู้ใช้ที่ผูกไว้ หรือ username ถ้าไม่มีชื่อที่แสดง
     * หรือข้อความ assignee เดิมถ้ายังไม่ได้ผูกบัญชี
     */
    public static function getByProject(int $projectId): array
    {
        return DB::run(
            'SELECT pt.*,
                    COALESCE(NULLIF(u.display_name, \'\'), u.username, pt.guest_name) AS creator_name,
                    COALESCE(NULLIF(au.display_name, \'\'), au.username, pt.assignee) AS assignee_name
             FROM project_tasks pt
             LEFT JOIN users u ON pt.user_id = u.id
             LEFT JOIN users au ON pt.assignee_user_id = au.id
             WHERE pt.project_id = ?
             ORDER BY pt.position ASC, pt.id ASC',
            [$projectId]
        )->fetchAll();
    }

    /**
     * ดึงงานตามรหัสไอดี
     */
    public static function getById(int $id): ?array
    {
        return DB::run(
            'SELECT * FROM project_tasks WHERE id = ?',
            [$id]
        )->fetch() ?: null;
    }

    /**
     * สร้างงานใหม่ในโครงการ (รองรับผู้ใช้ทั่วไป และ Guest)
     *
     * ถ้ามี assignee_user_id จะบันทึกผู้ใช้ที่ผูกไว้และล้างข้อความ assignee เดิม
     * ถ้าไม่มีจะเก็บข้อความ assignee ตามพฤติกรรมเดิมของ client เก่า
     */
    public static function create(int $userId, int $projectId, array $data): int
    {
        // คำนวณตำแหน่งลำดับสูงสุดในคอลัมน์นั้นๆ ของโปรเจค
        $status = $data['status'] ?? 'To Do';
        $maxPos = (int)DB::run(
            'SELECT COALESCE(MAX(position), -1) FROM project_tasks 
             WHERE project_id = ? AND status = ?',
            [$projectId, $status]
        )->fetchColumn();

        $insertUserId = ($userId > 0) ? $userId : null;
        $guestName = !empty($_SESSION['guest_name']) ? $_SESSION['guest_name'] : null;
        $assigneeUserId = !empty($data['assignee_user_id']) ? (int)$data['assignee_user_id'] : null;
        $assignee = ($assigneeUserId !== null) ? null : (!empty($data['assignee']) ? $data['assignee'] : null);

        DB::run(
            'INSERT INTO project_tasks (project_id, user_id, guest_name, title, status, priority, due_date, category, assignee_user_id, assignee, position, checklist)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $projectId,
                $insertUserId,
                $guestName,
                $data['title'],
                $status,
                $data['priority'] ?? 'Medium',
                !empty($data['due_date']) ? $data['due_date'] : null,
                !empty($data['category']) ? $data['category'] : null,
                $assigneeUserId,
                $assignee,
                $maxPos + 1,
                !empty($data['checklist']) ? json_encode($data['checklist'], JSON_UNESCAPED_UNICODE) : null
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    /**
     * อัปเดตงาน
     *
     * รองรับทั้ง assignee_user_id ใหม่และ assignee ข้อความเดิม
     * assignee_user_id ที่ว่าง/ศูนย์/null หมายถึงยังไม่ระบุ และจะล้าง assignee ข้อความด้วย
     */
    public static function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowed = ['title', 'status', 'priority', 'due_date', 'category', 'assignee_user_id', 'assignee', 'position', 'checklist'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`$field` = ?";
                if (($field === 'due_date' || $field === 'assignee_user_id' || $field === 'assignee') && empty($data[$field])) {
                    $params[] = null;
                } elseif ($field === 'checklist') {
                    $params[] = is_array($data[$field]) ? json_encode($data[$field], JSON_UNESCAPED_UNICODE) : $data[$field];
                } else {
                    $params[] = $data[$field];
                }
            }
        }

        if (empty($fields)) return false;

        $params[] = $id;

        return DB::run(
            'UPDATE project_tasks SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        )->rowCount() > 0;
    }

    /**
     * ลบงานย่อย
     */
    public static function delete(int $id): bool
    {
        return DB::run(
            'DELETE FROM project_tasks WHERE id = ?',
            [$id]
        )->rowCount() > 0;
    }

    /**
     * เรียงลำดับตำแหน่งงานใหม่จากการลากวาง (Kanban Reorder Batch)
     */
    public static function reorder(int $projectId, array $items): void
    {
        $db = DB::conn();
        // เตรียมอัปเดตทั้งสถานะ (Status) และตำแหน่งจัดเรียง (Position)
        $stmt = $db->prepare('UPDATE project_tasks SET status = ?, position = ? WHERE id = ? AND project_id = ?');
        foreach ($items as $item) {
            $stmt->execute([
                $item['status'],
                (int)$item['position'],
                (int)$item['id'],
                $projectId
            ]);
        }
    }
}
