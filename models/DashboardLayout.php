<?php
// =====================================================
// models/DashboardLayout.php
// =====================================================

class DashboardLayout
{
    private const DEFAULTS = [
        ['widget_key' => 'tasks',         'position' => 0, 'is_visible' => 1],
        ['widget_key' => 'calendar',      'position' => 1, 'is_visible' => 1],
        ['widget_key' => 'finance',       'position' => 2, 'is_visible' => 1],
        ['widget_key' => 'workout',       'position' => 3, 'is_visible' => 1],
        ['widget_key' => 'subscriptions', 'position' => 4, 'is_visible' => 1],
        ['widget_key' => 'projects',      'position' => 5, 'is_visible' => 1],
        ['widget_key' => 'notes',         'position' => 6, 'is_visible' => 1],
        ['widget_key' => 'stocks',        'position' => 7, 'is_visible' => 1],
        ['widget_key' => 'transfer',      'position' => 8, 'is_visible' => 1],
    ];

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
        return $rows;
    }

    public static function saveLayout(int $userId, array $widgets): void
    {
        // $widgets = [['widget_key' => 'tasks', 'position' => 0, 'is_visible' => 1], ...]
        $db = DB::conn();
        $stmt = $db->prepare(
            'INSERT INTO dashboard_layout (user_id, widget_key, position, is_visible)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE position = VALUES(position), is_visible = VALUES(is_visible)'
        );
        foreach ($widgets as $w) {
            $stmt->execute([
                $userId,
                $w['widget_key'],
                (int)$w['position'],
                isset($w['is_visible']) ? (int)$w['is_visible'] : 1
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
