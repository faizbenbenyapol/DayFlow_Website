<?php
// =====================================================
// controllers/SearchController.php - Cross-module search
// =====================================================

class SearchController
{
    /**
     * One entry per searchable module.
     *
     * Each SELECT contributes (src, id, title, subtitle) for a single user and
     * is combined into one UNION ALL statement, so a keystroke costs one round
     * trip instead of one per module. Every table here already has a leading
     * user_id index, which keeps each branch scoped to the caller's own rows.
     *
     * 'binds' is how many of (userId, like, like) the fragment consumes.
     */
    private const SOURCES = [
        ['key' => 'tasks',         'label' => 'งาน',            'path' => '/tasks',         'deep' => false, 'binds' => 3,
         'sql' => 'SELECT %d AS src, id, title, description AS subtitle FROM tasks
                   WHERE user_id = ? AND (title LIKE ? OR description LIKE ?)
                   ORDER BY id DESC LIMIT 5'],

        ['key' => 'notes',         'label' => 'โน้ต',           'path' => '/notes',         'deep' => true,  'binds' => 2,
         'sql' => 'SELECT %d AS src, id, title, "" AS subtitle FROM notes
                   WHERE user_id = ? AND title LIKE ?
                   ORDER BY id DESC LIMIT 5'],

        // Note bodies live in note_blocks. Encrypted notes are excluded: their
        // blocks are stored as plaintext and only gated behind a password, so
        // surfacing them in search would defeat that gate.
        ['key' => 'notes_body',    'label' => 'โน้ต (เนื้อหา)',  'path' => '/notes',         'deep' => true,  'binds' => 2,
         'sql' => 'SELECT %d AS src, n.id, n.title, MIN(b.content) AS subtitle
                   FROM notes n JOIN note_blocks b ON b.note_id = n.id
                   WHERE n.user_id = ? AND n.is_encrypted = 0 AND b.type = "text" AND b.content LIKE ?
                   GROUP BY n.id, n.title ORDER BY n.id DESC LIMIT 5'],

        ['key' => 'projects',      'label' => 'โปรเจค',         'path' => '/projects',      'deep' => false, 'binds' => 3,
         'sql' => 'SELECT %d AS src, id, name AS title, description AS subtitle FROM projects
                   WHERE user_id = ? AND (name LIKE ? OR description LIKE ?)
                   ORDER BY id DESC LIMIT 5'],

        ['key' => 'files',         'label' => 'ไฟล์',           'path' => '/files',         'deep' => false, 'binds' => 2,
         'sql' => 'SELECT %d AS src, id, name AS title, mime_type AS subtitle FROM files
                   WHERE user_id = ? AND name LIKE ?
                   ORDER BY id DESC LIMIT 5'],

        ['key' => 'subscriptions', 'label' => 'สมาชิก/บริการ',   'path' => '/subscriptions', 'deep' => false, 'binds' => 3,
         'sql' => 'SELECT %d AS src, id, name AS title, notes AS subtitle FROM subscriptions
                   WHERE user_id = ? AND (name LIKE ? OR notes LIKE ?)
                   ORDER BY id DESC LIMIT 5'],

        ['key' => 'bookmarks',     'label' => 'ลิงก์',          'path' => '/bookmarks',     'deep' => false, 'binds' => 3,
         'sql' => 'SELECT %d AS src, id, title, url AS subtitle FROM bookmarks
                   WHERE user_id = ? AND (title LIKE ? OR url LIKE ?)
                   ORDER BY id DESC LIMIT 5'],

        ['key' => 'quick_items',   'label' => 'จดด่วน',         'path' => '/quick-notes',   'deep' => false, 'binds' => 2,
         'sql' => 'SELECT %d AS src, id, content AS title, "" AS subtitle FROM quick_items
                   WHERE user_id = ? AND content LIKE ?
                   ORDER BY id DESC LIMIT 5'],

        ['key' => 'food_notes',    'label' => 'อาหาร',          'path' => '/food-notes',    'deep' => false, 'binds' => 3,
         'sql' => 'SELECT %d AS src, id, name AS title, notes AS subtitle FROM food_notes
                   WHERE user_id = ? AND (name LIKE ? OR notes LIKE ?)
                   ORDER BY id DESC LIMIT 5'],

        ['key' => 'habits',        'label' => 'นิสัย',          'path' => '/habits',        'deep' => false, 'binds' => 2,
         'sql' => 'SELECT %d AS src, id, name AS title, "" AS subtitle FROM habits
                   WHERE user_id = ? AND is_archived = 0 AND name LIKE ?
                   ORDER BY id DESC LIMIT 5'],

        ['key' => 'skills',        'label' => 'ทักษะ',          'path' => '/skills',        'deep' => false, 'binds' => 2,
         'sql' => 'SELECT %d AS src, id, name AS title, "" AS subtitle FROM skills
                   WHERE user_id = ? AND name LIKE ?
                   ORDER BY id DESC LIMIT 5'],

        ['key' => 'finances',      'label' => 'การเงิน',        'path' => '/finance',       'deep' => false, 'binds' => 2,
         'sql' => 'SELECT %d AS src, id, description AS title, "" AS subtitle FROM finances
                   WHERE user_id = ? AND description LIKE ?
                   ORDER BY id DESC LIMIT 5'],
    ];

    public function apiSearch(): void
    {
        $userId = Auth::userId();
        $query = trim((string)Request::query('q', ''));

        if (mb_strlen($query) < 2) Response::json(['results' => []]);

        $needle = mb_substr($query, 0, 100);
        $like   = '%' . $needle . '%';

        $branches = [];
        $params   = [];
        foreach (self::SOURCES as $index => $source) {
            $branches[] = '(' . sprintf($source['sql'], $index) . ')';
            $params[]   = $userId;
            // 'binds' counts userId plus one placeholder per searched column.
            for ($i = 1; $i < $source['binds']; $i++) $params[] = $like;
        }

        $rows = DB::run(implode("\nUNION ALL\n", $branches), $params)->fetchAll();

        $results = [];
        $seen    = [];
        foreach ($rows as $row) {
            $source = self::SOURCES[(int)$row['src']] ?? null;
            if ($source === null) continue;

            $title = (string)($row['title'] ?? '');
            if ($title === '') continue;

            // A note matching on both its title and its body is one result.
            $dedupeKey = $source['path'] . '#' . (int)$row['id'];
            if (isset($seen[$dedupeKey])) continue;
            $seen[$dedupeKey] = true;

            $results[] = [
                'type'     => $source['label'],
                'title'    => $title,
                'subtitle' => trim(mb_substr((string)($row['subtitle'] ?? ''), 0, 160)),
                'url'      => APP_URL . $source['path'] . ($source['deep'] ? '/' . (int)$row['id'] : ''),
                'rank'     => $this->rank($title, $needle),
            ];
        }

        // Closest match first: a title that starts with the query beats one that
        // merely contains it, which beats a hit that only matched the subtitle.
        usort($results, static function (array $a, array $b): int {
            return [$a['rank'], mb_strtolower($a['title'])] <=> [$b['rank'], mb_strtolower($b['title'])];
        });

        $results = array_map(static function (array $r): array {
            unset($r['rank']);
            return $r;
        }, array_slice($results, 0, 20));

        Response::json(['results' => $results]);
    }

    private function rank(string $title, string $needle): int
    {
        $title  = mb_strtolower($title);
        $needle = mb_strtolower($needle);
        if ($title === $needle) return 0;
        if (str_starts_with($title, $needle)) return 1;
        if (str_contains($title, $needle)) return 2;
        return 3; // matched somewhere other than the title
    }
}
