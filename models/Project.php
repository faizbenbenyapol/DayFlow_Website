<?php
// =====================================================
// models/Project.php — Project Model
// =====================================================

class Project
{
    /**
     * ดึงโครงการทั้งหมดของผู้ใช้ พร้อมสรุปสถิติจำนวนงานย่อย (รวมโปรเจคที่ผู้อื่นแชร์มาด้วย หรือบอร์ดสาธารณะของแขก)
     */
    public static function getAll(int $userId): array
    {
        // กรณีเป็นผู้เข้าใช้งานลิงก์สาธารณะโดยตรง (ไม่มีรหัสสมาชิกหลัก)
        if (empty($userId) && !empty($_SESSION['active_project_share_token'])) {
            return DB::run(
                'SELECT p.*, 
                        0 AS is_owner,
                        p.share_role AS user_role,
                        COUNT(DISTINCT t.id) AS total_tasks,
                        COUNT(DISTINCT CASE WHEN t.status = "Done" THEN t.id END) AS completed_tasks
                 FROM projects p
                 LEFT JOIN project_tasks t ON p.id = t.project_id
                 WHERE p.share_token = ?
                 GROUP BY p.id',
                [$_SESSION['active_project_share_token']]
            )->fetchAll();
        }

        return DB::run(
            'SELECT p.*, 
                    (p.user_id = ?) AS is_owner,
                    COALESCE(pm.role, "Owner") AS user_role,
                    COUNT(DISTINCT t.id) AS total_tasks,
                    COUNT(DISTINCT CASE WHEN t.status = "Done" THEN t.id END) AS completed_tasks
             FROM projects p
             LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = ?
             LEFT JOIN project_tasks t ON p.id = t.project_id
             WHERE p.user_id = ? OR pm.user_id = ?
             GROUP BY p.id
             ORDER BY 
                CASE p.priority
                    WHEN "Critical" THEN 1
                    WHEN "High" THEN 2
                    WHEN "Medium" THEN 3
                    WHEN "Low" THEN 4
                    ELSE 5
                END ASC,
                p.due_date ASC, p.id DESC',
            [$userId, $userId, $userId, $userId]
        )->fetchAll();
    }

    /**
     * ดึงข้อมูลโครงการเดี่ยวตามไอดี (ที่ผู้ใช้มีสิทธิ์เข้าถึง หรือลิงก์แชร์ของแขก)
     */
    public static function getById(int $id, int $userId): ?array
    {
        // กรณีผู้ใช้งานเป็น Guest ผ่านลิงก์สาธารณะ
        if (empty($userId) && !empty($_SESSION['active_project_share_token'])) {
            return DB::run(
                'SELECT p.*, 
                        0 AS is_owner,
                        p.share_role AS user_role
                 FROM projects p
                 WHERE p.id = ? AND p.share_token = ?',
                [$id, $_SESSION['active_project_share_token']]
            )->fetch() ?: null;
        }

        return DB::run(
            'SELECT p.*, 
                    (p.user_id = ?) AS is_owner,
                    COALESCE(pm.role, "Owner") AS user_role
             FROM projects p
             LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = ?
             WHERE p.id = ? AND (p.user_id = ? OR pm.user_id = ?)',
            [$userId, $userId, $id, $userId, $userId]
        )->fetch() ?: null;
    }

    /**
     * สร้างโครงการใหม่
     */
    public static function create(int $userId, array $data): int
    {
        DB::run(
            'INSERT INTO projects (user_id, name, description, status, priority, due_date)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['name'],
                $data['description'] ?? null,
                $data['status'] ?? 'Planning',
                $data['priority'] ?? 'Medium',
                !empty($data['due_date']) ? $data['due_date'] : null
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    /**
     * อัปเดตโครงการ
     */
    public static function update(int $id, int $userId, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowed = ['name', 'description', 'status', 'priority', 'due_date'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`$field` = ?";
                $params[] = ($field === 'due_date' && empty($data[$field])) ? null : $data[$field];
            }
        }

        if (empty($fields)) return false;

        $params[] = $id;
        $params[] = $userId;

        return DB::run(
            'UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?',
            $params
        )->rowCount() > 0;
    }

    /**
     * ลบโครงการ (เมื่อลบจะลบงานและกิจกรรมย่อยออกด้วย CASCADE)
     */
    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM projects WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }

    /**
     * สร้างข้อความวิเคราะห์อัจฉริยะจำลอง (AI Progress Insight) ตามเปอร์เซ็นต์และสถิติต่างๆ
     */
    public static function getAiInsight(int $projectId, int $userId): array
    {
        $project = self::getById($projectId, $userId);
        if (!$project) {
            return [
                'status' => 'unknown',
                'insight' => 'ไม่พบข้อมูลโครงการสำหรับการวิเคราะห์',
                'warning' => null
            ];
        }

        // ดึงสถิติต่างๆ ของโปรเจคโดยรวมของทุกคน
        $stats = DB::run(
            'SELECT COUNT(id) AS total,
                    SUM(CASE WHEN status = "To Do" THEN 1 ELSE 0 END) AS todo,
                    SUM(CASE WHEN status = "In Progress" THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN status = "Review" THEN 1 ELSE 0 END) AS review,
                    SUM(CASE WHEN status = "Done" THEN 1 ELSE 0 END) AS done,
                    SUM(CASE WHEN priority = "Critical" AND status != "Done" THEN 1 ELSE 0 END) AS open_critical,
                    SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURRENT_DATE() AND status != "Done" THEN 1 ELSE 0 END) AS overdue
              FROM project_tasks
              WHERE project_id = ?',
            [$projectId]
        )->fetch();

        $total = (int)$stats['total'];
        $done = (int)$stats['done'];
        $openCritical = (int)$stats['open_critical'];
        $overdue = (int)$stats['overdue'];

        $percent = $total > 0 ? round(($done / $total) * 100) : 0;
        
        $status = 'healthy';
        $insight = '';
        $warning = null;

        // คำนวณเดดไลน์
        $dueDays = null;
        if ($project['due_date']) {
            $diff = strtotime($project['due_date']) - time();
            $dueDays = ceil($diff / (60 * 60 * 24));
        }

        if ($overdue > 0) {
            $status = 'danger';
            $insight = "พบงานค้างส่งจำนวน {$overdue} รายการ! แนะนำให้รีบปรับแผนงานหรือติดต่อผู้รับผิดชอบทันทีเพื่อแก้ไขปัญหาคอขวดนี้";
            $warning = "มีงานค้างส่งเลยกำหนด (Overdue)!";
        } elseif ($openCritical > 0) {
            $status = 'warning';
            $insight = "โครงการมีงานระดับวิกฤต (Critical Priority) ที่ยังไม่เสร็จสิ้นจำนวน {$openCritical} งาน ควรรีบสะสางเพื่อป้องกันไม่ให้แผนหลักล่าช้า";
            $warning = "พบบันทึกงานระดับวิกฤตค้างอยู่!";
        } elseif ($dueDays !== null && $dueDays < 0 && $project['status'] !== 'Completed') {
            $status = 'danger';
            $insight = "โครงการเลยกำหนดส่งสุดท้ายมาแล้ว! แนะนำให้ประเมินความพร้อมและกดเปลี่ยนสถานะเป็น Completed หรืออัปเดตเดดไลน์ใหม่";
            $warning = "เลยกำหนดเดดไลน์โครงการ!";
        } elseif ($dueDays !== null && $dueDays <= 3 && $project['status'] !== 'Completed' && $percent < 90) {
            $status = 'warning';
            $insight = "ใกล้ถึงกำหนดส่งโครงการในอีก {$dueDays} วันแล้ว แต่ความคืบหน้าของงานเสร็จสิ้นเพียง {$percent}% เท่านั้น แนะนำให้ลดความสำคัญของฟีเจอร์รองลงชั่วคราว";
            $warning = "ใกล้ถึงเดดไลน์โครงการ!";
        } else {
            if ($total === 0) {
                $status = 'info';
                $insight = "โปรเจคเพิ่งเริ่มสร้างและยังไม่มีงานย่อย แนะนำให้เพิ่มงานแรกของคุณในคอลัมน์คัมบังด้านล่างเพื่อเริ่มติดตามการทำงานแบบโปรดิวเซอร์!";
            } elseif ($percent === 100) {
                $status = 'success';
                $insight = "โครงการดำเนินการเสร็จสิ้นสมบูรณ์ 100% สุขภาพงานอยู่ในระดับยอดเยี่ยม! คุณสามารถปิดงานและเปลี่ยนสถานะโครงการเป็น Completed ได้เลย";
            } elseif ($percent >= 75) {
                $status = 'healthy';
                $insight = "ความก้าวหน้าทำได้ถึง {$percent}% แล้ว! ทิศทางโครงการกำลังดำเนินไปอย่างสมบูรณ์แบบ แผนงานส่วนใหญ่จัดเก็บและส่งมอบเรียบร้อยดี";
            } elseif ($percent >= 40) {
                $status = 'healthy';
                $insight = "โครงการดำเนินมาได้ครึ่งทางแล้วความคืบหน้าอยู่ที่ {$percent}% สถิติการลากย้ายบอร์ดคัมบังอยู่ในเกณฑ์ปกติลื่นไหลดี";
            } else {
                $status = 'healthy';
                $insight = "โครงการอยู่ในเฟสเริ่มต้น ความคืบหน้า {$percent}% มีงานอยู่ในมือค่อนข้างราบรื่น แนะนำให้กระตุ้นงานในคอลัมน์ In Progress เพิ่มเติม";
            }
        }

        return [
            'status' => $status,
            'insight' => $insight,
            'warning' => $warning,
            'productivity' => $percent
        ];
    }
}
