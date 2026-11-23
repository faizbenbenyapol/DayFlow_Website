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
        'notes', 'bookmarks', 'files', 'transfer',
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
        'bookmarks'     => ['group' => 'vault', 'label' => 'ลิงก์',           'path' => '/bookmarks'],
        'files'         => ['group' => 'vault', 'label' => 'ไฟล์',            'path' => '/files'],
        'transfer'      => ['group' => 'vault', 'label' => 'ส่งไฟล์',         'path' => '/transfer'],
        'file-tools'    => ['group' => 'tools', 'label' => 'แปลงไฟล์',        'path' => '/file-tools'],
        'ai'            => ['group' => 'tools', 'label' => 'ผู้ช่วย AI',      'path' => '/ai'],
        'calculator'    => ['group' => 'tools', 'label' => 'เครื่องคิดเลข',   'path' => '/calculator'],
    ];

    /** ชื่อหมวดที่ผู้ใช้ตั้งเองยาวได้ไม่เกินนี้ (EDGE-4) */
    public const MAX_NAME_CHARS = 30;

    /** การจัดเมนูตั้งต้น: ไม่เปลี่ยนชื่อหมวด ไม่ย้ายเมนู ใช้เมื่อไม่มีค่าผู้ใช้ เช่นหน้าที่เปิดผ่านลิงก์แชร์ */
    public const NO_LAYOUT = ['names' => [], 'groups' => []];

    /**
     * อ่านการจัดเมนูที่ผู้ใช้บันทึก (user_settings.menu_layout) ให้เหลือเฉพาะค่าที่ใช้ได้
     * ตรวจทุกครั้งที่อ่าน ไม่ใช่แค่ตอนบันทึก เพราะค่ามาจากไฟล์สำรองที่นำเข้าได้ด้วย
     *
     * @param mixed $saved ค่าที่ json_decode แล้ว รูปแบบ {"names": {...}, "groups": {...}}
     * @return array{names: array<string, string>, groups: array<string, string>}
     *   names = หมวด → ชื่อที่ตั้งเอง (ตัดช่องว่างแล้ว ไม่ว่าง ไม่เกิน 30 ตัว ไม่ซ้ำชื่อตั้งต้น)
     *   groups = เมนู → หมวดที่ย้ายไป (เฉพาะเมนูที่ย้ายได้และย้ายไปหมวดอื่นจริง)
     *   คีย์ที่ไม่รู้จัก เช่นเมนูที่เอาออกจากแอปไปแล้ว (EDGE-5) ถูกข้ามเงียบ ๆ
     */
    public static function layout(mixed $saved): array
    {
        $names = [];
        foreach (self::layoutPart($saved, 'names') as $group => $name) {
            if (!is_string($group) || !isset(self::GROUPS[$group]) || !is_string($name)) continue;
            $name = self::cleanName($name);
            if ($name === '' || mb_strlen($name) > self::MAX_NAME_CHARS || $name === self::GROUPS[$group]) continue;
            $names[$group] = $name;
        }

        // วันนี้กับสรุปผลไม่อยู่ใน KEYS จึงย้ายไม่ได้ตาม REQ-10 โดยไม่ต้องเช็กแยก
        $groups = [];
        foreach (self::layoutPart($saved, 'groups') as $menu => $group) {
            if (!in_array($menu, self::KEYS, true) || !is_string($group) || !isset(self::GROUPS[$group])) continue;
            if ($group !== self::MENUS[$menu]['group']) $groups[$menu] = $group;
        }

        return ['names' => $names, 'groups' => $groups];
    }

    /**
     * ตรวจชื่อหมวดที่ผู้ใช้พิมพ์ก่อนบันทึก (EDGE-4) ช่องว่างได้ (= กลับชื่อเดิม)
     * แต่ยาวเกินต้องบอกผู้ใช้ ไม่ตัดให้เงียบ ๆ
     *
     * @return string|null ข้อความ error ภาษาไทย หรือ null ถ้าใช้ได้ทั้งหมด
     */
    public static function nameError(mixed $names): ?string
    {
        if (!is_array($names)) return 'รูปแบบชื่อหมวดไม่ถูกต้อง';
        foreach ($names as $name) {
            if (!is_string($name)) return 'รูปแบบชื่อหมวดไม่ถูกต้อง';
            if (mb_strlen(self::cleanName($name)) > self::MAX_NAME_CHARS) {
                return 'ชื่อหมวดยาวได้ไม่เกิน ' . self::MAX_NAME_CHARS . ' ตัวอักษร ลองตั้งให้สั้นลง';
            }
        }
        return null;
    }

    /** ส่วน names หรือ groups ของค่าที่บันทึก ถ้าไม่ใช่ array คืน [] */
    private static function layoutPart(mixed $saved, string $part): array
    {
        return is_array($saved) && is_array($saved[$part] ?? null) ? $saved[$part] : [];
    }

    /** ตัดช่องว่างหัวท้าย นับ NBSP ที่วางมาจากที่อื่นเป็นช่องว่างด้วย (trim ของ PHP ไม่ตัด NBSP) */
    private static function cleanName(string $name): string
    {
        return trim(str_replace("\u{00A0}", ' ', $name));
    }

    /**
     * หมวดที่เมนูอยู่: หมวดที่ผู้ใช้ย้ายไป หรือหมวดตั้งต้น
     * @param array{names: array<string, string>, groups: array<string, string>} $layout จาก layout()
     */
    public static function groupOf(string $key, array $layout = self::NO_LAYOUT): string
    {
        return $layout['groups'][$key] ?? self::MENUS[$key]['group'];
    }

    /**
     * ชื่อหมวดที่แสดงบนแท็บ แถบข้าง และหน้าทั้งหมด: ชื่อที่ผู้ใช้ตั้ง หรือชื่อตั้งต้น
     * @param array{names: array<string, string>, groups: array<string, string>} $layout จาก layout()
     */
    public static function groupName(string $group, array $layout = self::NO_LAYOUT): string
    {
        return $layout['names'][$group] ?? self::GROUPS[$group];
    }

    /**
     * หมวดของหน้าที่เปิด (เลือกสีพาสเทลของหน้า) ตามหมวดที่ผู้ใช้ย้ายเมนูไป
     * หน้าที่ไม่ได้อยู่ใต้เมนูไหน (ตั้งค่า, ตัวแก้โน้ตที่เปิดจาก path ของมันเอง) ได้ tools ซึ่งเป็นสีม่วงกลาง ๆ
     * @param array{names: array<string, string>, groups: array<string, string>} $layout จาก layout()
     */
    public static function groupForPath(string $path, array $layout = self::NO_LAYOUT): string
    {
        $key = self::menuForPath($path);
        return $key !== null ? self::groupOf($key, $layout) : 'tools';
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
     * สิ่งที่แถบข้าง แท็บบน และหน้าทั้งหมดแสดง: หมวดที่มีเมนูเหลือ พร้อมเมนูของมัน
     * หมวดเรียงตามค่าตั้งต้นเสมอ ลำดับที่บันทึกมีผลแค่ภายในหมวด หมวดที่ไม่เหลือเมนูไม่ถูกคืนมา (REQ-11)
     *
     * @param list<string> $order  ลำดับที่บันทึก จาก ordered()
     * @param list<string> $hidden เมนูที่ผู้ใช้ปิด
     * @param array{names: array<string, string>, groups: array<string, string>} $layout จาก layout()
     * @return list<array{key: string, label: string, items: list<array{key: string, label: string, path: string}>}>
     */
    public static function grouped(array $order, array $hidden, array $layout = self::NO_LAYOUT): array
    {
        $position = array_flip($order);
        $isHidden = array_flip($hidden);

        // แจกเมนูเข้าหมวดรอบเดียว ตามหมวดที่ผู้ใช้ย้ายไป
        $itemsByGroup = [];
        foreach (self::MENUS as $key => $menu) {
            if (isset($isHidden[$key])) continue;
            $itemsByGroup[self::groupOf($key, $layout)][] = ['key' => $key, 'label' => $menu['label'], 'path' => $menu['path']];
        }

        $groups = [];
        foreach (array_keys(self::GROUPS) as $groupKey) {
            $items = $itemsByGroup[$groupKey] ?? [];
            if ($items === []) continue;
            // วันนี้และสรุปผลไม่อยู่ใน $order ได้ -1 จึงอยู่หน้าสุดของหมวดเสมอ
            usort($items, fn(array $a, array $b): int => ($position[$a['key']] ?? -1) <=> ($position[$b['key']] ?? -1));
            $groups[] = ['key' => $groupKey, 'label' => self::groupName($groupKey, $layout), 'items' => $items];
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
