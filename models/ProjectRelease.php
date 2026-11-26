<?php
// =====================================================
// models/ProjectRelease.php — เวอร์ชัน (release) ของโปรเจคซอฟต์แวร์
//
// การเขียนทุกครั้งถือ lock ของโปรเจคแล้วอ่านแถวซ้ำใน transaction เดียวกัน
// changelog สร้างครั้งเดียวตอน "ออกเวอร์ชัน" แล้วเก็บเป็นข้อความ แก้ชื่องานทีหลัง
// จึงไม่ทำให้ประวัติของเวอร์ชันที่ออกไปแล้วเปลี่ยน
// =====================================================

// ProjectColumnException อยู่ใน ProjectColumn.php autoloader หาจากชื่อคลาสไม่เจอ จึงต้องโหลดเอง
require_once __DIR__ . '/ProjectColumn.php';

class ProjectRelease
{
    /** ความยาวชื่อเวอร์ชันสูงสุด (ตรงกับ VARCHAR(60)) */
    public const MAX_NAME = 60;

    /** ความยาว changelog สูงสุดที่แก้เองได้ */
    public const MAX_CHANGELOG = 20000;

    /** หัวกลุ่มของ changelog ตามชนิดงาน เรียงตามลำดับที่แสดง (งานย่อยและงานใหญ่ไม่ขึ้น) */
    public const CHANGELOG_GROUPS = ['story' => 'ของใหม่', 'task' => 'ปรับปรุง', 'bug' => 'แก้บั๊ก'];

    /**
     * ดึงเวอร์ชันทั้งหมดของโปรเจคพร้อมจำนวนงานและงานที่เสร็จ ในคำสั่งเดียว
     *
     * เรียงเวอร์ชันที่ยังไม่ออกก่อน (มีวันออกเรียงจากใกล้ไปไกล ไม่มีวันอยู่ท้าย แล้วใหม่ก่อน)
     * ตามด้วยเวอร์ชันที่ออกแล้ว ล่าสุดก่อน
     *
     * @param int $projectId รหัสโปรเจค
     * @return array<int, array<string, mixed>> แถวเวอร์ชัน + task_count, done_count
     */
    public static function listForProject(int $projectId): array
    {
        $rows = DB::run(
            // นับในตารางย่อยก่อน join: r.* กับ GROUP BY ตรง ๆ จะพังเมื่อเปิด ONLY_FULL_GROUP_BY
            "SELECT r.*, COALESCE(s.task_count, 0) AS task_count, COALESCE(s.done_count, 0) AS done_count
             FROM project_releases r
             LEFT JOIN (
                 SELECT t.release_id, COUNT(*) AS task_count, SUM(c.grp = 'done') AS done_count
                 FROM project_tasks t
                 LEFT JOIN project_columns c ON c.id = t.column_id
                 WHERE t.project_id = ? AND t.release_id IS NOT NULL
                 GROUP BY t.release_id
             ) s ON s.release_id = r.id
             WHERE r.project_id = ?
             ORDER BY r.state = 'released',
                      CASE WHEN r.state <> 'released' THEN r.release_date IS NULL END,
                      CASE WHEN r.state <> 'released' THEN r.release_date END ASC,
                      CASE WHEN r.state = 'released' THEN r.released_at END DESC,
                      r.id DESC",
            [$projectId, $projectId]
        )->fetchAll();

        return array_map(static fn(array $row): array => array_merge($row, [
            'task_count' => (int)$row['task_count'],
            'done_count' => (int)$row['done_count'],
        ]), $rows);
    }

    /**
     * หาเวอร์ชันตามรหัส โดยยังไม่ตรวจสิทธิ์ของโปรเจค
     *
     * @param int $id รหัสเวอร์ชัน
     * @return array<string, mixed>|null แถวเวอร์ชัน หรือ null เมื่อไม่พบ
     */
    public static function find(int $id): ?array
    {
        return DB::run('SELECT * FROM project_releases WHERE id = ?', [$id])->fetch() ?: null;
    }

    /**
     * หาเวอร์ชันที่อยู่ในโปรเจคที่ระบุ
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสเวอร์ชัน
     * @return array<string, mixed>|null แถวเวอร์ชัน หรือ null เมื่อไม่ใช่ของโปรเจคนี้
     */
    public static function findForProject(int $projectId, int $id): ?array
    {
        return DB::run(
            'SELECT * FROM project_releases WHERE id = ? AND project_id = ?',
            [$id, $projectId]
        )->fetch() ?: null;
    }

    /**
     * สร้างเวอร์ชันใหม่ในสถานะ planned
     *
     * @param int $projectId รหัสโปรเจค
     * @param array<string, mixed> $data name (บังคับ) และ release_date (ไม่บังคับ)
     * @return array{id: int, name: string} รหัสและชื่อเวอร์ชันใหม่
     * @throws ProjectColumnException 422 เมื่อชื่อหรือวันไม่ถูกต้อง
     */
    public static function create(int $projectId, array $data): array
    {
        $name = self::checkedName($data['name'] ?? '');
        $date = self::checkedDate($data['release_date'] ?? null);

        /** @var array{id: int, name: string} $created */
        $created = ProjectColumn::locked($projectId, static function () use ($projectId, $name, $date): array {
            DB::run(
                "INSERT INTO project_releases (project_id, name, release_date, state) VALUES (?, ?, ?, 'planned')",
                [$projectId, $name, $date]
            );
            return ['id' => (int)DB::conn()->lastInsertId(), 'name' => $name];
        });
        return $created;
    }

    /**
     * แก้ชื่อ วันออก สถานะ (planned/active) หรือ changelog ของเวอร์ชัน
     *
     * รับเฉพาะคีย์ที่ส่งมา สถานะเปลี่ยนได้เฉพาะเวอร์ชันที่ยังไม่ออก ส่วน changelog
     * แก้ได้เฉพาะเวอร์ชันที่ออกแล้ว (ก่อนออกยังไม่มี changelog ให้แก้)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสเวอร์ชัน
     * @param array<string, mixed> $data ค่าที่จะแก้
     * @throws ProjectColumnException 404 เมื่อไม่พบ / 422 เมื่อค่าผิดกฎ
     */
    public static function update(int $projectId, int $id, array $data): void
    {
        ProjectColumn::locked($projectId, static function () use ($projectId, $id, $data): void {
            $release = self::findForProject($projectId, $id);
            if (!$release) {
                throw new ProjectColumnException(404, 'ไม่พบเวอร์ชัน');
            }
            $released = (string)$release['state'] === 'released';

            $fields = [];
            $params = [];
            if (array_key_exists('name', $data)) {
                $fields[] = 'name = ?';
                $params[] = self::checkedName($data['name']);
            }
            if (array_key_exists('release_date', $data)) {
                $fields[] = 'release_date = ?';
                $params[] = self::checkedDate($data['release_date']);
            }
            if (array_key_exists('state', $data)) {
                if ($released) {
                    throw new ProjectColumnException(422, 'เวอร์ชันนี้ออกไปแล้ว');
                }
                if (!in_array($data['state'], ['planned', 'active'], true)) {
                    throw new ProjectColumnException(422, 'สถานะไม่ถูกต้อง');
                }
                $fields[] = 'state = ?';
                $params[] = $data['state'];
            }
            if (array_key_exists('changelog', $data)) {
                if (!$released) {
                    throw new ProjectColumnException(422, 'แก้ changelog ได้หลังออกเวอร์ชันแล้ว');
                }
                $text = is_string($data['changelog']) ? str_replace("\r\n", "\n", $data['changelog']) : null;
                if ($text === null || mb_strlen($text) > self::MAX_CHANGELOG) {
                    throw new ProjectColumnException(422, 'changelog ยาวได้ไม่เกิน 20,000 ตัวอักษร');
                }
                $fields[] = 'changelog = ?';
                $params[] = $text;
            }

            if ($fields !== []) {
                DB::run(
                    'UPDATE project_releases SET ' . implode(', ', $fields) . ' WHERE id = ? AND project_id = ?',
                    array_merge($params, [$id, $projectId])
                );
            }
        });
    }

    /**
     * ลบเวอร์ชัน งานที่ผูกไว้ยังอยู่ครบ (FK ตั้ง release_id เป็น NULL)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสเวอร์ชัน
     * @return array{name: string} ชื่อเวอร์ชันที่ลบ (ใช้ในบรรทัดประวัติ)
     * @throws ProjectColumnException 404 เมื่อไม่พบ
     */
    public static function delete(int $projectId, int $id): array
    {
        /** @var array{name: string} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($projectId, $id): array {
            $release = self::findForProject($projectId, $id);
            if (!$release) {
                throw new ProjectColumnException(404, 'ไม่พบเวอร์ชัน');
            }
            DB::run('DELETE FROM project_releases WHERE id = ? AND project_id = ?', [$id, $projectId]);
            return ['name' => (string)$release['name']];
        });
        return $result;
    }

    /**
     * ออกเวอร์ชัน: สร้าง changelog จากงานที่เสร็จ ย้ายงานค้าง แล้วตั้งสถานะ released
     *
     * งานที่ขึ้น changelog คืองานที่ผูกกับเวอร์ชันนี้ อยู่ในคอลัมน์กลุ่ม done และเป็น
     * story/task/bug เท่านั้น งานที่ยังไม่เสร็จทุกชนิดย้ายไปเวอร์ชันปลายทาง (หรือไม่ผูก)
     * ปลายทางต้องเป็นเวอร์ชันอื่นในโปรเจคเดียวกันที่ยังไม่ออก
     *
     * ผลข้างเคียง: เขียน changelog/state/released_at และเปลี่ยน release_id ของงานค้าง
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $id รหัสเวอร์ชันที่จะออก
     * @param mixed $carry รหัสเวอร์ชันปลายทางของงานค้าง หรือ null/'' = ไม่ผูก
     * @return array{name: string, carried: int} ชื่อเวอร์ชันและจำนวนงานค้างที่ย้าย
     * @throws ProjectColumnException 404 เมื่อไม่พบเวอร์ชัน / 422 เมื่อออกไปแล้วหรือปลายทางผิดกฎ
     */
    public static function release(int $projectId, int $id, mixed $carry): array
    {
        /** @var array{name: string, carried: int} $result */
        $result = ProjectColumn::locked($projectId, static function () use ($projectId, $id, $carry): array {
            $release = self::findForProject($projectId, $id);
            if (!$release) {
                throw new ProjectColumnException(404, 'ไม่พบเวอร์ชัน');
            }
            if ((string)$release['state'] === 'released') {
                throw new ProjectColumnException(422, 'เวอร์ชันนี้ออกไปแล้ว');
            }

            $target = null;
            if ($carry !== null && $carry !== '') {
                $target = self::checkedId($carry);
                $targetRow = $target === $id ? null : self::findForProject($projectId, $target);
                if (!$targetRow) {
                    throw new ProjectColumnException(404, 'ไม่พบเวอร์ชัน');
                }
                if ((string)$targetRow['state'] === 'released') {
                    throw new ProjectColumnException(422, 'ย้ายงานไปเวอร์ชันที่ออกแล้วไม่ได้');
                }
            }

            $tasks = DB::run(
                "SELECT t.id, t.number, t.title, t.kind, (c.grp = 'done') AS is_done
                 FROM project_tasks t
                 LEFT JOIN project_columns c ON c.id = t.column_id
                 WHERE t.project_id = ? AND t.release_id = ?
                 ORDER BY t.number ASC",
                [$projectId, $id]
            )->fetchAll();
            $prefix = (string)DB::run('SELECT key_prefix FROM projects WHERE id = ?', [$projectId])->fetchColumn();

            $changelog = self::buildChangelog($prefix, $tasks);
            $pending = array_map(
                static fn(array $task): int => (int)$task['id'],
                array_values(array_filter($tasks, static fn(array $task): bool => !(bool)$task['is_done']))
            );

            if ($pending !== []) {
                $placeholders = implode(',', array_fill(0, count($pending), '?'));
                DB::run(
                    'UPDATE project_tasks SET release_id = ? WHERE project_id = ? AND id IN (' . $placeholders . ')',
                    array_merge([$target, $projectId], $pending)
                );
            }
            DB::run(
                "UPDATE project_releases SET state = 'released', released_at = NOW(), changelog = ? WHERE id = ? AND project_id = ?",
                [$changelog, $id, $projectId]
            );
            return ['name' => (string)$release['name'], 'carried' => count($pending)];
        });
        return $result;
    }

    /**
     * สร้างข้อความ changelog จากแถวงาน (เรียงตามเลขงานมาแล้ว)
     *
     * แต่ละกลุ่มคือหัวกลุ่มหนึ่งบรรทัดตามด้วย "- ชื่องาน (KEY)" กลุ่มคั่นด้วยบรรทัดว่าง
     * กลุ่มที่ไม่มีงานไม่ขึ้น ไม่มีงานเสร็จเลยได้สตริงว่าง ชื่อที่มีขึ้นบรรทัดใหม่ถูกยุบเป็นช่องว่าง
     *
     * @param string $prefix คำนำหน้าเลขงานของโปรเจค
     * @param array<int, array<string, mixed>> $tasks แถวงาน (number, title, kind, is_done)
     * @return string ข้อความ changelog
     */
    private static function buildChangelog(string $prefix, array $tasks): string
    {
        $lines = array_fill_keys(array_keys(self::CHANGELOG_GROUPS), []);
        foreach ($tasks as $task) {
            $kind = (string)$task['kind'];
            if (!(bool)$task['is_done'] || !isset($lines[$kind])) continue;
            $title = trim((string)preg_replace('/\s+/u', ' ', (string)$task['title']));
            $lines[$kind][] = '- ' . $title . ' (' . $prefix . '-' . (int)$task['number'] . ')';
        }

        $groups = [];
        foreach (self::CHANGELOG_GROUPS as $kind => $heading) {
            if ($lines[$kind] !== []) {
                $groups[] = $heading . "\n" . implode("\n", $lines[$kind]);
            }
        }
        return implode("\n\n", $groups);
    }

    /**
     * ตรวจรหัสเวอร์ชันที่ client ส่งให้งาน: ต้องเป็นของโปรเจคนี้และยังไม่ออก
     *
     * รหัสผิดรูป ไม่มี หรืออยู่คนละโปรเจค ตอบ 404 เดียวกัน (เหมือน checkedSprint)
     * ส่วนเวอร์ชันที่ออกแล้วตอบ 422 ยกเว้นรหัสเดิมของงานที่ส่งกลับมาโดยไม่เปลี่ยน
     *
     * @param int $projectId รหัสโปรเจค
     * @param mixed $raw ค่าที่ client ส่งมา (ไม่ว่าง)
     * @param int|null $current release_id เดิมของงาน (null สำหรับงานใหม่)
     * @return int รหัสเวอร์ชันที่ตรวจแล้ว
     * @throws ProjectColumnException 404 / 422
     */
    public static function checkedForTask(int $projectId, mixed $raw, ?int $current): int
    {
        $id = self::checkedId($raw);
        $state = DB::run(
            'SELECT state FROM project_releases WHERE id = ? AND project_id = ?',
            [$id, $projectId]
        )->fetchColumn();
        if ($state === false) {
            throw new ProjectColumnException(404, 'ไม่พบเวอร์ชัน');
        }
        if ((string)$state === 'released' && $id !== $current) {
            throw new ProjectColumnException(422, 'เวอร์ชันนี้ออกไปแล้ว');
        }
        return $id;
    }

    /**
     * ชื่อเวอร์ชันสำหรับบรรทัดประวัติ ('ไม่ผูก' เมื่อไม่มี)
     *
     * @param int $projectId รหัสโปรเจค
     * @param int|null $id รหัสเวอร์ชัน
     * @return string ชื่อเวอร์ชัน
     */
    public static function label(int $projectId, ?int $id): string
    {
        if ($id === null) return 'ไม่ผูก';
        $name = DB::run(
            'SELECT name FROM project_releases WHERE id = ? AND project_id = ?',
            [$id, $projectId]
        )->fetchColumn();
        return $name === false ? 'ไม่ผูก' : (string)$name;
    }

    /**
     * แปลงรหัสจาก client เป็นจำนวนเต็มบวก ค่าผิดรูปตอบ 404 เหมือนรหัสที่ไม่มี
     *
     * @param mixed $raw ค่าที่ client ส่งมา
     * @return int รหัสที่มากกว่าศูนย์
     * @throws ProjectColumnException 404 เมื่อผิดรูป
     */
    private static function checkedId(mixed $raw): int
    {
        $id = is_int($raw) ? $raw : (is_string($raw) && ctype_digit($raw) ? (int)$raw : 0);
        if ($id <= 0) {
            throw new ProjectColumnException(404, 'ไม่พบเวอร์ชัน');
        }
        return $id;
    }

    /**
     * ตรวจชื่อเวอร์ชัน: แปลง NBSP เป็นช่องว่างก่อนตัด แล้วต้องยาว 1-60 ตัวอักษร
     *
     * @param mixed $raw ค่าที่ client ส่งมา
     * @return string ชื่อที่ตรวจแล้ว
     * @throws ProjectColumnException 422 เมื่อว่างหรือยาวเกิน
     */
    private static function checkedName(mixed $raw): string
    {
        $name = is_string($raw) ? trim(str_replace("\xC2\xA0", ' ', $raw)) : '';
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new ProjectColumnException(422, 'กรุณาระบุชื่อเวอร์ชัน (ไม่เกิน 60 ตัวอักษร)');
        }
        return $name;
    }

    /**
     * ตรวจวันออกรูปแบบ Y-m-d (ว่างหรือ null = ยังไม่กำหนด)
     *
     * @param mixed $value ค่าที่ client ส่งมา
     * @return string|null วันที่ตรวจแล้ว
     * @throws ProjectColumnException 422 เมื่อรูปแบบผิดหรือไม่มีวันนั้นจริง
     */
    private static function checkedDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        $date = is_string($value) ? DateTime::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new ProjectColumnException(422, 'วันที่ไม่ถูกต้อง');
        }
        return $value;
    }
}
