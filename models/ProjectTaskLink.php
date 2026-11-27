<?php
// =====================================================
// models/ProjectTaskLink.php — ลิงก์ภายนอกของงานในโปรเจค
//
// รับเฉพาะ URL ที่มี scheme เป็น http หรือ https เท่านั้น (กัน javascript: ที่กดแล้ว
// รันโค้ด และ data: ที่ฝังเนื้อหา) ลิงก์ทุกแถวผูกกับงาน ต้องเป็นงานของโปรเจคเดียวกัน
// และการเพิ่ม/ลบถือ lock ของโปรเจคเหมือนตารางความสัมพันธ์อื่น
// =====================================================

// ProjectColumnException อยู่ใน ProjectColumn.php autoloader หาจากชื่อคลาสไม่เจอ จึงต้องโหลดเอง
require_once __DIR__ . '/ProjectColumn.php';

class ProjectTaskLink
{
    /** ความยาว URL สูงสุด (ตรงกับ VARCHAR(2000)) */
    public const MAX_URL = 2000;

    /** ความยาวชื่อลิงก์สูงสุด (ตรงกับ VARCHAR(120)) */
    public const MAX_LABEL = 120;

    /**
     * ตรวจ URL: ต้องเป็นสตริง ยาวไม่เกิน 2,000 ตัวอักษร และมี scheme http/https จริง
     *
     * ตรวจด้วย parse_url ไม่ใช่การเทียบคำนำหน้า เพื่อไม่ให้ "HTTP://" หรือช่องว่าง
     * หลุดผ่าน และต้องมี host ด้วย (เช่น "http:" เดี่ยว ๆ ไม่ใช่ลิงก์ที่เปิดได้)
     *
     * @param mixed $raw ค่าที่ client ส่งมา
     * @return string URL ที่ตัดช่องว่างหัวท้ายแล้ว
     * @throws ProjectColumnException 422 เมื่อ URL ว่าง ยาวเกิน หรือ scheme ไม่อนุญาต
     */
    public static function checkedUrl(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw new ProjectColumnException(422, 'ลิงก์ไม่ถูกต้อง');
        }
        $url = trim($raw);
        if ($url === '') {
            throw new ProjectColumnException(422, 'กรุณาระบุลิงก์');
        }
        if (mb_strlen($url) > self::MAX_URL) {
            throw new ProjectColumnException(422, 'ลิงก์ยาวได้ไม่เกิน 2,000 ตัวอักษร');
        }
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new ProjectColumnException(422, 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://');
        }
        return $url;
    }

    /**
     * ตรวจชื่อลิงก์: ไม่บังคับ ค่าว่างคือไม่มีชื่อ ยาวไม่เกิน 120 ตัวอักษร
     *
     * @param mixed $raw ค่าที่ client ส่งมา
     * @return string|null ชื่อที่ตัดช่องว่างแล้ว หรือ null
     * @throws ProjectColumnException 422 เมื่อยาวเกิน
     */
    private static function checkedLabel(mixed $raw): ?string
    {
        if ($raw === null) return null;
        if (!is_string($raw)) {
            throw new ProjectColumnException(422, 'ชื่อลิงก์ไม่ถูกต้อง');
        }
        $label = trim($raw);
        if ($label === '') return null;
        if (mb_strlen($label) > self::MAX_LABEL) {
            throw new ProjectColumnException(422, 'ชื่อลิงก์ยาวได้ไม่เกิน ' . self::MAX_LABEL . ' ตัวอักษร');
        }
        return $label;
    }

    /**
     * ลิงก์ทั้งหมดของโปรเจค เรียงตามงานแล้วตามลำดับที่เพิ่ม
     *
     * ใช้ทั้ง payload ของรายการงานและการตอบกลับหลังเพิ่ม/ลบ เพื่อให้ client
     * วาดแผงใหม่ได้โดยไม่ต้องโหลดโปรเจคซ้ำ
     *
     * @param int $projectId รหัสโปรเจค
     * @return list<array{id: int, task_id: int, url: string, label: string|null}>
     */
    public static function listForProject(int $projectId): array
    {
        $rows = DB::run(
            'SELECT l.id, l.task_id, l.url, l.label
             FROM project_task_links l
             JOIN project_tasks t ON t.id = l.task_id
             WHERE t.project_id = ?
             ORDER BY l.task_id, l.id',
            [$projectId]
        )->fetchAll();

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'task_id' => (int)$row['task_id'],
            'url' => (string)$row['url'],
            'label' => $row['label'] === null ? null : (string)$row['label'],
        ], $rows);
    }

    /**
     * เพิ่มลิงก์ให้งานในโปรเจค
     *
     * งานที่ไม่มี ถูกลบไปแล้ว หรืออยู่คนละโปรเจคได้ 404 ข้อความเดียวกัน
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $taskId รหัสงาน
     * @param mixed $rawUrl URL ที่ client ส่งมา
     * @param mixed $rawLabel ชื่อลิงก์ (ไม่บังคับ)
     * @return array{id: int, url: string, label: string|null} ลิงก์ใหม่
     * @throws ProjectColumnException 404 เมื่อไม่พบงาน / 422 เมื่อ URL หรือชื่อผิดกฎ
     */
    public static function create(int $projectId, int $taskId, mixed $rawUrl, mixed $rawLabel): array
    {
        $url = self::checkedUrl($rawUrl);
        $label = self::checkedLabel($rawLabel);

        /** @var array{id: int, url: string, label: string|null} $created */
        $created = ProjectColumn::locked($projectId, static function () use ($projectId, $taskId, $url, $label): array {
            $exists = DB::run(
                'SELECT id FROM project_tasks WHERE id = ? AND project_id = ?',
                [$taskId, $projectId]
            )->fetchColumn();
            if ($exists === false) {
                throw new ProjectColumnException(404, 'ไม่พบข้อมูลงาน');
            }

            DB::run(
                'INSERT INTO project_task_links (task_id, url, label) VALUES (?, ?, ?)',
                [$taskId, $url, $label]
            );
            return ['id' => (int)DB::conn()->lastInsertId(), 'url' => $url, 'label' => $label];
        });
        return $created;
    }

    /**
     * เอาลิงก์ออกจากงาน
     *
     * ลิงก์ที่ไม่มี ถูกลบไปแล้ว อยู่คนละงาน หรืออยู่คนละโปรเจคได้ 404 ข้อความเดียวกัน
     *
     * @param int $projectId รหัสโปรเจค
     * @param int $taskId รหัสงานที่ URL อ้างถึง
     * @param int $linkId รหัสลิงก์
     * @return array{url: string, label: string|null} ลิงก์ที่ถูกลบ (ใช้บันทึกประวัติ)
     * @throws ProjectColumnException 404 เมื่อไม่พบงานหรือลิงก์
     */
    public static function delete(int $projectId, int $taskId, int $linkId): array
    {
        /** @var array{url: string, label: string|null} $deleted */
        $deleted = ProjectColumn::locked($projectId, static function () use ($projectId, $taskId, $linkId): array {
            $exists = DB::run(
                'SELECT id FROM project_tasks WHERE id = ? AND project_id = ?',
                [$taskId, $projectId]
            )->fetchColumn();
            if ($exists === false) {
                throw new ProjectColumnException(404, 'ไม่พบข้อมูลงาน');
            }

            $row = DB::run(
                'SELECT url, label FROM project_task_links WHERE id = ? AND task_id = ?',
                [$linkId, $taskId]
            )->fetch();
            if (!$row) {
                throw new ProjectColumnException(404, 'ไม่พบลิงก์');
            }

            DB::run('DELETE FROM project_task_links WHERE id = ? AND task_id = ?', [$linkId, $taskId]);
            return [
                'url' => (string)$row['url'],
                'label' => $row['label'] === null ? null : (string)$row['label'],
            ];
        });
        return $deleted;
    }
}
