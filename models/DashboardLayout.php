<?php
// =====================================================
// models/DashboardLayout.php
// =====================================================

class DashboardLayout
{
    private const ALLOWED_WIDGETS = [
        'tasks', 'calendar', 'habits', 'finance', 'subscriptions', 'workout',
        'projects', 'notes', 'stocks', 'transfer',
    ];

    /**
     * Where each section sits on the Today page. The page has a wide column of
     * things to do, a narrow one of money and body, and a strip of extras below;
     * a saved position orders sections within their own area.
     */
    public const AREAS = [
        'main' => ['tasks', 'calendar', 'habits'],
        'side' => ['finance', 'subscriptions', 'workout'],
        'more' => ['projects', 'notes', 'stocks', 'transfer'],
    ];

    private const DEFAULTS = [
        ['widget_key' => 'tasks',         'position' => 0, 'is_visible' => 1],
        ['widget_key' => 'calendar',      'position' => 1, 'is_visible' => 1],
        ['widget_key' => 'habits',        'position' => 2, 'is_visible' => 1],
        ['widget_key' => 'finance',       'position' => 3, 'is_visible' => 1],
        ['widget_key' => 'subscriptions', 'position' => 4, 'is_visible' => 1],
        ['widget_key' => 'workout',       'position' => 5, 'is_visible' => 1],
        ['widget_key' => 'projects',      'position' => 6, 'is_visible' => 1],
        ['widget_key' => 'notes',         'position' => 7, 'is_visible' => 1],
        ['widget_key' => 'stocks',        'position' => 8, 'is_visible' => 1],
        ['widget_key' => 'transfer',      'position' => 9, 'is_visible' => 1],
    ];

    /** The layout a new account gets, and what "reset" restores. */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    public static function getForUser(int $userId): array
    {
        $stmt = DB::run(
            'SELECT widget_key, position, is_visible FROM dashboard_layout
             WHERE user_id = ? ORDER BY position ASC',
            [$userId]
        );
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            self::seedDefaults($userId);
            return self::DEFAULTS;
        }

        // A section added after this account saved its layout shows up too, at
        // the end and switched on, instead of silently never appearing. It is
        // written the next time the layout is saved.
        $have = array_column($rows, 'widget_key');
        $next = (int)max(array_column($rows, 'position')) + 1;
        foreach (self::ALLOWED_WIDGETS as $key) {
            if (!in_array($key, $have, true)) {
                $rows[] = ['widget_key' => $key, 'position' => $next++, 'is_visible' => 1];
            }
        }
        return $rows;
    }

    public static function saveLayout(int $userId, array $widgets): void
    {
        // $widgets = [['widget_key' => 'tasks', 'position' => 0, 'is_visible' => 1], ...]
        $clean = [];
        $seen = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) continue;
            $key = (string)($widget['widget_key'] ?? '');
            if (!in_array($key, self::ALLOWED_WIDGETS, true) || isset($seen[$key])) continue;
            $seen[$key] = true;
            $clean[] = [
                'widget_key' => $key,
                'position' => max(0, min(99, (int)($widget['position'] ?? count($clean)))),
                'is_visible' => !empty($widget['is_visible']) ? 1 : 0,
            ];
        }
        if (empty($clean)) {
            throw new InvalidArgumentException('Invalid dashboard layout');
        }

        $db = DB::conn();
        $stmt = $db->prepare(
            'INSERT INTO dashboard_layout (user_id, widget_key, position, is_visible)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE position = VALUES(position), is_visible = VALUES(is_visible)'
        );
        foreach ($clean as $w) {
            $stmt->execute([
                $userId,
                $w['widget_key'],
                (int)$w['position'],
                (int)$w['is_visible']
            ]);
        }
    }

    private static function seedDefaults(int $userId): void
    {
        $db = DB::conn();
        $stmt = $db->prepare(
            'INSERT IGNORE INTO dashboard_layout (user_id, widget_key, position, is_visible)
             VALUES (?, ?, ?, ?)'
        );
        foreach (self::DEFAULTS as $w) {
            $stmt->execute([$userId, $w['widget_key'], $w['position'], $w['is_visible']]);
        }
    }
}
