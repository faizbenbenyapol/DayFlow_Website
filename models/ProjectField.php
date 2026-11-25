<?php
// =====================================================
// models/ProjectField.php — ช่องข้อมูลเพิ่มเติมของโปรเจค (REQ-P6, D-6 แบบ A)
//
// นิยามช่องอยู่ใน project_fields ส่วนค่าที่กรอกอยู่ใน project_tasks.custom
// เป็น JSON {"<field id>": ค่า} ไฟล์นี้ดูแลนิยามช่อง ตรวจค่าก่อนเขียน และ
// ล้างค่าที่ใช้ไม่ได้แล้วออกจากทุกงาน (ลบช่อง เอาตัวเลือกออก)
//
// ทุกคำสั่งที่เปลี่ยนนิยามช่องถือ lock ของโปรเจคเดียวกับคอลัมน์
// (ProjectColumn::locked) จึงนับจำนวนช่องและตรวจชื่อซ้ำได้ไม่พลาด
// =====================================================

// ProjectColumnException อยู่ในไฟล์ของ ProjectColumn ซึ่ง autoloader หาจากชื่อคลาสไม่เจอ
require_once __DIR__ . '/ProjectColumn.php';

class ProjectField
{
    public const KINDS = ['text', 'number', 'date', 'select', 'person', 'check'];
    public const MAX_FIELDS = 10;
    private const MAX_OPTIONS = 20;
    private const MAX_OPTION_LENGTH = 40;
    private const MAX_TEXT_LENGTH = 500;

    /**
     * แปลงแถวจากฐานข้อมูลให้พร้อมใช้: id/position เป็นตัวเลข options เป็น array
     *
     * @param array<string, mixed> $row แถวของ project_fields
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['position'] = (int)$row['position'];
        $options = json_decode((string)($row['options'] ?? ''), true);
        $row['options'] = is_array($options) ? $options : [];
        return $row;
    }

    /**
     * ช่องทั้งหมดของโปรเจค เรียงตามลำดับที่ตั้งไว้
     *
     * @param int $projectId รหัสโปรเจค
     * @return list<array<string, mixed>>
     */
    public static function listForProject(int $projectId): array
    {
        $rows = DB::run(
            'SELECT id, name, kind, options, position FROM project_fields WHERE project_id = ? ORDER BY position, id',
            [$projectId]
        )->fetchAll();
        return array_map(self::shape(...), $rows);
    }

    /**
     * หาช่องตามรหัส (รวม project_id ไว้ให้ผู้เรียกตรวจสิทธิ์)
     *
     * @param int $fieldId รหัสช่อง
     * @return array<string, mixed>|null ช่อง หรือ null เมื่อไม่มี
     */
    public static function find(int $fieldId): ?array
    {
        $row = DB::run('SELECT * FROM project_fields WHERE id = ?', [$fieldId])->fetch();
        return $row ? self::shape($row) : null;
    }

    /**
     * ทำความสะอาดรายการตัวเลือก: NBSP เป็นช่องว่าง ตัดช่องว่างหัวท้าย ตัดตัวว่างและตัวซ้ำ
     *
     * @param mixed $raw array ของข้อความ หรือข้อความหลายบรรทัด
     * @return list<string> ตัวเลือกที่ใช้ได้
     * @throws ProjectColumnException 422 เมื่อตัวเลือกยาวหรือมากเกิน
     */
    public static function normalizeOptions(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/\R/u', (string)$raw);
        $clean = [];
        foreach ($items ?: [] as $item) {
            $option = trim(str_replace("\xC2\xA0", ' ', (string)$item));
            if ($option === '' || in_array($option, $clean, true)) continue;
            if (mb_strlen($option) > self::MAX_OPTION_LENGTH) {
                throw new ProjectColumnException(422, 'ตัวเลือกยาวได้ไม่เกิน ' . self::MAX_OPTION_LENGTH . ' ตัวอักษร');
            }
            $clean[] = $option;
        }
        if (count($clean) > self::MAX_OPTIONS) {
            throw new ProjectColumnException(422, 'ตัวเลือกมีได้สูงสุด ' . self::MAX_OPTIONS . ' ตัว');
        }
        return $clean;
    }

    /**
     * มีช่องชื่อนี้ในโปรเจคแล้วหรือยัง (เทียบแบบ binary เพราะ collation ปกติมองข้ามวรรณยุกต์ไทย)
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $name ชื่อที่ทำความสะอาดแล้ว
     * @param int $exceptId ช่องที่ไม่นับ (ตอนเปลี่ยนชื่อตัวเอง)
     */
    private static function nameExists(int $projectId, string $name, int $exceptId = 0): bool
    {
        return (bool)DB::run(
            'SELECT 1 FROM project_fields WHERE project_id = ? AND name COLLATE utf8mb4_bin = ? AND id <> ? LIMIT 1',
            [$projectId, $name, $exceptId]
        )->fetchColumn();
    }

    /**
     * หาช่องของโปรเจคนี้ภายใน lock ช่องที่ถูกลบหรือเป็นของโปรเจคอื่นได้ 404 เดียวกัน
     *
     * @return array<string, mixed>
     * @throws ProjectColumnException 404
     */
    private static function mustFind(int $projectId, int $fieldId): array
    {
        $field = self::find($fieldId);
        if (!$field || (int)$field['project_id'] !== $projectId) {
            throw new ProjectColumnException(404, 'ไม่พบช่องข้อมูล');
        }
        return $field;
    }

    /** path ของค่าช่องใน JSON ของงาน เช่น $."12" (ส่งเป็นพารามิเตอร์ ไม่ต่อเข้า SQL) */
    private static function jsonPath(int $fieldId): string
    {
        return '$."' . $fieldId . '"';
    }

    /**
     * เพิ่มช่องต่อท้าย ตรวจจำนวน ชื่อซ้ำ และตัวเลือกใน lock เดียวกับการเพิ่ม
     *
     * @param int $projectId รหัสโปรเจค
     * @param string $name ชื่อช่อง (ตรวจความยาวแล้ว)
     * @param string $kind ชนิดใน KINDS (ตรวจแล้ว)
     * @param list<string> $options ตัวเลือกที่ผ่าน normalizeOptions (ใช้กับ select เท่านั้น)
     * @return int รหัสช่องใหม่
     * @throws ProjectColumnException 422 เมื่อเกินจำนวน ชื่อซ้ำ หรือ select ไม่มีตัวเลือก
     */
    public static function create(int $projectId, string $name, string $kind, array $options): int
    {
        return (int)ProjectColumn::locked($projectId, static function () use ($projectId, $name, $kind, $options): int {
            $count = (int)DB::run('SELECT COUNT(*) FROM project_fields WHERE project_id = ?', [$projectId])->fetchColumn();
            if ($count >= self::MAX_FIELDS) {
                throw new ProjectColumnException(422, 'เพิ่มช่องข้อมูลได้สูงสุด ' . self::MAX_FIELDS . ' ช่องต่อโปรเจค');
            }
            if (self::nameExists($projectId, $name)) {
                throw new ProjectColumnException(422, 'มีชื่อช่องนี้อยู่แล้ว');
            }
            if ($kind === 'select' && !$options) {
                throw new ProjectColumnException(422, 'ช่องแบบตัวเลือกต้องมีตัวเลือกอย่างน้อย 1 ตัว');
            }

            $position = (int)DB::run(
                'SELECT COALESCE(MAX(position), -1) FROM project_fields WHERE project_id = ?',
                [$projectId]
            )->fetchColumn() + 1;
            DB::run(
                'INSERT INTO project_fields (project_id, name, kind, options, position) VALUES (?, ?, ?, ?, ?)',
                [$projectId, $name, $kind, $kind === 'select' ? json_encode($options, JSON_UNESCAPED_UNICODE) : null, $position]
            );
            return (int)DB::conn()->lastInsertId();
        });
    }

    /**
     * เปลี่ยนชื่อและ/หรือตัวเลือกของช่อง (ชนิดเปลี่ยนไม่ได้ เพราะค่าเดิมจะใช้ไม่ได้ทั้งหมด)
     *
     * เปลี่ยนตัวเลือกแล้ว ค่าในงานที่ไม่อยู่ในรายการใหม่ถูกล้างใน UPDATE เดียว
     *
     * @param list<string>|null $options ตัวเลือกใหม่ที่ผ่าน normalizeOptions หรือ null = ไม่เปลี่ยน
     * @throws ProjectColumnException 404 ช่องหาย, 422 ชื่อซ้ำ/ไม่ใช่ select/ตัวเลือกว่าง
     */
    public static function update(int $projectId, int $fieldId, ?string $name, ?array $options): void
    {
        ProjectColumn::locked($projectId, static function () use ($projectId, $fieldId, $name, $options): void {
            $field = self::mustFind($projectId, $fieldId);

            if ($name !== null) {
                if (self::nameExists($projectId, $name, $fieldId)) {
                    throw new ProjectColumnException(422, 'มีชื่อช่องนี้อยู่แล้ว');
                }
                DB::run('UPDATE project_fields SET name = ? WHERE id = ?', [$name, $fieldId]);
            }

            if ($options === null) return;
            if ($field['kind'] !== 'select') {
                throw new ProjectColumnException(422, 'ช่องนี้ไม่มีตัวเลือก');
            }
            if (!$options) {
                throw new ProjectColumnException(422, 'ช่องแบบตัวเลือกต้องมีตัวเลือกอย่างน้อย 1 ตัว');
            }
            DB::run('UPDATE project_fields SET options = ? WHERE id = ?', [json_encode($options, JSON_UNESCAPED_UNICODE), $fieldId]);

            // ล้างค่าที่ไม่อยู่ในตัวเลือกใหม่ ไม่ใช่การแก้ของผู้ใช้ จึงคง updated_at เดิม
            $path = self::jsonPath($fieldId);
            $placeholders = implode(', ', array_fill(0, count($options), '?'));
            DB::run(
                "UPDATE project_tasks
                    SET custom = JSON_REMOVE(custom, ?), updated_at = updated_at
                  WHERE project_id = ?
                    AND JSON_EXISTS(custom, ?)
                    AND JSON_UNQUOTE(JSON_EXTRACT(custom, ?)) COLLATE utf8mb4_bin NOT IN ($placeholders)",
                array_merge([$path, $projectId, $path, $path], $options)
            );
        });
    }

    /**
     * ตั้งลำดับช่องใหม่ ต้องส่ง id ครบและตรงกับช่องของโปรเจคพอดี
     *
     * @param list<mixed> $ids รหัสช่องตามลำดับใหม่
     * @throws ProjectColumnException 422 เมื่อชุด id ไม่ตรง
     */
    public static function reorder(int $projectId, array $ids): void
    {
        ProjectColumn::locked($projectId, static function () use ($projectId, $ids): void {
            $wanted = array_map('intval', $ids);
            $current = array_map('intval', array_column(self::listForProject($projectId), 'id'));
            $sortedWanted = $wanted;
            sort($sortedWanted);
            sort($current);
            if ($sortedWanted !== $current) {
                throw new ProjectColumnException(422, 'ข้อมูลการจัดลำดับไม่ถูกต้อง');
            }
            // สูงสุด 10 แถว: statement เดียว execute ซ้ำ
            $stmt = DB::conn()->prepare('UPDATE project_fields SET position = ? WHERE id = ?');
            foreach ($wanted as $position => $fieldId) {
                $stmt->execute([$position, $fieldId]);
            }
        });
    }

    /**
     * ลบช่อง และล้างค่าของช่องนี้ออกจากทุกงานของโปรเจคใน UPDATE เดียว
     *
     * @throws ProjectColumnException 404 เมื่อช่องหายไปแล้ว
     */
    public static function delete(int $projectId, int $fieldId): void
    {
        ProjectColumn::locked($projectId, static function () use ($projectId, $fieldId): void {
            self::mustFind($projectId, $fieldId);
            $path = self::jsonPath($fieldId);
            DB::run(
                'UPDATE project_tasks SET custom = JSON_REMOVE(custom, ?), updated_at = updated_at
                  WHERE project_id = ? AND JSON_EXISTS(custom, ?)',
                [$path, $projectId, $path]
            );
            DB::run('DELETE FROM project_fields WHERE id = ?', [$fieldId]);
        });
    }

    /**
     * ตรวจค่าที่ client ส่งมา {"<field id>": ค่า} แล้วคืนแพตช์สำหรับ JSON_MERGE_PATCH
     *
     * ค่า null หรือว่าง = ลบค่า (null ในแพตช์) ไม่เขียนฐานข้อมูล
     *
     * @param int $projectId รหัสโปรเจค (ใช้ตรวจว่าคนในช่อง "คน" เป็นสมาชิก)
     * @param list<array<string, mixed>> $fields ผลของ listForProject()
     * @param array<mixed> $input ค่าที่ client ส่งมา
     * @return array<string, mixed> แพตช์ คีย์เป็น id แบบข้อความ
     * @throws ProjectColumnException 422 พร้อมชื่อช่องเมื่อค่าไม่ผ่าน
     */
    public static function cleanValues(int $projectId, array $fields, array $input): array
    {
        $byId = array_column($fields, null, 'id');
        $patch = [];
        foreach ($input as $key => $value) {
            $field = $byId[(int)$key] ?? null;
            if ($field === null) {
                throw new ProjectColumnException(422, 'ไม่พบช่องข้อมูล');
            }
            $patch[(string)$field['id']] = self::cleanValue($projectId, $field, $value);
        }
        return $patch;
    }

    /**
     * ตรวจค่าของช่องเดียวตามชนิด
     *
     * @param array<string, mixed> $field ช่อง
     * @return mixed ค่าที่เก็บ หรือ null = ไม่มีค่า
     * @throws ProjectColumnException 422 เมื่อค่าไม่ตรงชนิด
     */
    private static function cleanValue(int $projectId, array $field, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim(str_replace("\xC2\xA0", ' ', $value));
        }
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $name = '"' . $field['name'] . '"';

        switch ($field['kind']) {
            case 'text':
                if (!is_string($value) && !is_int($value) && !is_float($value)) break;
                if (mb_strlen((string)$value) > self::MAX_TEXT_LENGTH) {
                    throw new ProjectColumnException(422, 'ค่าในช่อง ' . $name . ' ยาวได้ไม่เกิน ' . self::MAX_TEXT_LENGTH . ' ตัวอักษร');
                }
                return (string)$value;
            case 'number':
                if (!is_numeric($value) || !is_finite((float)$value)) {
                    throw new ProjectColumnException(422, 'ช่อง ' . $name . ' ต้องเป็นตัวเลข');
                }
                $number = (float)$value;
                return floor($number) === $number && abs($number) < PHP_INT_MAX ? (int)$number : $number;
            case 'date':
                $date = is_string($value) ? DateTime::createFromFormat('!Y-m-d', $value) : false;
                if (!$date || $date->format('Y-m-d') !== $value) {
                    throw new ProjectColumnException(422, 'ช่อง ' . $name . ' ต้องเป็นวันที่');
                }
                return $value;
            case 'select':
                if (!in_array($value, $field['options'], true)) {
                    throw new ProjectColumnException(422, 'ตัวเลือกในช่อง ' . $name . ' ไม่ถูกต้อง');
                }
                return $value;
            case 'person':
                $userId = is_numeric($value) ? (int)$value : 0;
                if ($userId <= 0 || ProjectMember::getRole($projectId, $userId) === null) {
                    throw new ProjectColumnException(422, 'คนในช่อง ' . $name . ' ไม่ใช่สมาชิกของโปรเจคนี้');
                }
                return $userId;
            case 'check':
                if ($value === true || $value === 1 || $value === '1') return true;
                if ($value === 0 || $value === '0') return null;
                break;
        }
        throw new ProjectColumnException(422, 'ค่าในช่อง ' . $name . ' ไม่ถูกต้อง');
    }
}
