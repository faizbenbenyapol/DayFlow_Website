<?php
// =====================================================
// core/AppMenus.php — the menus a user can show, hide and reorder
//
// One list for the sidebar, the settings page and the endpoint that saves
// the choice. They used to keep three copies, and Habits went missing from
// the sidebar when one of them was rewritten without it.
// =====================================================

final class AppMenus
{
    /** In default sidebar order. */
    public const KEYS = [
        'projects', 'tasks', 'notes', 'planner', 'focus', 'habits',
        'exercise', 'food-notes', 'finance', 'subscriptions', 'stocks',
        'ai', 'file-tools', 'transfer',
        'files', 'quick-notes', 'bookmarks',
    ];

    /** A saved order restricted to known menus, with any it lacks appended. */
    public static function ordered(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        return array_values(array_unique(array_merge(
            array_values(array_intersect($saved, self::KEYS)),
            self::KEYS
        )));
    }
}
