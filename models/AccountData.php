<?php
// =====================================================
// models/AccountData.php — personal backup (export / import)
//
// Export and import read the same table list, so whatever a backup contains
// is exactly what a restore puts back. Before this, the two lists had drifted
// apart: import deleted food notes, skills and stocks that export never wrote,
// so restoring a backup quietly lost them.
//
// Deliberately left out:
//   - projects and their tasks, members, chat and activity: they are shared
//     with other accounts, and replacing them would pull the ground from under
//     every collaborator;
//   - files and stock screenshots: the rows point at files on disk that a
//     JSON document does not carry;
//   - API keys, 2FA secrets, remembered devices, push subscriptions and the
//     Telegram token: credentials never travel in a portable file;
//   - share links and file transfers: public tokens are not re-issued blindly.
// =====================================================

require_once ROOT . '/models/LegacyTaskMapper.php';

final class AccountData
{
    public const FORMAT  = 'dayflow-export';
    public const VERSION = 3;

    /**
     * Every table a backup carries, parents before children.
     *
     * Each entry lists the columns that point at another table in this list:
     * column => [parent table, required]. A required reference that does not
     * resolve drops the row; an optional one is cleared to NULL.
     */
    private const TABLES = [
        'tasks'               => [],
        'task_subtasks'       => ['task_id' => ['tasks', true]],
        'notes'               => [],
        'note_tags'           => [],
        'note_blocks'         => ['note_id' => ['notes', true]],
        'note_tag_relations'  => ['note_id' => ['notes', true], 'tag_id' => ['note_tags', true]],
        'calendar_events'     => [],
        'exercise_categories' => [],
        'workouts'            => [],
        'finance_categories'  => [],
        'finances'            => ['category_id' => ['finance_categories', false]],
        'subscriptions'       => [],
        'dashboard_layout'    => [],
        'food_notes'          => [],
        'skills'              => [],
        'skill_logs'          => ['skill_id' => ['skills', true]],
        'focus_sessions'      => ['task_id' => ['tasks', false]],
        'habits'              => [],
        'habit_logs'          => ['habit_id' => ['habits', true]],
        'bookmarks'           => [],
        'stock_transactions'  => [],
        'stock_watchlists'    => [],
        'stock_capital_flows' => [],
        'ai_generations'      => [],
    ];

    /** Tables without user_id of their own, reached through their note. */
    private const VIA_NOTE = ['note_blocks', 'note_tag_relations'];

    /** Tables whose id is a UUID the app generates, not AUTO_INCREMENT. */
    private const UUID_IDS = ['skills', 'skill_logs'];

    /** user_settings columns a backup may carry. Telegram credentials are not among them. */
    private const SETTINGS = ['theme', 'day_color', 'desk', 'language', 'timezone', 'telegram_notify_events', 'hidden_menus', 'menu_order', 'mobile_tabs'];

    private const JSON_SETTINGS = ['telegram_notify_events', 'hidden_menus', 'menu_order', 'mobile_tabs'];

    /**
     * ส่งออกข้อมูลผู้ใช้เป็นไฟล์ JSON ที่ย้ายไปบัญชีอื่นได้
     *
     * ตารางที่ไม่มีในเซิร์ฟเวอร์จะถูกข้าม ส่วนความผิดพลาดอื่นจะ throw
     * เพราะ backup ที่เงียบๆ ขาดตารางย่อมแย่กว่าไม่มี backup
     *
     * @param int $userId รหัสผู้ใช้
     * @return array ข้อมูลทั้งหมดที่จะส่งออก
     */
    public static function export(int $userId): array
    {
        $data = [
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => date('c'),
            'user'        => User::findById($userId),
            'settings'    => self::exportSettings($userId),
        ];

        foreach (array_keys(self::TABLES) as $table) {
            if (self::columns($table) === []) continue;

            if (in_array($table, self::VIA_NOTE, true)) {
                $sql = "SELECT c.* FROM `$table` c JOIN notes n ON n.id = c.note_id WHERE n.user_id = ?";
            } else {
                $columns = array_keys(self::columns($table));
                if ($table === 'tasks') {
                    // คอลัมน์ legacy ไม่ควรเดินทางไปกับ backup เพราะ UNIQUE key จะชนกัน
                    $columns = array_values(array_diff($columns, ['legacy_source', 'legacy_id']));
                }
                $sql = 'SELECT `' . implode('`, `', $columns) . "` FROM `$table` WHERE user_id = ?";
            }
            $data[$table] = DB::run($sql, [$userId])->fetchAll();
        }

        return $data;
    }

    /**
     * นำเข้า backup กลับเข้าบัญชีผู้ใช้ และคืนจำนวนแถวที่นำเข้าแยกตามตาราง
     *
     * มีเฉพาะตารางที่ปรากฏในไฟล์จึงจะถูกแทนที่ backup เก่าที่ยังไม่มีฟีเจอร์
     * ใหม่จะไม่แตะข้อมูลฟีเจอร์นั้น id จากไฟล์จะไม่ถูกนำมาใช้ แต่สร้าง id
     * ใหม่และเขียน reference ใหม่ เพื่อให้ย้ายไปบัญชี/เซิร์ฟเวอร์อื่นได้
     * โดยไม่ชนกัน
     *
     * daily_todos และ quick_items จาก backup เก่าจะถูกแปลงเป็น tasks
     *
     * @param int $userId รหัสผู้ใช้ปลายทาง
     * @param array<string, mixed> $data ข้อมูล backup
     * @return array<string, int> จำนวนแถวที่นำเข้าแยกตามตาราง
     * @throws InvalidArgumentException พร้อมข้อความที่แสดงให้ผู้ใช้เข้าใจ
     */
    public static function import(int $userId, array $data): array
    {
        if (isset($data['format']) && $data['format'] !== self::FORMAT) {
            throw new InvalidArgumentException('ไฟล์นี้ไม่ใช่ไฟล์สำรองข้อมูลของ DayFlow');
        }
        if ((int)($data['version'] ?? 1) > self::VERSION) {
            throw new InvalidArgumentException('ไฟล์นี้มาจาก DayFlow เวอร์ชันใหม่กว่า กรุณาอัปเดตระบบก่อนนำเข้า');
        }

        // จำไว้ว่าไฟล์ต้นฉบับมีส่วน tasks จริงหรือไม่ ถ้าไม่มี
        // ต้องไม่ลบงานเดิมของผู้ใช้ แต่ยังนำเข้าแถวที่แปลงจาก legacy ได้
        $hadTasksSection = isset($data['tasks']) && is_array($data['tasks']);

        if (array_key_exists('tasks', $data) && !is_array($data['tasks'])) {
            throw new InvalidArgumentException('ไฟล์มีข้อมูลที่ไม่ถูกต้องในส่วน tasks');
        }

        self::convertLegacyTasks($userId, $data);

        $present = array_values(array_filter(
            array_keys(self::TABLES),
            fn(string $t): bool => ($t !== 'tasks' || $hadTasksSection) && isset($data[$t]) && is_array($data[$t]) && self::columns($t) !== []
        ));
        $hasSettings = isset($data['settings']) && is_array($data['settings']);

        $hasConvertedTasks = !$hadTasksSection && !empty($data['tasks']);
        if ($present === [] && !$hasSettings && !$hasConvertedTasks) {
            throw new InvalidArgumentException('ไม่พบข้อมูล DayFlow ในไฟล์นี้');
        }

        $conn = DB::conn();
        $conn->beginTransaction();

        try {
            // ตารางที่ถูกแทนที่จะลบผ่าน ON DELETE CASCADE
            // (note_blocks, habit_logs, skill_logs) ส่วน reference ที่ optional
            // จะถูกเคลียร์เป็น NULL ผ่าน ON DELETE SET NULL
            foreach ($present as $table) {
                if (!in_array($table, self::VIA_NOTE, true)) {
                    DB::run("DELETE FROM `$table` WHERE user_id = ?", [$userId]);
                }
            }

            $idMap  = [];
            $counts = [];
            foreach ($present as $table) {
                $counts[$table] = self::importTable($userId, $table, $data[$table], $idMap);
            }

            // ถ้าไฟล์ไม่มีส่วน tasks ให้นำเข้าแถวที่แปลงจาก daily_todos/quick_items
            // เพิ่มเข้าไปโดยไม่ลบงานเดิมของผู้ใช้
            if (!$hadTasksSection && !empty($data['tasks'])) {
                $counts['tasks'] = ($counts['tasks'] ?? 0) + self::importTable($userId, 'tasks', $data['tasks'], $idMap);
            }

            if ($hasSettings) self::importSettings($userId, $data['settings']);

            $conn->commit();
            return $counts;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * แปลง daily_todos และ quick_items จาก backup เก่าให้เป็น tasks
     *
     * แถวที่แปลงจะถูกเพิ่มเข้าไปใน $data['tasks'] เพื่อให้ importTable นำเข้าเป็น tasks
     * ไม่คืนจำนวนแยก เพราะจะนับซ้ำกับ tasks ที่นำเข้าจริง
     *
     * @param int $userId ผู้ใช้ปลายทาง
     * @param array<string, mixed> $data ข้อมูล backup (อาจถูกแก้ไขโดยเพิ่ม tasks)
     * @throws InvalidArgumentException เมื่อพบค่าที่ไม่ถูกต้อง
     */
    private static function convertLegacyTasks(int $userId, array &$data): void
    {
        $tasks = [];
        $map   = [
            'daily_todos' => 'fromDailyTodo',
            'quick_items' => 'fromQuickItem',
        ];

        foreach ($map as $source => $method) {
            if (!isset($data[$source]) || !is_array($data[$source])) continue;

            foreach ($data[$source] as $row) {
                if (!is_array($row)) continue;
                foreach ($row as $value) {
                    if ($value !== null && !is_scalar($value)) {
                        throw new InvalidArgumentException("ไฟล์มีข้อมูลที่ไม่ถูกต้องในส่วน {$source}");
                    }
                }

                if ($source === 'daily_todos') {
                    $date = (string)($row['todo_date'] ?? '');
                    $parsed = DateTime::createFromFormat('Y-m-d', $date);
                    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                        throw new InvalidArgumentException('ไฟล์มีข้อมูลที่ไม่ถูกต้องในส่วน daily_todos');
                    }
                }

                $row['user_id'] = $userId;
                $task = LegacyTaskMapper::$method($row);
                unset($task['legacy_source'], $task['legacy_id']);
                // แถวที่แปลงไม่มี id เพื่อไม่ให้ชนกับ id ของ tasks จริงใน backup
                $tasks[] = $task;
            }
        }

        if ($tasks !== []) {
            $data['tasks'] = array_merge($data['tasks'] ?? [], $tasks);
        }
    }

    private static function importTable(int $userId, string $table, array $rows, array &$idMap): int
    {
        $columns = self::columns($table);
        $count = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            // คอลัมน์ legacy ของ tasks ไม่ควรนำเข้า เพราะ UNIQUE key จะชนกัน
            if ($table === 'tasks') {
                unset($row['legacy_source'], $row['legacy_id']);

                // backup เก่าอาจไม่มี completed_at แต่มีงาน done อยู่
                // ต้องเติม completed_at เพื่อให้งานยังคงแสดงใน inbox ตามกฎ 7 วัน
                if (($row['status'] ?? '') === 'done' && empty($row['completed_at'])) {
                    $row['completed_at'] = !empty($row['updated_at']) ? $row['updated_at'] : date('Y-m-d H:i:s');
                }
            }

            // คีย์ที่ไม่รู้จะถูกทิ้ง ไม่ส่งต่อเข้า SQL
            $row = array_intersect_key($row, $columns);
            if ($row === []) continue;
            foreach ($row as $value) {
                if ($value !== null && !is_scalar($value)) {
                    throw new InvalidArgumentException("ไฟล์มีข้อมูลที่ไม่ถูกต้องในส่วน {$table}");
                }
            }

            $oldId = $row['id'] ?? null;
            unset($row['id']);
            if (isset($columns['user_id'])) $row['user_id'] = $userId;

            if (!self::remapReferences($table, $row, $idMap)) continue;

            if (in_array($table, self::UUID_IDS, true)) $row['id'] = uuid4();

            $names = implode('`, `', array_keys($row));
            $marks = implode(', ', array_fill(0, count($row), '?'));
            try {
                DB::run("INSERT INTO `$table` (`$names`) VALUES ($marks)", array_values($row));
            } catch (PDOException $e) {
                // ค่าที่คอลัมน์ปฏิเสธ (วันที่ผิด enum ซ้ำ) เป็นความผิดของไฟล์
                throw new InvalidArgumentException("ไฟล์มีข้อมูลที่ไม่ถูกต้องในส่วน {$table}", 0, $e);
            }

            if ($oldId !== null && $oldId !== '') {
                $idMap[$table][(string)$oldId] = $row['id'] ?? (string)DB::conn()->lastInsertId();
            }
            $count++;
        }

        return $count;
    }

    /**
     * Points a row's references at the rows this import created in their
     * place. Returns false when a required parent is missing.
     */
    private static function remapReferences(string $table, array &$row, array $idMap): bool
    {
        foreach (self::TABLES[$table] as $column => [$parent, $required]) {
            $old = $row[$column] ?? null;
            $new = ($old === null || $old === '') ? null : ($idMap[$parent][(string)$old] ?? null);

            if ($new === null) {
                if ($required) return false;
                if (array_key_exists($column, $row)) $row[$column] = null;
            } else {
                $row[$column] = $new;
            }
        }
        return true;
    }

    private static function exportSettings(int $userId): array
    {
        $available = array_values(array_intersect(self::SETTINGS, array_keys(self::columns('user_settings'))));
        if ($available === []) return [];

        $row = DB::run(
            'SELECT `' . implode('`, `', $available) . '` FROM user_settings WHERE user_id = ?',
            [$userId]
        )->fetch();
        return $row ?: [];
    }

    /** Restores the settings that pass validation; anything else keeps its current value. */
    private static function importSettings(int $userId, array $settings): void
    {
        $columns = self::columns('user_settings');
        $values = [];

        foreach (self::SETTINGS as $key) {
            if (!isset($columns[$key]) || !array_key_exists($key, $settings)) continue;
            $value = $settings[$key];

            if (in_array($key, self::JSON_SETTINGS, true)) {
                if (is_string($value)) $value = json_decode($value, true);
                if ($value !== null && !is_array($value)) continue;
                $values[$key] = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif ($key === 'theme') {
                // A backup from before v2 may name one of the four palettes that
                // no longer exist; the closest thing that does is light.
                if (in_array($value, User::LEGACY_THEMES, true)) $value = 'light';
                if (in_array($value, User::THEMES, true)) $values[$key] = $value;
            } elseif ($key === 'day_color') {
                if (in_array($value, [0, 1, '0', '1', true, false], true)) $values[$key] = (int)$value;
            } elseif ($key === 'desk') {
                if (is_string($value) && isset(User::DESKS[$value])) $values[$key] = $value;
            } elseif ($key === 'timezone') {
                if (is_string($value) && in_array($value, DateTimeZone::listIdentifiers(), true)) $values[$key] = $value;
            } elseif ($key === 'language') {
                if (is_string($value) && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $value)) $values[$key] = $value;
            }
        }

        if ($values === []) return;

        $names = array_keys($values);
        DB::run(
            'INSERT INTO user_settings (user_id, `' . implode('`, `', $names) . '`) VALUES (?' . str_repeat(', ?', count($names)) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($n) => "`$n` = VALUES(`$n`)", $names)),
            array_merge([$userId], array_values($values))
        );
    }

    /**
     * The real columns of a table as a name => true map; empty when the table
     * does not exist here.
     */
    public static function columns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];

        $names = DB::run(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->fetchAll(PDO::FETCH_COLUMN);
        return $cache[$table] = array_fill_keys($names, true);
    }
}
