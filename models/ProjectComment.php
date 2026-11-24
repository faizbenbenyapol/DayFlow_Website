<?php
// =====================================================
// models/ProjectComment.php — ความเห็นต่องาน และการกล่าวถึง @username
//
// ผู้เรียก (controller) ตรวจสิทธิ์ของโปรเจคมาแล้ว ไฟล์นี้ดูแลแค่ข้อมูล
// =====================================================

final class ProjectComment
{
    /** ความยาวสูงสุดของความเห็นหนึ่งรายการ */
    public const MAX_LENGTH = 5000;

    /**
     * ความเห็นของงาน เก่าไปใหม่ เฉพาะที่ id มากกว่า $afterId (polling ดึงเฉพาะของใหม่)
     *
     * @param int $taskId รหัสงาน
     * @param int $afterId ดึงเฉพาะ id ที่มากกว่านี้ (0 = ทั้งหมด)
     * @return array<int, array<string, mixed>> id, user_id, body, created_at, author_name, author_username
     */
    public static function listForTask(int $taskId, int $afterId = 0): array
    {
        return DB::run(
            "SELECT c.id, c.user_id, c.body, c.created_at,
                    COALESCE(NULLIF(u.display_name, ''), u.username, 'ผู้ใช้ที่ลบแล้ว') AS author_name,
                    u.username AS author_username
             FROM project_task_comments c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.task_id = ? AND c.id > ?
             ORDER BY c.id ASC
             LIMIT 200",
            [$taskId, $afterId]
        )->fetchAll();
    }

    /**
     * เพิ่มความเห็น
     *
     * @return int รหัสความเห็นใหม่
     */
    public static function create(int $taskId, int $projectId, ?int $userId, string $body): int
    {
        DB::run(
            'INSERT INTO project_task_comments (task_id, project_id, user_id, body) VALUES (?, ?, ?, ?)',
            [$taskId, $projectId, $userId, $body]
        );
        return (int)DB::conn()->lastInsertId();
    }

    /**
     * หาความเห็นตามรหัส (ยังไม่ตรวจสิทธิ์)
     *
     * @return array<string, mixed>|null id, task_id, project_id, user_id
     */
    public static function find(int $commentId): ?array
    {
        return DB::run(
            'SELECT id, task_id, project_id, user_id FROM project_task_comments WHERE id = ?',
            [$commentId]
        )->fetch() ?: null;
    }

    /** ลบความเห็น */
    public static function delete(int $commentId): void
    {
        DB::run('DELETE FROM project_task_comments WHERE id = ?', [$commentId]);
    }

    /**
     * หาคนในโปรเจคที่ถูกกล่าวถึงด้วย @username ในข้อความ (เจ้าของหรือสมาชิก) query เดียว
     * คนนอกโปรเจคหรือชื่อที่ไม่มีจริงถูกข้าม ไม่บอกว่ามีบัญชีนี้ในระบบไหม
     *
     * @param int $projectId โปรเจค
     * @param string $text ข้อความที่อาจมี @username
     * @return array<int, int> user id ที่ไม่ซ้ำ
     */
    public static function mentionedMembers(int $projectId, string $text): array
    {
        preg_match_all('/@([A-Za-z0-9_]{3,30})/', $text, $m);
        $names = array_values(array_unique($m[1]));
        if ($names === []) return [];

        $marks = implode(',', array_fill(0, count($names), '?'));
        $rows = DB::run(
            "SELECT u.id FROM users u
             WHERE u.username IN ($marks)
               AND (u.id = (SELECT p.user_id FROM projects p WHERE p.id = ?)
                    OR u.id IN (SELECT pm.user_id FROM project_members pm WHERE pm.project_id = ?))",
            array_merge($names, [$projectId, $projectId])
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    }

    /**
     * แจ้งทุกคนที่ถูกกล่าวถึง (ไม่แจ้งคนพิมพ์เอง — Notification::send ข้ามให้)
     *
     * @param int $projectId โปรเจค
     * @param string $text ข้อความที่พิมพ์
     * @param int $actorId คนพิมพ์
     * @param string $where "ในความเห็น" หรือ "ในแชท"
     * @param string $body บรรทัดรองของการแจ้งเตือน (ชื่องาน/โปรเจค)
     * @param string $url ลิงก์ที่กดแล้วไป
     */
    public static function notifyMentions(int $projectId, string $text, int $actorId, string $where, string $body, string $url): void
    {
        $ids = self::mentionedMembers($projectId, $text);
        if ($ids === []) return;
        $actor = DB::run("SELECT COALESCE(NULLIF(display_name, ''), username) FROM users WHERE id = ?", [$actorId])->fetchColumn() ?: 'มีคน';
        $snippet = mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '…' : $text;
        foreach ($ids as $userId) {
            Notification::send($userId, $actorId, 'mention', $actor . ' กล่าวถึงคุณ' . $where . ': "' . $snippet . '"', $body, $url);
        }
    }
}
