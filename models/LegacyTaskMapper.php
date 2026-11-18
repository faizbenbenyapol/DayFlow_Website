<?php
// =====================================================
// models/LegacyTaskMapper.php
//
// ฟังก์ชันแปลงแถวจาก daily_todos และ quick_items ให้เป็นรูปแบบคอลัมน์
// ของ tasks โดยไม่เข้าถึงฐานข้อมูล ใช้งานโดย scripts/migrate-daily-loop.php
// =====================================================

/**
 * แปลงแถวจาก daily_todos และ quick_items ให้เป็นรูปแบบคอลัมน์ของ tasks
 * ไม่มีการเข้าถึงฐานข้อมูล ใช้งานโดย scripts/migrate-daily-loop.php
 */
class LegacyTaskMapper
{
    public const TITLE_MAX_LENGTH = 255;
    public const UNTITLED = '(ไม่มีชื่อ)';

    /**
     * แปลงแถวจาก daily_todos ให้เป็นรูปแบบคอลัมน์ของ tasks
     *
     * @param array<string, mixed> $row แถวจาก daily_todos
     * @return array<string, mixed> ข้อมูลที่พร้อม insert ลง tasks
     */
    public static function fromDailyTodo(array $row): array
    {
        $title = trim((string)($row['title'] ?? ''));
        if ($title === '') {
            $title = self::UNTITLED;
        }

        return [
            'user_id'       => (int)($row['user_id'] ?? 0),
            'title'         => $title,
            'description'   => null,
            'quadrant'      => null,
            'status'        => (int)($row['is_done'] ?? 0) ? 'done' : 'open',
            'due_date'      => $row['todo_date'] ?? null,
            'repeat_rule'   => 'none',
            'position'      => (int)($row['position'] ?? 0),
            'legacy_source' => 'daily_todo',
            'legacy_id'     => (int)($row['id'] ?? 0),
        ];
    }

    /**
     * แยกข้อความจดด่วนเป็นชื่อเรื่องกับรายละเอียด
     *
     * บรรทัดแรกที่ไม่ว่างเป็นชื่อเรื่อง ข้อความทั้งหมดเป็น description
     * เมื่อข้อความมีมากกว่าหนึ่งบรรทัด หรือชื่อเรื่องถูกตัดสั้น
     *
     * @param string $content ข้อความดิบ
     * @return array<string, string|null> คีย์ title, description
     */
    public static function splitText(string $content): array
    {
        $text  = trim($content);
        $title = self::firstNonEmptyLine($text);
        if ($title === '') {
            $title = self::UNTITLED;
        }
        $title = mb_substr($title, 0, self::TITLE_MAX_LENGTH);

        return [
            'title'       => $title,
            'description' => $text !== '' && $text !== $title ? $text : null,
        ];
    }

    /**
     * แปลงแถวจาก quick_items ให้เป็นรูปแบบคอลัมน์ของ tasks
     *
     * @param array<string, mixed> $row แถวจาก quick_items
     * @return array<string, mixed> ข้อมูลที่พร้อม insert ลง tasks
     */
    public static function fromQuickItem(array $row): array
    {
        $split = self::splitText((string)($row['content'] ?? ''));

        return [
            'user_id'       => (int)($row['user_id'] ?? 0),
            'title'         => $split['title'],
            'description'   => $split['description'],
            'quadrant'      => null,
            'status'        => (int)($row['is_done'] ?? 0) ? 'done' : 'open',
            'due_date'      => null,
            'repeat_rule'   => 'none',
            'position'      => 0,
            'created_at'    => $row['created_at'] ?? null,
            'updated_at'    => $row['updated_at'] ?? null,
            'legacy_source' => 'quick_item',
            'legacy_id'     => (int)($row['id'] ?? 0),
        ];
    }

    /**
     * คืนบรรทัดแรกที่ไม่ว่างเปล่าของข้อความ หลังตัดช่องว่างส่วนเกิน
     *
     * @param string $text ข้อความที่ตัดช่องว่างหน้าหลังแล้ว
     * @return string บรรทัดแรกที่ไม่ว่าง หรือสตริงว่างถ้าไม่มี
     */
    private static function firstNonEmptyLine(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
        return '';
    }
}
