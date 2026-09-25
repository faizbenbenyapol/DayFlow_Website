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

final class AccountData
{
    public const FORMAT  = 'dayflow-export';
    public const VERSION = 2;

    /**
     * Every table a backup carries, parents before children.
     *
     * Each entry lists the columns that point at another table in this list:
     * column => [parent table, required]. A required reference that does not
     * resolve drops the row; an optional one is cleared to NULL.
     */
    private const TABLES = [
        'tasks'               => [],
        'notes'               => [],
        'note_tags'           => [],
        'note_blocks'         => ['note_id' => ['notes', true]],
        'note_tag_relations'  => ['note_id' => ['notes', true], 'tag_id' => ['note_tags', true]],
        'calendar_events'     => [],
        'daily_todos'         => [],
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
        'quick_items'         => [],
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
    private const SETTINGS = ['theme', 'language', 'timezone', 'telegram_notify_events', 'hidden_menus', 'menu_order'];

    private const JSON_SETTINGS = ['telegram_notify_events', 'hidden_menus', 'menu_order'];

    /**
     * The user's data as a portable document.
     *
     * A table that does not exist on this server is left out; any other
     * failure is thrown, because a backup that silently misses a table is
     * worse than no backup.
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

            $sql = in_array($table, self::VIA_NOTE, true)
                ? "SELECT c.* FROM `$table` c JOIN notes n ON n.id = c.note_id WHERE n.user_id = ?"
                : "SELECT * FROM `$table` WHERE user_id = ?";
            $data[$table] = DB::run($sql, [$userId])->fetchAll();
        }

        return $data;
    }

    /**
     * Restores a backup into the user's account and returns rows imported per table.
     *
     * Only the tables present in the file are replaced, so an older backup
     * that predates a feature leaves that feature's data alone. Ids are never
     * taken from the file: every row gets a fresh one and the references
     * between rows are rewritten to match, which lets a backup be restored
     * into another account or onto another server without colliding.
     *
     * Everything in $data is untrusted: keys are matched against real
     * columns, values must be scalars, and a reference is only honoured when
     * it points at a row this same import created.
     *
     * @throws InvalidArgumentException with a message fit to show the user
     */
    public static function import(int $userId, array $data): array
    {
        if (isset($data['format']) && $data['format'] !== self::FORMAT) {
            throw new InvalidArgumentException('ไฟล์นี้ไม่ใช่ไฟล์สำรองข้อมูลของ DayFlow');
        }
        if ((int)($data['version'] ?? 1) > self::VERSION) {
            throw new InvalidArgumentException('ไฟล์นี้มาจาก DayFlow เวอร์ชันใหม่กว่า กรุณาอัปเดตระบบก่อนนำเข้า');
        }

        $present = array_values(array_filter(
            array_keys(self::TABLES),
            fn(string $t): bool => isset($data[$t]) && is_array($data[$t]) && self::columns($t) !== []
        ));
        $hasSettings = isset($data['settings']) && is_array($data['settings']);

        if ($present === [] && !$hasSettings) {
            throw new InvalidArgumentException('ไม่พบข้อมูล DayFlow ในไฟล์นี้');
        }

        $conn = DB::conn();
        $conn->beginTransaction();

        try {
            // Children of a replaced table go with it through ON DELETE
            // CASCADE (blocks with notes, logs with habits and skills), and
            // optional references are cleared by ON DELETE SET NULL.
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

            if ($hasSettings) self::importSettings($userId, $data['settings']);

            $conn->commit();
            return $counts;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    private static function importTable(int $userId, string $table, array $rows, array &$idMap): int
    {
        $columns = self::columns($table);
        $count = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            // Unknown keys are dropped, never interpolated into the SQL.
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
                // A value the column refuses (bad date, unknown enum, a
                // duplicate) is the file's fault, not the server's.
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
                if (in_array($value, User::THEMES, true)) $values[$key] = $value;
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
    private static function columns(string $table): array
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
