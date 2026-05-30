<?php
// =====================================================
// models/ProjectChat.php — Collaboration Chat Model
// =====================================================

class ProjectChat
{
    /**
     * บันทึกข้อความแชทใหม่ในโครงการ (รองรับผู้ใช้ปกติ และ Guest)
     */
    public static function sendMessage(int $projectId, int $userId, string $message): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        $insertUserId = ($userId > 0) ? $userId : null;
        $guestName = !empty($_SESSION['guest_name']) ? $_SESSION['guest_name'] : null;

        return DB::run(
            'INSERT INTO project_chats (project_id, user_id, guest_name, message) VALUES (?, ?, ?, ?)',
            [$projectId, $insertUserId, $guestName, $message]
        )->rowCount() > 0;
    }

    /**
     * ดึงข้อความแชทล่าสุดของโครงการ
     */
    public static function getMessages(int $projectId, int $limit = 50): array
    {
        return DB::run(
            'SELECT pc.*, COALESCE(u.display_name, pc.guest_name) AS display_name, u.avatar_path, u.username
             FROM project_chats pc
             LEFT JOIN users u ON pc.user_id = u.id
             WHERE pc.project_id = ?
             ORDER BY pc.id ASC LIMIT ?',
            [$projectId, $limit]
        )->fetchAll();
    }
}
