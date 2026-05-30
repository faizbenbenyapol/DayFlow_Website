<?php

class SkillLog
{
    public static function all(int $userId, int $limit = 50): array
    {
        $sql = "SELECT l.*, s.name as skill_name, s.color as skill_color 
                FROM skill_logs l
                JOIN skills s ON l.skill_id = s.id
                WHERE l.user_id = ? 
                ORDER BY l.start_time DESC LIMIT ?";
        $stmt = DB::conn()->prepare($sql);
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function create(int $userId, string $skillId, string $startTime, string $endTime, int $durationSeconds, string $notes): string
    {
        $id = uuid4();
        $sql = "INSERT INTO skill_logs (id, user_id, skill_id, start_time, end_time, duration_seconds, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        DB::run($sql, [$id, $userId, $skillId, $startTime, $endTime, $durationSeconds, $notes]);
        return $id;
    }
    
    public static function delete(string $id, int $userId): void
    {
        $sql = "DELETE FROM skill_logs WHERE id = ? AND user_id = ?";
        DB::run($sql, [$id, $userId]);
    }

    // Active Timer functions
    public static function getActiveTimer(int $userId)
    {
        $sql = "SELECT t.*, s.name as skill_name, s.color as skill_color 
                FROM skill_active_timers t
                JOIN skills s ON t.skill_id = s.id
                WHERE t.user_id = ?";
        return DB::run($sql, [$userId])->fetch();
    }

    public static function startTimer(int $userId, string $skillId, string $notes): void
    {
        // Delete existing if any (only 1 active timer allowed)
        self::stopTimerAndLog($userId);
        
        $sql = "INSERT INTO skill_active_timers (user_id, skill_id, start_time, notes) VALUES (?, ?, NOW(), ?)";
        DB::run($sql, [$userId, $skillId, $notes]);
    }

    public static function stopTimerAndLog(int $userId): void
    {
        $activeTimer = self::getActiveTimer($userId);
        if ($activeTimer) {
            $sql = "DELETE FROM skill_active_timers WHERE user_id = ?";
            DB::run($sql, [$userId]);

            // Calculate duration
            $startTime = new DateTime($activeTimer['start_time']);
            $endTime = new DateTime();
            $durationSeconds = $endTime->getTimestamp() - $startTime->getTimestamp();
            if ($durationSeconds > 0) {
                self::create($userId, $activeTimer['skill_id'], $activeTimer['start_time'], $endTime->format('Y-m-d H:i:s'), $durationSeconds, $activeTimer['notes'] ?: '');
            }
        }
    }
    
    public static function updateTimerNotes(int $userId, string $notes): void
    {
        $sql = "UPDATE skill_active_timers SET notes = ? WHERE user_id = ?";
        DB::run($sql, [$notes, $userId]);
    }

    public static function getStats(int $userId): array
    {
        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('last monday', strtotime('tomorrow')));
        $monthStart = date('Y-m-01');

        $sql = "SELECT 
                SUM(CASE WHEN DATE(start_time) = ? THEN duration_seconds ELSE 0 END) as today_seconds,
                SUM(CASE WHEN DATE(start_time) >= ? THEN duration_seconds ELSE 0 END) as week_seconds,
                SUM(CASE WHEN DATE(start_time) >= ? THEN duration_seconds ELSE 0 END) as month_seconds,
                SUM(duration_seconds) as total_seconds
                FROM skill_logs WHERE user_id = ?";
        $res = DB::run($sql, [$today, $weekStart, $monthStart, $userId])->fetch();

        // Stats grouping by skill for total progress
        $sqlSkill = "SELECT skill_id, SUM(duration_seconds) as total_seconds FROM skill_logs WHERE user_id = ? GROUP BY skill_id";
        $skillsStats = DB::run($sqlSkill, [$userId])->fetchAll();

        return [
            'today_seconds' => (int)($res['today_seconds'] ?? 0),
            'week_seconds' => (int)($res['week_seconds'] ?? 0),
            'month_seconds' => (int)($res['month_seconds'] ?? 0),
            'total_seconds' => (int)($res['total_seconds'] ?? 0),
            'skills_progress' => $skillsStats
        ];
    }
}
