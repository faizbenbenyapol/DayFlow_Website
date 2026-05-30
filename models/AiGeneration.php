<?php
// =====================================================
// models/AiGeneration.php — AI generation history
// =====================================================

class AiGeneration
{
    public static function create(int $userId, array $data): int
    {
        DB::run(
            'INSERT INTO ai_generations
             (user_id, kind, keyword, platform, style, language, duration_sec,
              text_provider, video_provider, prompt, result_json, video_url, video_job_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['kind'] ?? 'script',
                $data['keyword'] ?? null,
                $data['platform'] ?? null,
                $data['style'] ?? null,
                $data['language'] ?? 'th',
                $data['duration_sec'] ?? 30,
                $data['text_provider'] ?? null,
                $data['video_provider'] ?? null,
                $data['prompt'] ?? null,
                isset($data['result']) ? json_encode($data['result'], JSON_UNESCAPED_UNICODE) : null,
                $data['video_url'] ?? null,
                $data['video_job_id'] ?? null,
                $data['status'] ?? 'completed',
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $patch): bool
    {
        $fields = [];
        $params = [];
        $allowed = ['status', 'video_url', 'video_job_id', 'error_message', 'result_json'];
        foreach ($patch as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $fields[] = "$k = ?";
            $params[] = $v;
        }
        if (!$fields) return false;
        $params[] = $id;
        $params[] = $userId;
        $stmt = DB::run(
            'UPDATE ai_generations SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?',
            $params
        );
        return $stmt->rowCount() > 0;
    }

    public static function getById(int $id, int $userId): ?array
    {
        $stmt = DB::run(
            'SELECT * FROM ai_generations WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['result'] = $row['result_json'] ? json_decode($row['result_json'], true) : null;
        return $row;
    }

    public static function listForUser(int $userId, int $limit = 30): array
    {
        $stmt = DB::run(
            'SELECT id, kind, keyword, platform, style, status, video_url, created_at, result_json
             FROM ai_generations WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . (int)$limit,
            [$userId]
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['result'] = $row['result_json'] ? json_decode($row['result_json'], true) : null;
            unset($row['result_json']);
            $out[] = $row;
        }
        return $out;
    }

    public static function delete(int $id, int $userId): bool
    {
        $stmt = DB::run(
            'DELETE FROM ai_generations WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        return $stmt->rowCount() > 0;
    }
}
