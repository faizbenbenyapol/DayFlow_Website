<?php
// =====================================================
// models/FocusSession.php
// =====================================================

class FocusSession
{
    public static function listForUser(int $userId, int $limit = 50): array
    {
        $sql = 'SELECT f.*, t.title as task_title 
                FROM focus_sessions f
                LEFT JOIN tasks t ON f.task_id = t.id
                WHERE f.user_id = ?
                ORDER BY f.completed_at DESC, f.id DESC LIMIT ?';
        return DB::run($sql, [$userId, $limit])->fetchAll();
    }

    public static function create(int $userId, array $data): int
    {
        DB::run(
            'INSERT INTO focus_sessions (user_id, task_id, title, duration_min, type)
             VALUES (?, ?, ?, ?, ?)',
            [
                $userId,
                $data['task_id'] ?: null,
                $data['title'],
                (int)$data['duration_min'],
                $data['type'] ?: 'work'
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM focus_sessions WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }

    public static function getStats(int $userId): array
    {
        $today = date('Y-m-d');

        // Today's total work minutes
        $todayWorkMinutes = (int)DB::run(
            'SELECT COALESCE(SUM(duration_min), 0) FROM focus_sessions
             WHERE user_id = ? AND DATE(completed_at) = ? AND type = "work"',
            [$userId, $today]
        )->fetchColumn();

        // Today's completed work sessions count
        $todayWorkSessions = (int)DB::run(
            'SELECT COUNT(*) FROM focus_sessions
             WHERE user_id = ? AND DATE(completed_at) = ? AND type = "work"',
            [$userId, $today]
        )->fetchColumn();

        // Total completed sessions (all types)
        $totalSessionsCount = (int)DB::run(
            'SELECT COUNT(*) FROM focus_sessions WHERE user_id = ?',
            [$userId]
        )->fetchColumn();

        // Weekly breakdown (past 7 days including today)
        $weeklyStats = DB::run(
            'SELECT DATE(completed_at) as focus_date, SUM(duration_min) as total_min, COUNT(*) as sessions
             FROM focus_sessions 
             WHERE user_id = ? AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND type = "work"
             GROUP BY focus_date ORDER BY focus_date ASC',
            [$userId]
        )->fetchAll();

        return [
            'today_work_minutes' => $todayWorkMinutes,
            'today_work_sessions' => $todayWorkSessions,
            'total_sessions_count' => $totalSessionsCount,
            'weekly' => $weeklyStats
        ];
    }
}
