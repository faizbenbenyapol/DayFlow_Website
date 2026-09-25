<?php
// =====================================================
// models/User.php
// =====================================================

class User
{
    public static function findById(int $id): ?array
    {
        $stmt = DB::run(
            'SELECT id, username, email, display_name, avatar_path, created_at FROM users WHERE id = ?',
            [$id]
        );
        return $stmt->fetch() ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = DB::run(
            'SELECT * FROM users WHERE email = ?',
            [$email]
        );
        return $stmt->fetch() ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $stmt = DB::run(
            'SELECT * FROM users WHERE username = ?',
            [$username]
        );
        return $stmt->fetch() ?: null;
    }

    public static function findDemo(): ?array
    {
        $stmt = DB::run('SELECT id FROM users WHERE is_demo = 1 LIMIT 1');
        return $stmt->fetch() ?: null;
    }

    public static function create(string $username, string $email, string $password, string $displayName = ''): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::run(
            'INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)',
            [$username, $email, $hash, $displayName ?: $username]
        );
        $userId = (int)DB::conn()->lastInsertId();

        // Create default settings
        DB::run(
            'INSERT INTO user_settings (user_id, theme, timezone) VALUES (?, ?, ?)',
            [$userId, 'light', 'Asia/Bangkok']
        );

        return $userId;
    }

    public static function updateProfile(int $userId, string $displayName, string $email): bool
    {
        $stmt = DB::run(
            'UPDATE users SET display_name = ?, email = ?, updated_at = NOW() WHERE id = ?',
            [$displayName, $email, $userId]
        );
        return $stmt->rowCount() > 0;
    }

    public static function updatePassword(int $userId, string $newPassword): bool
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = DB::run(
            'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
            [$hash, $userId]
        );
        return $stmt->rowCount() > 0;
    }

    public static function updateAvatar(int $userId, string $path): void
    {
        DB::run('UPDATE users SET avatar_path = ? WHERE id = ?', [$path, $userId]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * The stored hash for one account, or null if there is none.
     *
     * findById() deliberately leaves the hash out — its result is handed to
     * views and to Auth::login() — so callers that need to check a password
     * ask for it explicitly.
     */
    public static function passwordHash(int $id): ?string
    {
        $hash = DB::run('SELECT password_hash FROM users WHERE id = ?', [$id])->fetchColumn();
        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public static function emailExists(string $email, int $excludeId = 0): bool
    {
        $stmt = DB::run(
            'SELECT id FROM users WHERE email = ? AND id != ?',
            [$email, $excludeId]
        );
        return (bool)$stmt->fetch();
    }

    public static function usernameExists(string $username): bool
    {
        $stmt = DB::run('SELECT id FROM users WHERE username = ?', [$username]);
        return (bool)$stmt->fetch();
    }

    public static function getSettings(int $userId): array
    {
        try {
            DB::run('SELECT telegram_bot_token FROM user_settings LIMIT 1');
        } catch (\Exception $e) {
            try {
                DB::run('ALTER TABLE user_settings ADD COLUMN telegram_bot_token VARCHAR(255) DEFAULT NULL');
                DB::run('ALTER TABLE user_settings ADD COLUMN telegram_chat_id VARCHAR(100) DEFAULT NULL');
                DB::run('ALTER TABLE user_settings ADD COLUMN telegram_notify_events JSON DEFAULT NULL');
            } catch (\Exception $e2) {
                // Ignore if they already exist but threw for some other reason
            }
        }
        $stmt = DB::run('SELECT * FROM user_settings WHERE user_id = ?', [$userId]);
        return $stmt->fetch() ?: ['user_id' => $userId, 'theme' => 'light', 'timezone' => 'Asia/Bangkok'];
    }

    public static function updateTheme(int $userId, string $theme): void
    {
        DB::run(
            'INSERT INTO user_settings (user_id, theme) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE theme = VALUES(theme)',
            [$userId, $theme]
        );
    }

    public static function updateTimezone(int $userId, string $tz): void
    {
        DB::run(
            'INSERT INTO user_settings (user_id, timezone) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE timezone = VALUES(timezone)',
            [$userId, $tz]
        );
    }

    public static function updateHiddenMenus(int $userId, ?string $hiddenMenus): void
    {
        DB::run(
            'INSERT INTO user_settings (user_id, hidden_menus) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE hidden_menus = VALUES(hidden_menus)',
            [$userId, $hiddenMenus]
        );
    }

    public static function updateMenuOrder(int $userId, string $menuOrder): void
    {
        DB::run(
            'INSERT INTO user_settings (user_id, menu_order) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE menu_order = VALUES(menu_order)',
            [$userId, $menuOrder]
        );
    }

    public static function updateTelegramSettings(int $userId, ?string $botToken, ?string $chatId, ?string $notifyEvents): void
    {
        if ($botToken === null || trim($botToken) === '') {
            $existing = self::getSettings($userId);
            $botToken = (string)($existing['telegram_bot_token'] ?? '');
        } else {
            $botToken = 'tg1:' . appEncrypt(trim($botToken));
        }
        DB::run(
            'INSERT INTO user_settings (user_id, telegram_bot_token, telegram_chat_id, telegram_notify_events) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE telegram_bot_token = VALUES(telegram_bot_token), telegram_chat_id = VALUES(telegram_chat_id), telegram_notify_events = VALUES(telegram_notify_events)',
            [$userId, $botToken, $chatId, $notifyEvents]
        );
    }

    public static function decryptTelegramToken(?string $stored): string
    {
        if (!$stored) return '';
        if (str_starts_with($stored, 'tg1:')) {
            return appDecrypt(substr($stored, 4));
        }
        // Backward compatibility for tokens saved before encryption was added.
        return $stored;
    }

    public static function deleteAccount(int $userId): bool
    {
        // CASCADE on FK will remove child rows
        $stmt = DB::run('DELETE FROM users WHERE id = ?', [$userId]);
        return $stmt->rowCount() > 0;
    }

    public static function exportAllData(int $userId): array
    {
        $user = self::findById($userId);
        $settings = self::getSettings($userId);
        // Never include integration credentials in a portable export.
        unset($settings['telegram_bot_token'], $settings['telegram_chat_id']);
        $tables = [
            'tasks', 'notes', 'note_blocks', 'note_tags', 'note_tag_relations',
            'calendar_events', 'daily_todos', 'workouts',
            'finance_categories', 'finances', 'subscriptions', 'dashboard_layout'
        ];
        $data = ['user' => $user, 'settings' => $settings, 'exported_at' => date('c')];
        foreach ($tables as $t) {
            try {
                // note_blocks & note_tag_relations don't have user_id directly — skip or join
                if ($t === 'note_blocks') {
                    $stmt = DB::run("SELECT nb.* FROM note_blocks nb JOIN notes n ON nb.note_id = n.id WHERE n.user_id = ?", [$userId]);
                } elseif ($t === 'note_tag_relations') {
                    $stmt = DB::run("SELECT r.* FROM note_tag_relations r JOIN notes n ON r.note_id = n.id WHERE n.user_id = ?", [$userId]);
                } else {
                    $stmt = DB::run("SELECT * FROM $t WHERE user_id = ?", [$userId]);
                }
                $data[$t] = $stmt->fetchAll();
            } catch (\Throwable $e) {
                $data[$t] = [];
            }
        }
        return $data;
    }

    /**
     * Replaces the user's data with the rows of an export file.
     *
     * Everything in $data is attacker-controlled. Column names are matched
     * against the table's real columns (they used to be pasted straight into
     * the SQL, which made the file an injection vector), values must be
     * scalars, and a row that points at a note, tag, category or skill must
     * point at one this same import created — otherwise it could attach itself
     * to another account's rows. Foreign-key checks stay on for the same reason.
     *
     * @throws InvalidArgumentException when a value is not a scalar
     */
    public static function importAllData(int $userId, array $data): bool
    {
        // Parents before children: the ownership checks below rely on it.
        $tables = [
            'tasks', 'notes', 'note_blocks', 'note_tags', 'note_tag_relations',
            'calendar_events', 'daily_todos', 'workouts',
            'finance_categories', 'finances', 'subscriptions', 'dashboard_layout',
            'food_notes', 'skills', 'skill_logs', 'stock_transactions', 'stock_watchlists'
        ];

        // Tables whose rows carry user_id directly.
        $userTables = [
            'tasks', 'notes', 'note_tags', 'calendar_events', 'daily_todos',
            'workouts', 'finance_categories', 'finances', 'subscriptions',
            'dashboard_layout', 'food_notes', 'skills', 'skill_logs',
            'stock_transactions', 'stock_watchlists'
        ];

        // Ids created by this import, per parent table. Every earlier row of
        // the user is deleted first, so these are exactly the rows they own.
        $owned = ['notes' => [], 'note_tags' => [], 'finance_categories' => [], 'skills' => []];

        $conn = DB::conn();
        $conn->beginTransaction();

        try {
            // note_blocks and note_tag_relations go with their notes and tags
            // through ON DELETE CASCADE.
            foreach ($userTables as $t) {
                DB::run("DELETE FROM `$t` WHERE user_id = ?", [$userId]);
            }

            foreach ($tables as $t) {
                if (!isset($data[$t]) || !is_array($data[$t])) continue;

                $columns = self::tableColumns($t);
                if ($columns === []) continue;

                foreach ($data[$t] as $row) {
                    if (!is_array($row)) continue;

                    // Unknown keys are dropped, never interpolated.
                    $row = array_intersect_key($row, $columns);
                    foreach ($row as $column => $value) {
                        if ($value !== null && !is_scalar($value)) {
                            throw new InvalidArgumentException("Invalid value for {$t}.{$column}");
                        }
                    }

                    if (in_array($t, $userTables, true)) {
                        $row['user_id'] = $userId;
                    }

                    if ($row === [] || !self::importReferencesOwned($t, $row, $owned)) continue;

                    $colStr = implode('`, `', array_keys($row));
                    $valStr = implode(', ', array_fill(0, count($row), '?'));
                    DB::run("INSERT INTO `$t` (`$colStr`) VALUES ($valStr)", array_values($row));

                    if (isset($owned[$t])) {
                        $id = isset($row['id']) ? (string)$row['id'] : (string)$conn->lastInsertId();
                        if ($id !== '' && $id !== '0') $owned[$t][$id] = true;
                    }
                }
            }

            $conn->commit();
            return true;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Whether an imported row references only parents this import created.
     * A finance entry with a foreign category loses the category; a block,
     * tag link or skill log that points elsewhere is skipped.
     */
    private static function importReferencesOwned(string $table, array &$row, array $owned): bool
    {
        $has = static fn(string $parent, mixed $id): bool =>
            $id !== null && isset($owned[$parent][(string)$id]);

        switch ($table) {
            case 'note_blocks':
                return $has('notes', $row['note_id'] ?? null);
            case 'note_tag_relations':
                return $has('notes', $row['note_id'] ?? null) && $has('note_tags', $row['tag_id'] ?? null);
            case 'skill_logs':
                return $has('skills', $row['skill_id'] ?? null);
            case 'finances':
                if (isset($row['category_id']) && !$has('finance_categories', $row['category_id'])) {
                    $row['category_id'] = null;
                }
                return true;
            default:
                return true;
        }
    }

    /**
     * The real columns of a table, as a name => true map.
     */
    private static function tableColumns(string $table): array
    {
        $names = DB::run(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($names, true);
    }
}
