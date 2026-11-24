<?php
// =====================================================
// models/ProjectColumn.php — คอลัมน์บอร์ดของแต่ละโปรเจค
//
// column_id คือความจริงเดียวของงาน ส่วนสถานะเดิม (status) เป็นเงาที่
// ProjectTask เขียนจากกลุ่มของคอลัมน์ ไฟล์นี้จึงดูแลเฉพาะตัวคอลัมน์
// และเรียก ProjectTask เมื่อต้องย้าย/ปรับสถานะงาน
//
// ทุกคำสั่งที่เปลี่ยนโครงสร้างคอลัมน์ (เพิ่ม/เปลี่ยนชื่อ/ย้ายกลุ่ม/เรียง/ลบ)
// ต้องถือ lock ของโปรเจคก่อน แล้วอ่านค่าที่ตัดสินใจอีกครั้งใน transaction
// เดียวกัน จึงไม่มีคำขอสองคำขอที่ทำให้กลุ่มว่างหรือชี้คอลัมน์ที่ถูกลบไปแล้ว
// =====================================================

/**
 * ความผิดพลาดที่ผู้เรียกแก้ได้เอง (422) หรือคอลัมน์/เป้าหมายหายไป (404)
 *
 * model โยนข้อยกเว้นนี้ขณะถือ lock ของโปรเจค แล้ว controller แปลงเป็น JSON
 * ด้วยรหัสและข้อความเดียวกับที่ client เคยได้รับ เพื่อให้ transaction ถูก
 * rollback ก่อนตอบเสมอ
 */
final class ProjectColumnException extends RuntimeException
{
    /**
     * @param int $httpStatus รหัส HTTP ที่ควรตอบ
     * @param string $message ข้อความไทยที่ client เคยเห็น
     */
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

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

    /** จำนวนคอลัมน์สูงสุดต่อโปรเจค */
    public const MAX_COLUMNS = 12;

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
     * ให้งานที่ยังไม่มีคอลัมน์ของโปรเจคได้คอลัมน์ตามกลุ่มของสถานะเดิม แล้วปรับสถานะเงา
     *
     * ใช้ตอนกู้ข้อมูลที่บันทึกก่อนมีคอลัมน์ (snapshot เก่าของบัญชีตัวอย่าง)
     * กฎเดียวกับ forLegacyStatus() และขั้น backfill ของ migration 038
     * งานที่มีคอลัมน์แล้วไม่ถูกแตะ และไม่เปลี่ยนเวลาแก้ไขล่าสุดของผู้ใช้
     *
     * @param int $projectId รหัสโปรเจค (ต้องมีคอลัมน์ครบทุกกลุ่มแล้ว)
     */
    public static function adoptLooseTasks(int $projectId): void
    {
        DB::run(
            "UPDATE project_tasks t
             SET t.column_id = (
                     SELECT c.id FROM project_columns c
                     WHERE c.project_id = t.project_id
                       AND c.grp = CASE t.status WHEN 'To Do' THEN 'todo' WHEN 'Done' THEN 'done' ELSE 'doing' END
                     ORDER BY IF(t.status = 'Review', c.position, 0) DESC,
                              IF(t.status = 'Review', c.id, 0) DESC,
                              c.position, c.id
                     LIMIT 1
                 ),
                 t.updated_at = t.updated_at
             WHERE t.project_id = ? AND t.column_id IS NULL",
            [$projectId]
        );
        ProjectTask::syncProjectShadow($projectId);
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
     * ล็อกแถวโปรเจคเพื่อให้คำขอที่เปลี่ยนโครงสร้างทีละคำขอ
     *
     * ต้องเรียกใน transaction เท่านั้น (locked() ทำให้แล้ว) lock นี้ทำให้
     * คำขอที่แย่งกันลบ/ย้ายคอลัมน์ของโปรเจคเดียวกันเข้าคิวกัน ไม่เห็นค่าเก่า
     *
     * @param int $projectId รหัสโปรเจค
     */
    public static function lockProject(int $projectId): void
    {
        // โปรเจคอาจถูกลบระหว่างรอ lock: ตอบ 404 แบบ id ที่ถูกลบ (EDGE-P4) แทนการ INSERT ชน FK แล้วได้ 500
        if (DB::run('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$projectId])->fetchColumn() === false) {
            throw new ProjectColumnException(404, 'ไม่พบข้อมูลโปรเจค');
        }
    }

    /**
     * รันงานใน transaction ที่ถือ lock ของโปรเจคก่อน แล้วจึงอ่าน/ตรวจค่าใหม่
     *
     * ถ้าผู้เรียกอยู่ใน transaction อยู่แล้ว (เช่น update() เรียก rename() และ
     * regroup()) จะไม่เปิดซ้อน เพราะ PDO เปิดซ้อนไม่ได้ และจะไม่ commit ให้
     * เจ้าของ transaction ชั้นนอกเป็นผู้ตัดสิน
     *
     * @param int $projectId รหัสโปรเจค
     * @param callable $callback งานที่จะทำหลังได้ lock
     * @return mixed ผลลัพธ์ของ callback
     */
    public static function locked(int $projectId, callable $callback): mixed
    {
        $db = DB::conn();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            self::lockProject($projectId);
            $result = $callback();
            if ($ownsTransaction) {
                $db->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * เพิ่มคอลัมน์ใหม่ต่อท้ายกลุ่มของมัน
     *
     * ตรวจจำนวนคอลัมน์และชื่อซ้ำใน lock เดียวกับการเพิ่ม จึงไม่มีคำขออื่น
     * แทรกจนเกิน 12 คอลัมน์หรือชื่อซ้ำระหว่างตรวจกับเขียน
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $name ชื่อคอลัมน์ (ผ่านการตรวจความยาวแล้ว)
     * @param string $grp กลุ่ม todo/doing/done
     * @return int รหัสคอลัมน์ใหม่
     */
    public static function create(int $projectId, string $name, string $grp): int
    {
        return (int)self::locked($projectId, static function () use ($projectId, $name, $grp): int {
            $count = (int)DB::run(
                'SELECT COUNT(*) FROM project_columns WHERE project_id = ?',
                [$projectId]
            )->fetchColumn();
            if ($count >= self::MAX_COLUMNS) {
                throw new ProjectColumnException(422, 'เพิ่มคอลัมน์ได้สูงสุด ' . self::MAX_COLUMNS . ' คอลัมน์ต่อโปรเจค');
            }
            if (self::nameExists($projectId, $name)) {
                throw new ProjectColumnException(422, 'มีชื่อคอลัมน์นี้อยู่แล้ว');
            }

            $position = self::maxPosition($projectId, $grp) + 1;
            DB::run(
                'INSERT INTO project_columns (project_id, name, grp, position) VALUES (?, ?, ?, ?)',
                [$projectId, $name, $grp, $position]
            );
            $columnId = (int)DB::conn()->lastInsertId();
            // คอลัมน์ doing สุดท้ายอาจเปลี่ยน จึงคำนวณสถานะเงาของงานทั้งโปรเจคใหม่
            ProjectTask::syncProjectShadow($projectId);
            return $columnId;
        });
    }

    /**
     * เปลี่ยนชื่อคอลัมน์
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $columnId รหัสคอลัมน์
     * @param string $name ชื่อใหม่ (ผ่านการตรวจความยาวแล้ว)
     */
    public static function rename(int $projectId, int $columnId, string $name): void
    {
        self::locked($projectId, static function () use ($projectId, $columnId, $name): void {
            if (!self::findInProject($projectId, $columnId)) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }
            if (self::nameExists($projectId, $name, $columnId)) {
                throw new ProjectColumnException(422, 'มีชื่อคอลัมน์นี้อยู่แล้ว');
            }
            DB::run(
                'UPDATE project_columns SET name = ? WHERE id = ? AND project_id = ?',
                [$name, $columnId, $projectId]
            );
        });
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
        self::locked($projectId, static function () use ($projectId, $columnId, $grp): void {
            $column = self::findInProject($projectId, $columnId);
            if (!$column) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }
            if ($column['grp'] === $grp) {
                return;
            }

            self::assertGroupKeepsOne($projectId, (string)$column['grp'], $columnId);
            DB::run(
                'UPDATE project_columns SET grp = ?, position = ? WHERE id = ? AND project_id = ?',
                [$grp, self::maxPosition($projectId, $grp) + 1, $columnId, $projectId]
            );
            ProjectTask::syncProjectShadow($projectId);
        });
    }

    /**
     * เปลี่ยนชื่อและ/หรือย้ายกลุ่มของคอลัมน์ใน lock เดียว
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $columnId รหัสคอลัมน์
     * @param string|null $name ชื่อใหม่ หรือ null เมื่อไม่เปลี่ยนชื่อ
     * @param string|null $grp กลุ่มใหม่ หรือ null เมื่อไม่ย้ายกลุ่ม
     */
    public static function update(int $projectId, int $columnId, ?string $name, ?string $grp): void
    {
        self::locked($projectId, static function () use ($projectId, $columnId, $name, $grp): void {
            if ($name !== null) {
                self::rename($projectId, $columnId, $name);
            }
            if ($grp !== null) {
                self::regroup($projectId, $columnId, $grp);
            }
        });
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
        self::locked($projectId, static function () use ($projectId, $grp, $ids): void {
            $groupIds = [];
            foreach (self::listForProject($projectId) as $column) {
                if ($column['grp'] === $grp) $groupIds[] = (int)$column['id'];
            }
            $given = array_map(static fn(mixed $value): int => (int)$value, array_values($ids));
            sort($groupIds);
            sort($given);
            if ($groupIds !== $given) {
                throw new ProjectColumnException(422, 'รายการคอลัมน์ไม่ตรงกับกลุ่ม');
            }

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
        });
    }

    /**
     * ลบคอลัมน์ โดยย้ายงานไปคอลัมน์อื่นก่อนถ้ามีงานอยู่
     *
     * จำนวนงานและคอลัมน์ปลายทางอ่านใน lock เดียวกับการลบ คำขอที่แย่งกันจึง
     * ไม่ทำให้กลุ่มว่าง หรือทิ้งงานที่ column_id กลายเป็น NULL
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $columnId รหัสคอลัมน์ที่จะลบ
     * @param int|null $moveToId คอลัมน์ปลายทางของงาน หรือ null เมื่อไม่มีงาน
     */
    public static function delete(int $projectId, int $columnId, ?int $moveToId): void
    {
        self::locked($projectId, static function () use ($projectId, $columnId, $moveToId): void {
            $column = self::findInProject($projectId, $columnId);
            if (!$column) {
                throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
            }
            self::assertGroupKeepsOne($projectId, (string)$column['grp'], $columnId);

            if (ProjectTask::countInColumn($projectId, $columnId) > 0) {
                if ($moveToId === null || $moveToId <= 0) {
                    throw new ProjectColumnException(422, 'เลือกคอลัมน์ที่จะย้ายงานไป');
                }
                if ($moveToId === $columnId || !self::findInProject($projectId, $moveToId)) {
                    throw new ProjectColumnException(404, 'ไม่พบคอลัมน์');
                }
                ProjectTask::moveColumnTasks($projectId, $columnId, $moveToId);
            }

            DB::run('DELETE FROM project_columns WHERE id = ? AND project_id = ?', [$columnId, $projectId]);
            // ลบคอลัมน์ doing สุดท้ายอาจทำให้คอลัมน์ก่อนหน้าเป็นคอลัมน์สุดท้ายแทน
            ProjectTask::syncProjectShadow($projectId);
        });
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

    /**
     * หยุดด้วย 422 เมื่อการลบ/ย้ายคอลัมน์จะทำให้กลุ่มนั้นว่าง
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $grp กลุ่มที่กำลังจะเสียคอลัมน์
     * @param int $exceptId คอลัมน์ที่กำลังจะถูกย้ายหรือลบ
     */
    private static function assertGroupKeepsOne(int $projectId, string $grp, int $exceptId): void
    {
        $remaining = (int)DB::run(
            'SELECT COUNT(*) FROM project_columns WHERE project_id = ? AND grp = ? AND id <> ?',
            [$projectId, $grp, $exceptId]
        )->fetchColumn();
        if ($remaining < 1) {
            throw new ProjectColumnException(422, 'แต่ละกลุ่มต้องมีอย่างน้อย 1 คอลัมน์');
        }
    }
}
