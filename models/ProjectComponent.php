<?php
// =====================================================
// models/ProjectComponent.php — ส่วนของระบบ (component) ของโปรเจคซอฟต์แวร์
//
// ชื่อของส่วนของระบบเป็นของผู้ใช้: แปลง NBSP เป็นช่องว่างก่อน TRIM และเทียบชื่อซ้ำ
// แบบ utf8mb4_bin เพื่อให้ "สมชาย" กับ "สมช้าย" เป็นคนละชื่อ แต่ชื่อที่เหมือนกันทุก
// ตัวอักษรเป็นชื่อซ้ำ การเพิ่ม แก้ชื่อ ลบ และการเลือกของงานทุกครั้งถือ lock ของโปรเจค
// (ProjectColumn::locked) เพื่อไม่ให้สองคำขอตรวจชื่อซ้ำพร้อมกันแล้วชนกัน
// =====================================================

// ProjectColumnException อยู่ใน ProjectColumn.php autoloader หาจากชื่อคลาสไม่เจอ จึงต้องโหลดเอง
require_once __DIR__ . '/ProjectColumn.php';

class ProjectComponent
{
    /** ความยาวชื่อส่วนของระบบสูงสุด (ตรงกับ VARCHAR(60)) */
    public const MAX_NAME = 60;

    /**
     * แปลงชื่อที่ผู้ใช้พิมพ์: NBSP เป็นช่องว่างแล้วตัดช่องว่างหัวท้าย
     *
     * @param mixed $raw ค่าที่ client ส่งมา
     * @param string $label คำนำข้อความผิดพลาด
     * @return string ชื่อที่ทำความสะอาดแล้ว
     * @throws ProjectColumnException 422 เมื่อค่าว่างหรือยาวเกิน
     */
    private static function checkedName(mixed $raw, string $label = 'ชื่อส่วนของระบบ'): string
    {
        if (!is_string($raw)) {
            throw new ProjectColumnException(422, $label . 'ไม่ถูกต้อง');
        }
        $name = trim(str_replace("\xC2\xA0", ' ', $raw));
        if ($name === '') {
            throw new ProjectColumnException(422, 'กรุณาระบุ' . $label);
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            throw new ProjectColumnException(422, $label . 'ยาวได้ไม่เกิน ' . self::MAX_NAME . ' ตัวอักษร');
        }
        return $name;
    }

    /**
     * มีชื่อนี้ในโปรเจคแล้วหรือยัง (เทียบแบบ binary เพราะ collation ปกติมองข้ามวรรณยุกต์ไทย)
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $name ชื่อที่ทำความสะอาดแล้ว
     * @param int $exceptId ส่วนของระบบที่ไม่นับ (ตอนเปลี่ยนชื่อตัวเอง)
     * @return bool true เมื่อมีชื่อนี้อยู่แล้ว
     */
    private static function nameExists(int $projectId, string $name, int $exceptId = 0): bool
    {
        return (bool)DB::run(
            'SELECT 1 FROM project_components WHERE project_id = ? AND name COLLATE utf8mb4_bin = ? AND id <> ? LIMIT 1',
            [$projectId, $name, $exceptId]
        )->fetchColumn();
    }

    /**
     * ส่วนของระบบทั้งหมดของโปรเจค เรียงตามลำดับที่เพิ่ม
     *
     * @param int $projectId รหัสโปรเจค
     * @return list<array{id: int, name: string}>
     */
    public static function listForProject(int $projectId): array
    {
        $rows = DB::run(
            'SELECT id, name FROM project_components WHERE project_id = ? ORDER BY id',
            [$projectId]
        )->fetchAll();
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
        ], $rows);
    }

    /**
     * หาส่วนของระบบตามรหัส (ไม่ตรวจสิทธิ์ของโปรเจค ผู้เรียกตรวจเอง)
     *
     * @param int $id รหัสส่วนของระบบ
     * @return array<string, mixed>|null แถว หรือ null เมื่อไม่พบ
     */
    public static function find(int $id): ?array
    {
        return DB::run('SELECT * FROM project_components WHERE id = ?', [$id])->fetch() ?: null;
    }

    /**
     * เพิ่มส่วนของระบบต่อท้าย ตรวจชื่อซ้ำใน lock เดียวกับการเพิ่ม
     *
     * @param int $projectId รหัสโปรเจค
     * @param mixed $rawName ชื่อที่ client ส่งมา
     * @return array{id: int, name: string} รหัสและชื่อใหม่
     * @throws ProjectColumnException 422 เมื่อชื่อว่าง ยาวเกิน หรือซ้ำ
     */
    public static function create(int $projectId, mixed $rawName): array
    {
        $name = self::checkedName($rawName);

        /** @var array{id: int, name: string} $created */
        $created = ProjectColumn::locked($projectId, static function () use ($projectId, $name): array {
            if (self::nameExists($projectId, $name)) {
                throw new ProjectColumnException(422, 'มีส่วนของระบบชื่อนี้อยู่แล้ว');
            }
            DB::run(
                'INSERT INTO project_components (project_id, name) VALUES (?, ?)',
                [$projectId, $name]
            );
            return ['id' => (int)DB::conn()->lastInsertId(), 'name' => $name];
        });
        return $created;
    }

    /**
     * เปลี่ยนชื่อส่วนของระบบ ตรวจรหัสและชื่อซ้ำใน lock ของโปรเจค
     *
     * รหัสที่ไม่มี อยู่คนละโปรเจค หรือถูกลบไปแล้วได้ 404 ข้อความเดียวกัน
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสส่วนของระบบ
     * @param mixed $rawName ชื่อใหม่
     * @return array{old: string, name: string} ชื่อเดิมและชื่อใหม่
     * @throws ProjectColumnException 404 เมื่อไม่พบ / 422 เมื่อชื่อผิดกฎหรือซ้ำ
     */
    public static function rename(int $projectId, int $id, mixed $rawName): array
    {
        $name = self::checkedName($rawName);

        /** @var array{old: string, name: string} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($projectId, $id, $name): array {
            $old = DB::run(
                'SELECT name FROM project_components WHERE id = ? AND project_id = ?',
                [$id, $projectId]
            )->fetchColumn();
            if ($old === false) {
                throw new ProjectColumnException(404, 'ไม่พบส่วนของระบบ');
            }
            if (self::nameExists($projectId, $name, $id)) {
                throw new ProjectColumnException(422, 'มีส่วนของระบบชื่อนี้อยู่แล้ว');
            }
            DB::run(
                'UPDATE project_components SET name = ? WHERE id = ? AND project_id = ?',
                [$name, $id, $projectId]
            );
            return ['old' => (string)$old, 'name' => $name];
        });
        return $result;
    }

    /**
     * ลบส่วนของระบบ งานที่ติดอยู่หลุดจากส่วนนี้ตาม FK (project_task_components)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสส่วนของระบบ
     * @return string ชื่อที่ลบ (ใช้บันทึกประวัติ)
     * @throws ProjectColumnException 404 เมื่อไม่พบ
     */
    public static function delete(int $projectId, int $id): string
    {
        /** @var string $name */
        $name = ProjectColumn::locked($projectId, static function () use ($projectId, $id): string {
            $name = DB::run(
                'SELECT name FROM project_components WHERE id = ? AND project_id = ?',
                [$id, $projectId]
            )->fetchColumn();
            if ($name === false) {
                throw new ProjectColumnException(404, 'ไม่พบส่วนของระบบ');
            }
            DB::run('DELETE FROM project_components WHERE id = ? AND project_id = ?', [$id, $projectId]);
            return (string)$name;
        });
        return $name;
    }

    /**
     * รหัสส่วนของระบบของงานทุกงานในโปรเจค จัดกลุ่มตามงาน
     *
     * อ่านครั้งเดียวสำหรับ payload ของรายการงาน ไม่ query ต่อหนึ่งงาน
     *
     * @param int $projectId รหัสโปรเจค
     * @return array<int, list<int>> รหัสงาน => รหัสส่วนของระบบ
     */
    public static function idsForProject(int $projectId): array
    {
        $rows = DB::run(
            'SELECT tc.task_id, tc.component_id
             FROM project_task_components tc
             JOIN project_tasks t ON t.id = tc.task_id
             WHERE t.project_id = ?
             ORDER BY tc.component_id',
            [$projectId]
        )->fetchAll();

        $byTask = [];
        foreach ($rows as $row) {
            $byTask[(int)$row['task_id']][] = (int)$row['component_id'];
        }
        return $byTask;
    }

    /**
     * ตั้งส่วนของระบบของงานหนึ่งให้ตรงกับรายการที่เลือก (แทนทั้งชุด)
     *
     * รหัสทั้งหมดต้องเป็นส่วนของระบบของโปรเจคเดียวกัน รหัสที่ไม่มี อยู่คนละโปรเจค
     * หรือถูกลบไปแล้วได้ 404 ข้อความเดียวกัน เพื่อไม่ให้ client เดารหัสที่มีอยู่
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $taskId รหัสงาน
     * @param array<mixed> $rawIds รหัสส่วนของระบบที่เลือก
     * @return array{changed: bool, names: list<string>} มีการเปลี่ยนหรือไม่ และชื่อทั้งหมดหลังตั้ง
     * @throws ProjectColumnException 404 เมื่องานหรือส่วนของระบบไม่พบ / 422 เมื่อรหัสผิดรูป
     */
    public static function setForTask(int $projectId, int $taskId, array $rawIds): array
    {
        /** @var array{changed: bool, names: list<string>} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($projectId, $taskId, $rawIds): array {
            $exists = DB::run(
                'SELECT id FROM project_tasks WHERE id = ? AND project_id = ?',
                [$taskId, $projectId]
            )->fetchColumn();
            if ($exists === false) {
                throw new ProjectColumnException(404, 'ไม่พบข้อมูลงาน');
            }

            $wanted = [];
            foreach ($rawIds as $raw) {
                if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
                    throw new ProjectColumnException(404, 'ไม่พบส่วนของระบบ');
                }
                $id = (int)$raw;
                if ($id > 0 && !in_array($id, $wanted, true)) {
                    $wanted[] = $id;
                }
            }

            $names = [];
            if ($wanted !== []) {
                $placeholders = implode(',', array_fill(0, count($wanted), '?'));
                $rows = DB::run(
                    'SELECT id, name FROM project_components
                     WHERE project_id = ? AND id IN (' . $placeholders . ')',
                    array_merge([$projectId], $wanted)
                )->fetchAll();
                if (count($rows) !== count($wanted)) {
                    throw new ProjectColumnException(404, 'ไม่พบส่วนของระบบ');
                }
                foreach ($rows as $row) {
                    $names[(int)$row['id']] = (string)$row['name'];
                }
            }

            $oldIds = array_map(
                static fn(mixed $value): int => (int)$value,
                DB::run(
                    'SELECT component_id FROM project_task_components WHERE task_id = ? ORDER BY component_id',
                    [$taskId]
                )->fetchAll(PDO::FETCH_COLUMN)
            );
            sort($oldIds);
            $newIds = $wanted;
            sort($newIds);
            $changed = $oldIds !== $newIds;
            if ($changed) {
                DB::run('DELETE FROM project_task_components WHERE task_id = ?', [$taskId]);
                $values = [];
                $params = [];
                foreach ($wanted as $componentId) {
                    $values[] = '(?, ?)';
                    array_push($params, $taskId, $componentId);
                }
                if ($values !== []) {
                    DB::run(
                        'INSERT INTO project_task_components (task_id, component_id) VALUES ' . implode(', ', $values),
                        $params
                    );
                }
            }

            ksort($names);
            return ['changed' => $changed, 'names' => array_values($names)];
        });
        return $result;
    }
}
