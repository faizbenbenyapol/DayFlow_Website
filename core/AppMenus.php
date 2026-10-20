<?php
// =====================================================
// core/AppMenus.php — the app's menus: names, groups, and what a user can
// show, hide and reorder
//
// One list for the side rail, the mobile tab bar, the "all menus" sheet, the
// command palette, the settings page and the endpoint that saves a choice.
// They used to keep their own copies, and Habits went missing from the rail
// when one of them was rewritten without it.
// =====================================================

final class AppMenus
{
    /**
     * The menus a user can show, hide and reorder, in default order. Today and
     * Review are not here: they are always on.
     */
    public const KEYS = [
        'tasks', 'projects', 'planner',
        'habits', 'focus', 'skills', 'exercise', 'food-notes',
        'finance', 'subscriptions', 'stocks',
        'notes', 'quick-notes', 'bookmarks', 'files', 'transfer',
        'file-tools', 'ai', 'calculator',
    ];

    /**
     * Group key => heading. The first five are the places: each has an index
     * tab and a pastel (docs/REDESIGN-V3.md §2). Tools has no tab; it is
     * reached from "ทั้งหมด" and the command palette.
     */
    public const GROUPS = [
        'today' => 'วันนี้',
        'work'  => 'งาน',
        'life'  => 'ชีวิต',
        'money' => 'เงิน',
        'vault' => 'คลัง',
        'tools' => 'เครื่องมือ',
    ];

    /** The groups that get an index tab, in order. */
    public const PLACES = ['today', 'work', 'life', 'money', 'vault'];

    /** The icon (views/partials/icons.php) each index tab shows on a phone. */
    public const PLACE_ICONS = ['today' => 'today', 'work' => 'tasks', 'life' => 'habits', 'money' => 'finance', 'vault' => 'notes'];

    /**
     * Every entry the menus can show. The label is what the page's own heading
     * says too, so a person who clicks "งาน" lands on a page titled "งาน".
     */
    public const MENUS = [
        'today'         => ['group' => 'today', 'label' => 'วันนี้',          'path' => '/'],
        'review'        => ['group' => 'today', 'label' => 'สรุปผล',          'path' => '/review'],
        'tasks'         => ['group' => 'work',  'label' => 'งาน',             'path' => '/tasks'],
        'projects'      => ['group' => 'work',  'label' => 'โปรเจค',          'path' => '/projects'],
        'planner'       => ['group' => 'work',  'label' => 'แพลนเนอร์',       'path' => '/planner'],
        'habits'        => ['group' => 'life',  'label' => 'นิสัย',           'path' => '/habits'],
        'focus'         => ['group' => 'life',  'label' => 'โฟกัส',           'path' => '/focus'],
        'skills'        => ['group' => 'life',  'label' => 'ทักษะ',           'path' => '/skills'],
        'exercise'      => ['group' => 'life',  'label' => 'ออกกำลังกาย',     'path' => '/exercise'],
        'food-notes'    => ['group' => 'life',  'label' => 'อาหาร',           'path' => '/food-notes'],
        'finance'       => ['group' => 'money', 'label' => 'รายรับรายจ่าย',   'path' => '/finance'],
        'subscriptions' => ['group' => 'money', 'label' => 'รายจ่ายประจำ',    'path' => '/subscriptions'],
        'stocks'        => ['group' => 'money', 'label' => 'หุ้น',            'path' => '/stocks'],
        'notes'         => ['group' => 'vault', 'label' => 'โน้ต',            'path' => '/notes'],
        'quick-notes'   => ['group' => 'vault', 'label' => 'จดด่วน',         'path' => '/quick-notes'],
        'bookmarks'     => ['group' => 'vault', 'label' => 'ลิงก์',           'path' => '/bookmarks'],
        'files'         => ['group' => 'vault', 'label' => 'ไฟล์',            'path' => '/files'],
        'transfer'      => ['group' => 'vault', 'label' => 'ส่งไฟล์',         'path' => '/transfer'],
        'file-tools'    => ['group' => 'tools', 'label' => 'แปลงไฟล์',        'path' => '/file-tools'],
        'ai'            => ['group' => 'tools', 'label' => 'ผู้ช่วย AI',      'path' => '/ai'],
        'calculator'    => ['group' => 'tools', 'label' => 'เครื่องคิดเลข',   'path' => '/calculator'],
    ];

    /**
     * The group a request path belongs to: the menu whose path it is or sits
     * under. Pages outside every menu (settings, a note editor reached by its
     * own path) fall back to tools, whose pastel is the neutral lilac.
     */
    public static function groupForPath(string $path): string
    {
        $key = self::menuForPath($path);
        return $key !== null ? self::MENUS[$key]['group'] : 'tools';
    }

    /** The menu a request path is, or sits under; null for a page outside every menu. */
    public static function menuForPath(string $path): ?string
    {
        if ($path === '/' || $path === '') return 'today';
        foreach (self::MENUS as $key => $menu) {
            if ($menu['path'] === '/') continue;
            if ($path === $menu['path'] || str_starts_with($path, $menu['path'] . '/')) return $key;
        }
        return null;
    }

    /** The two menus the mobile tab bar offers beside Today, until the user picks others. */
    public const DEFAULT_TABS = ['tasks', 'finance'];

    /** A saved order restricted to known menus, with any it lacks appended. */
    public static function ordered(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        return array_values(array_unique(array_merge(
            array_values(array_intersect($saved, self::KEYS)),
            self::KEYS
        )));
    }

    /** The name shown for a menu, or the key itself for one this build does not know. */
    public static function label(string $key): string
    {
        return self::MENUS[$key]['label'] ?? $key;
    }

    /**
     * What the rail shows: the groups that have something to show, each with
     * its entries. Groups stay where they are; a saved order only changes the
     * sequence inside a group, because a menu that jumped between groups
     * would no longer sit under its heading.
     *
     * @param list<string> $order  a saved order, as returned by ordered()
     * @param list<string> $hidden menus the user turned off
     * @return list<array{key: string, label: string, items: list<array{key: string, label: string, path: string}>}>
     */
    public static function grouped(array $order, array $hidden): array
    {
        $position = array_flip($order);
        $groups = [];

        foreach (self::GROUPS as $groupKey => $heading) {
            $items = [];
            foreach (self::MENUS as $key => $menu) {
                if ($menu['group'] !== $groupKey) continue;
                if (in_array($key, $hidden, true)) continue;
                $items[] = ['key' => $key, 'label' => $menu['label'], 'path' => $menu['path']];
            }
            // Today and Review keep their fixed place (they are not in $order).
            usort($items, fn(array $a, array $b): int => ($position[$a['key']] ?? -1) <=> ($position[$b['key']] ?? -1));

            if ($items !== []) {
                $groups[] = ['key' => $groupKey, 'label' => $heading, 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * The two menus for the mobile tab bar. A choice that is unknown, repeated
     * or hidden is skipped, and a free slot falls back to the first visible menu
     * the user has not already got in the bar.
     *
     * @param list<string> $hidden
     * @return array{0: string, 1: string}
     */
    public static function mobileTabs(mixed $saved, array $hidden): array
    {
        $usable = fn(mixed $key): bool => is_string($key) && in_array($key, self::KEYS, true) && !in_array($key, $hidden, true);

        $tabs = [];
        foreach (is_array($saved) ? $saved : [] as $key) {
            if ($usable($key) && !in_array($key, $tabs, true)) $tabs[] = $key;
            if (count($tabs) === 2) break;
        }

        foreach (array_merge(self::DEFAULT_TABS, self::KEYS) as $key) {
            if (count($tabs) === 2) break;
            if ($usable($key) && !in_array($key, $tabs, true)) $tabs[] = $key;
        }

        // Every menu hidden: fall back to the defaults so the bar is never empty.
        while (count($tabs) < 2) $tabs[] = self::DEFAULT_TABS[count($tabs)];

        return [$tabs[0], $tabs[1]];
    }
}
