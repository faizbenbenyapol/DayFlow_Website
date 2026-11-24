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
     * สร้างงานใหม่ในโครงการในคอลัมน์ที่เลือกไว้แล้ว (รองรับผู้ใช้ทั่วไป และ Guest)
     *
     * ตำแหน่งคือต่อท้ายคอลัมน์นั้น ส่วนสถานะเดิมถูกคำนวณจากคอลัมน์ด้วย
     * นิพจน์เดียวกับ moveTo() (เป็นฟังก์ชันของคอลัมน์ ไม่ขึ้นกับสถานะเดิม)
     *
     * @param int $userId ผู้สร้าง (0 = guest)
     * @param int $projectId รหัสโปรเจค
     * @param array<string, mixed> $column คอลัมน์ที่ตรวจแล้ว (ต้องมี id)
     * @param array<string, mixed> $data ข้อมูลงาน
     * @return int รหัสงานใหม่
     */
    public static function create(int $userId, int $projectId, array $column, array $data): int
    {
        $columnId = (int)$column['id'];
        return (int)ProjectColumn::locked($projectId, static function () use ($userId, $projectId, $columnId, $data): int {
            // อ่านคอลัมน์ซ้ำหลังได้ lock: ถ้าคำขอลบชนะไปแล้ว ต้องตอบ 404 ไม่ใช่
            // ปล่อยให้ FK ON DELETE SET NULL ทำให้งานลอยอย่างไร้คอลัมน์
            if (!ProjectColumn::findInProject($projectId, $columnId)) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }

            $maxPos = (int)DB::run(
                'SELECT COALESCE(MAX(position), -1) FROM project_tasks
                 WHERE project_id = ? AND column_id = ?',
                [$projectId, $columnId]
            )->fetchColumn();

            $insertUserId = ($userId > 0) ? $userId : null;
            $guestName = !empty($_SESSION['guest_name']) ? $_SESSION['guest_name'] : null;
            $assigneeUserId = !empty($data['assignee_user_id']) ? (int)$data['assignee_user_id'] : null;
            $assignee = ($assigneeUserId !== null) ? null : (!empty($data['assignee']) ? $data['assignee'] : null);

            $insert = DB::run(
                'INSERT INTO project_tasks (project_id, user_id, guest_name, title, status, priority, due_date, category, assignee_user_id, assignee, column_id, position, checklist)
                 SELECT ?, ?, ?, ?, ' . self::shadowStatusSql() . ', ?, ?, ?, ?, ?, c.id, ?, ?
                 FROM project_columns c
                 WHERE c.id = ? AND c.project_id = ?',
                [
                    $projectId,
                    $insertUserId,
                    $guestName,
                    $data['title'],
                    $data['priority'] ?? 'Medium',
                    !empty($data['due_date']) ? $data['due_date'] : null,
                    !empty($data['category']) ? $data['category'] : null,
                    $assigneeUserId,
                    $assignee,
                    $maxPos + 1,
                    !empty($data['checklist']) ? json_encode($data['checklist'], JSON_UNESCAPED_UNICODE) : null,
                    $columnId,
                    $projectId
                ]
            );
            // คอลัมน์หายไปจริง (เช่นถูกลบหลังอ่านครั้งแรก) → INSERT…SELECT ใส่ 0 แถว
            if ($insert->rowCount() < 1) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }
            return (int)DB::conn()->lastInsertId();
        });
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

        // สถานะและตำแหน่งเขียนผ่าน moveTo()/reorder() เท่านั้น
        $allowed = ['title', 'description', 'priority', 'due_date', 'category', 'assignee_user_id', 'assignee', 'checklist'];
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
     * ย้ายงานไปต่อท้ายคอลัมน์ที่ระบุ พร้อมเขียนสถานะเงาให้ตรงกลุ่ม
     *
     * @param int $taskId รหัสงาน
     * @param int $projectId รหัสโปรเจค
     * @param array<string, mixed> $column คอลัมน์ปลายทางที่ตรวจแล้ว (ต้องมี id)
     */
    public static function moveTo(int $taskId, int $projectId, array $column): void
    {
        $columnId = (int)$column['id'];
        ProjectColumn::locked($projectId, static function () use ($taskId, $projectId, $columnId): void {
            // คอลัมน์ปลายทางต้องยังอยู่ในโปรเจคนี้ตอนได้ lock
            if (!ProjectColumn::findInProject($projectId, $columnId)) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }

            $maxPos = (int)DB::run(
                'SELECT COALESCE(MAX(position), -1) FROM project_tasks
                 WHERE project_id = ? AND column_id = ? AND id <> ?',
                [$projectId, $columnId, $taskId]
            )->fetchColumn();

            DB::run(
                'UPDATE project_tasks t
                 JOIN project_columns c ON c.id = ? AND c.project_id = t.project_id
                 SET t.column_id = c.id, t.position = ?, t.status = ' . self::shadowStatusSql() . '
                 WHERE t.id = ? AND t.project_id = ?',
                [$columnId, $maxPos + 1, $taskId, $projectId]
            );
        });
    }

    /**
     * เรียงลำดับตำแหน่งและคอลัมน์ของงานใหม่จากการลากวาง ในคำสั่ง UPDATE เดียว
     *
     * @param int $projectId รหัสโปรเจค
     * @param array<int, array<string, mixed>> $items รายการ [{id, column_id, position}]
     */
    public static function reorder(int $projectId, array $items): void
    {
        if ($items === []) return;

        ProjectColumn::locked($projectId, static function () use ($projectId, $items): void {
            // อ่านคอลัมน์ของโปรเจคซ้ำหลังได้ lock: รายการที่ client ถือมาอาจชี้
            // คอลัมน์ที่เพิ่งถูกลบไปแล้ว
            $validColumns = [];
            foreach (ProjectColumn::listForProject($projectId) as $column) {
                $validColumns[(int)$column['id']] = true;
            }
            foreach ($items as $item) {
                if (!isset($validColumns[(int)$item['column_id']])) {
                    throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
                }
            }

            $values = [];
            $params = [];
            foreach ($items as $item) {
                $values[] = 'SELECT ? AS id, ? AS column_id, ? AS position';
                array_push($params, (int)$item['id'], (int)$item['column_id'], (int)$item['position']);
            }
            $params[] = $projectId;

            DB::run(
                'UPDATE project_tasks t
                 JOIN (' . implode(' UNION ALL ', $values) . ') v ON v.id = t.id
                 JOIN project_columns c ON c.id = v.column_id AND c.project_id = t.project_id
                 SET t.column_id = v.column_id, t.position = v.position, t.status = ' . self::shadowStatusSql() . '
                 WHERE t.project_id = ?',
                $params
            );
        });
    }

    /**
     * ย้ายงานทุกงานของคอลัมน์หนึ่งไปต่อท้ายอีกคอลัมน์ โดยคงลำดับเดิม
     * และไม่แตะเวลาแก้ไขล่าสุดของผู้ใช้ (ใช้ตอนลบคอลัมน์)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $fromColumnId คอลัมน์ต้นทาง
     * @param int $toColumnId คอลัมน์ปลายทาง
     */
    public static function moveColumnTasks(int $projectId, int $fromColumnId, int $toColumnId): void
    {
        ProjectColumn::locked($projectId, static function () use ($projectId, $fromColumnId, $toColumnId): void {
            // ทั้งต้นทางและปลายทางต้องเป็นคอลัมน์ของโปรเจคนี้ตอนได้ lock
            if (!ProjectColumn::findInProject($projectId, $fromColumnId)
                || !ProjectColumn::findInProject($projectId, $toColumnId)) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }

            $taskIds = DB::run(
                'SELECT id FROM project_tasks WHERE project_id = ? AND column_id = ? ORDER BY position, id',
                [$projectId, $fromColumnId]
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($taskIds === []) return;

            $base = (int)DB::run(
                'SELECT COALESCE(MAX(position), -1) FROM project_tasks WHERE project_id = ? AND column_id = ?',
                [$projectId, $toColumnId]
            )->fetchColumn();

            $values = [];
            $params = [];
            foreach ($taskIds as $index => $taskId) {
                $values[] = 'SELECT ? AS id, ? AS position';
                array_push($params, (int)$taskId, $base + 1 + $index);
            }
            $params[] = $toColumnId;
            $params[] = $projectId;

            DB::run(
                'UPDATE project_tasks t
                 JOIN (' . implode(' UNION ALL ', $values) . ') v ON v.id = t.id
                 JOIN project_columns c ON c.id = ? AND c.project_id = t.project_id
                 SET t.column_id = c.id, t.position = v.position, t.status = ' . self::shadowStatusSql() . ', t.updated_at = t.updated_at
                 WHERE t.project_id = ?',
                $params
            );
        });
    }

    /**
     * คำนวณสถานะเงาใหม่ให้งานทุกงานของโปรเจค (ใช้หลังเพิ่ม/ย้าย/เรียง/ลบคอลัมน์
     * เพราะคอลัมน์ doing สุดท้ายอาจเปลี่ยน) ไม่แตะเวลาแก้ไขล่าสุดของผู้ใช้
     *
     * @param int $projectId รหัสโปรเจค
     */
    public static function syncProjectShadow(int $projectId): void
    {
        DB::run(
            'UPDATE project_tasks t
             JOIN project_columns c ON c.id = t.column_id
             SET t.status = ' . self::shadowStatusSql() . ', t.updated_at = t.updated_at
             WHERE t.project_id = ?',
            [$projectId]
        );
    }

    /**
     * นับจำนวนงานในคอลัมน์ (ใช้ตัดสินว่าต้องเลือกคอลัมน์ปลายทางตอนลบหรือไม่)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $columnId รหัสคอลัมน์
     * @return int จำนวนงาน
     */
    public static function countInColumn(int $projectId, int $columnId): int
    {
        return (int)DB::run(
            'SELECT COUNT(*) FROM project_tasks WHERE project_id = ? AND column_id = ?',
            [$projectId, $columnId]
        )->fetchColumn();
    }

    /**
     * นิพจน์ SQL เดียวที่เขียนสถานะเงา (c = project_columns)
     *
     * done → Done, todo → To Do, doing → Review เฉพาะคอลัมน์ doing สุดท้าย
     * (position มากสุดแล้ว id มากสุด) ของโปรเจค นอกนั้นเป็น In Progress
     *
     * @return string นิพจน์ CASE
     */
    private static function shadowStatusSql(): string
    {
        return "CASE c.grp WHEN 'done' THEN 'Done' WHEN 'todo' THEN 'To Do' ELSE IF(c.id = (SELECT d.id FROM project_columns d WHERE d.project_id = c.project_id AND d.grp = 'doing' ORDER BY d.position DESC, d.id DESC LIMIT 1), 'Review', 'In Progress') END";
    }
}
