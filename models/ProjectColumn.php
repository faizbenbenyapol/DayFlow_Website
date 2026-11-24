<?php
// =====================================================
// models/ProjectColumn.php — คอลัมน์บอร์ดของแต่ละโปรเจค
//
// column_id คือความจริงเดียวของงาน ส่วนสถานะเดิม (status) เป็นเงาที่
// ProjectTask เขียนจากกลุ่มของคอลัมน์ ไฟล์นี้จึงดูแลเฉพาะตัวคอลัมน์
// และเรียก ProjectTask เมื่อต้องย้าย/ปรับสถานะงาน
// =====================================================

class ProjectColumn
{
    /**
     * คอลัมน์ตั้งต้นตามประเภทโปรเจค เป็นรายการเดียวใน PHP
     * แต่ละรายการคือ [ชื่อ, กลุ่ม] เรียงตามลำดับที่ควรแสดง
     */
    public const DEFAULTS = [
        'general' => [['ต้องทำ', 'todo'], ['กำลังทำ', 'doing'], ['รอตรวจ', 'doing'], ['เสร็จแล้ว', 'done']],
        'finance' => [['ต้องทำ', 'todo'], ['กำลังทำ', 'doing'], ['รอตรวจ', 'doing'], ['เสร็จแล้ว', 'done']],
        'software' => [['ต้องทำ', 'todo'], ['กำลังทำ', 'doing'], ['รอรีวิว', 'doing'], ['ทดสอบ', 'doing'], ['เสร็จ', 'done']],
        'marketing' => [['ไอเดีย', 'todo'], ['ร่าง', 'doing'], ['รอตรวจ', 'doing'], ['นัดโพสต์', 'doing'], ['โพสต์แล้ว', 'done']],
    ];

    /** กลุ่มของสถานะเดิมที่ client เก่ายังส่งมา */
    public const LEGACY_STATUS_GROUPS = [
        'To Do' => 'todo',
        'In Progress' => 'doing',
        'Review' => 'doing',
        'Done' => 'done',
    ];

    /** ชุดคำสั่งเรียงคอลัมน์แบบบอร์ด: กลุ่มก่อน แล้วจึงตำแหน่ง */
    public const ORDER_SQL = "ORDER BY FIELD(grp, 'todo', 'doing', 'done'), position, id";

    /**
     * สร้างคอลัมน์ตั้งต้นของโปรเจคใหม่
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $type ประเภทโปรเจค (general/software/marketing/finance)
     */
    public static function createDefaults(int $projectId, string $type): void
    {
        $defaults = self::DEFAULTS[$type] ?? self::DEFAULTS['general'];
        $values = [];
        $params = [];
        foreach ($defaults as $position => [$name, $grp]) {
            $values[] = '(?, ?, ?, ?)';
            array_push($params, $projectId, $name, $grp, $position);
        }
        DB::run('INSERT INTO project_columns (project_id, name, grp, position) VALUES ' . implode(', ', $values), $params);
    }

    /**
     * ดึงคอลัมน์ทั้งหมดของโปรเจคตามลำดับบอร์ด
     *
     * @param int $projectId รหัสโปรเจค
     * @return array<int, array<string, mixed>> คอลัมน์ (id, name, grp, position)
     */
    public static function listForProject(int $projectId): array
    {
        return DB::run(
            'SELECT id, name, grp, position FROM project_columns WHERE project_id = ? ' . self::ORDER_SQL,
            [$projectId]
        )->fetchAll();
    }

    /**
     * หาคอลัมน์ที่อยู่ในโปรเจคที่ระบุ
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $columnId รหัสคอลัมน์
     * @return array<string, mixed>|null คอลัมน์ หรือ null เมื่อไม่ใช่ของโปรเจคนี้
     */
    public static function findInProject(int $projectId, int $columnId): ?array
    {
        return DB::run(
            'SELECT id, name, grp, position FROM project_columns WHERE id = ? AND project_id = ?',
            [$columnId, $projectId]
        )->fetch() ?: null;
    }

    /**
     * หาคอลัมน์ตามรหัส โดยยังไม่ตรวจสิทธิ์ของโปรเจค
     *
     * @param int $columnId รหัสคอลัมน์
     * @return array<string, mixed>|null คอลัมน์ หรือ null เมื่อไม่พบ
     */
    public static function find(int $columnId): ?array
    {
        return DB::run(
            'SELECT id, project_id, name, grp, position FROM project_columns WHERE id = ?',
            [$columnId]
        )->fetch() ?: null;
    }

    /**
     * แปลงสถานะเดิมของ client เก่าเป็นคอลัมน์ของโปรเจค
     *
     * To Do = คอลัมน์แรกของ todo, In Progress = คอลัมน์แรกของ doing,
     * Review = คอลัมน์สุดท้ายของ doing, Done = คอลัมน์แรกของ done
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $status สถานะเดิม
     * @return array<string, mixed>|null คอลัมน์ หรือ null เมื่อสถานะไม่รู้จัก/ไม่มีคอลัมน์ในกลุ่ม
     */
    public static function forLegacyStatus(int $projectId, string $status): ?array
    {
        $grp = self::LEGACY_STATUS_GROUPS[$status] ?? null;
        if ($grp === null) return null;
        return self::pickGroupColumn(self::listForProject($projectId), $grp, $status === 'Review');
    }

    /**
     * เลือกคอลัมน์แรกหรือสุดท้ายของกลุ่มจากรายการที่โหลดไว้แล้ว
     *
     * @param array<int, array<string, mixed>> $columns รายการคอลัมน์ตามลำดับบอร์ด
     * @param string $grp กลุ่มที่ต้องการ
     * @param bool $last true = เอาคอลัมน์สุดท้ายของกลุ่ม
     * @return array<string, mixed>|null คอลัมน์ หรือ null เมื่อกลุ่มว่าง
     */
    public static function pickGroupColumn(array $columns, string $grp, bool $last = false): ?array
    {
        $inGroup = array_values(array_filter(
            $columns,
            static fn(array $column): bool => $column['grp'] === $grp
        ));
        if ($inGroup === []) return null;
        return $last ? $inGroup[count($inGroup) - 1] : $inGroup[0];
    }

    /**
     * ตรวจว่ามีชื่อคอลัมน์ซ้ำในโปรเจคหรือไม่ (เทียบแบบ utf8mb4_bin)
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $name ชื่อที่จะตรวจ
     * @param int $exceptId รหัสคอลัมน์ที่ยกเว้น (ตอนเปลี่ยนชื่อ)
     * @return bool true เมื่อมีชื่อซ้ำ
     */
    public static function nameExists(int $projectId, string $name, int $exceptId = 0): bool
    {
        return (bool)DB::run(
            'SELECT 1 FROM project_columns WHERE project_id = ? AND name COLLATE utf8mb4_bin = ? AND id <> ? LIMIT 1',
            [$projectId, $name, $exceptId]
        )->fetchColumn();
    }

    /**
     * เพิ่มคอลัมน์ใหม่ต่อท้ายกลุ่มของมัน
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $name ชื่อคอลัมน์ (ผ่านการตรวจแล้ว)
     * @param string $grp กลุ่ม todo/doing/done
     * @return int รหัสคอลัมน์ใหม่
     */
    public static function create(int $projectId, string $name, string $grp): int
    {
        $position = self::maxPosition($projectId, $grp) + 1;
        DB::run(
            'INSERT INTO project_columns (project_id, name, grp, position) VALUES (?, ?, ?, ?)',
            [$projectId, $name, $grp, $position]
        );
        $columnId = (int)DB::conn()->lastInsertId();
        // คอลัมน์ doing สุดท้ายอาจเปลี่ยน จึงคำนวณสถานะเงาของงานทั้งโปรเจคใหม่
        ProjectTask::syncProjectShadow($projectId);
        return $columnId;
    }

    /**
     * เปลี่ยนชื่อคอลัมน์
     *
     * @param int $columnId รหัสคอลัมน์
     * @param string $name ชื่อใหม่ (ผ่านการตรวจแล้ว)
     */
    public static function rename(int $columnId, string $name): void
    {
        DB::run('UPDATE project_columns SET name = ? WHERE id = ?', [$name, $columnId]);
    }

    /**
     * ย้ายคอลัมน์ไปต่อท้ายกลุ่มใหม่ และปรับสถานะเงาของงานในคอลัมน์
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $columnId รหัสคอลัมน์
     * @param string $grp กลุ่มใหม่
     */
    public static function regroup(int $projectId, int $columnId, string $grp): void
    {
        $db = DB::conn();
        $db->beginTransaction();
        try {
            DB::run(
                'UPDATE project_columns SET grp = ?, position = ? WHERE id = ? AND project_id = ?',
                [$grp, self::maxPosition($projectId, $grp) + 1, $columnId, $projectId]
            );
            ProjectTask::syncProjectShadow($projectId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * ตั้งลำดับคอลัมน์ในกลุ่มตามรายการที่ส่งมา (ตำแหน่ง 0..n-1)
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $grp กลุ่มที่จัดลำดับ
     * @param array<int, int|string> $ids รหัสคอลัมน์ตามลำดับใหม่
     */
    public static function reorderGroup(int $projectId, string $grp, array $ids): void
    {
        $values = [];
        $params = [];
        foreach (array_values($ids) as $position => $id) {
            $values[] = 'SELECT ? AS id, ? AS position';
            array_push($params, (int)$id, $position);
        }
        $params[] = $projectId;
        $params[] = $grp;
        DB::run(
            'UPDATE project_columns c
             JOIN (' . implode(' UNION ALL ', $values) . ') v ON v.id = c.id
             SET c.position = v.position
             WHERE c.project_id = ? AND c.grp = ?',
            $params
        );
        // ลำดับในกลุ่ม doing เปลี่ยน คอลัมน์สุดท้ายอาจเปลี่ยนตาม
        ProjectTask::syncProjectShadow($projectId);
    }

    /**
     * ลบคอลัมน์ โดยย้ายงานไปคอลัมน์อื่นก่อนถ้ามีงานอยู่
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $columnId รหัสคอลัมน์ที่จะลบ
     * @param int|null $moveToId คอลัมน์ปลายทางของงาน หรือ null เมื่อไม่มีงาน
     */
    public static function delete(int $projectId, int $columnId, ?int $moveToId): void
    {
        $db = DB::conn();
        $db->beginTransaction();
        try {
            if ($moveToId !== null) {
                ProjectTask::moveColumnTasks($projectId, $columnId, $moveToId);
            }
            DB::run('DELETE FROM project_columns WHERE id = ? AND project_id = ?', [$columnId, $projectId]);
            // ลบคอลัมน์ doing สุดท้ายอาจทำให้คอลัมน์ก่อนหน้าเป็นคอลัมน์สุดท้ายแทน
            ProjectTask::syncProjectShadow($projectId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * หาตำแหน่งสูงสุดของกลุ่ม (คืน -1 เมื่อกลุ่มยังว่าง)
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $grp กลุ่ม
     * @return int ตำแหน่งสูงสุด
     */
    private static function maxPosition(int $projectId, string $grp): int
    {
        return (int)DB::run(
            'SELECT COALESCE(MAX(position), -1) FROM project_columns WHERE project_id = ? AND grp = ?',
            [$projectId, $grp]
        )->fetchColumn();
    }
}
