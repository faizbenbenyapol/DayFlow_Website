<?php
// =====================================================
// controllers/SearchController.php - Cross-module search
// =====================================================

class SearchController
{
    public function apiSearch(): void
    {
        $userId = Auth::userId();
        $query = trim((string)Request::query('q', ''));

        if (mb_strlen($query) < 2) Response::json(['results' => []]);

        $like = '%' . mb_substr($query, 0, 100) . '%';
        $results = [];
        $sources = [
            ['tasks', 'title', 'description', 'งาน', '/tasks', false],
            ['notes', 'title', 'title', 'โน้ต', '/notes', true],
            ['projects', 'name', 'description', 'โปรเจค', '/projects', false],
            ['files', 'name', 'mime_type', 'ไฟล์', '/files', false],
            ['subscriptions', 'name', 'notes', 'สมาชิก/บริการ', '/subscriptions', false],
        ];

        foreach ($sources as [$table, $titleColumn, $subtitleColumn, $type, $path, $deepLink]) {
            $sql = "SELECT id, {$titleColumn} AS title, {$subtitleColumn} AS subtitle
                    FROM {$table}
                    WHERE user_id = ? AND ({$titleColumn} LIKE ? OR {$subtitleColumn} LIKE ?)
                    ORDER BY id DESC LIMIT 5";
            foreach (DB::run($sql, [$userId, $like, $like])->fetchAll() as $row) {
                $results[] = [
                    'type' => $type,
                    'title' => (string)$row['title'],
                    'subtitle' => trim((string)($row['subtitle'] ?? '')),
                    'url' => APP_URL . $path . ($deepLink ? '/' . (int)$row['id'] : ''),
                ];
            }
        }

        usort($results, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));
        Response::json(['results' => array_slice($results, 0, 20)]);
    }
}
