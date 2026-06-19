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

    public static function updateTelegramSettings(int $userId, ?string $botToken, ?string $chatId, ?string $notifyEvents): void
    {
        DB::run(
            'INSERT INTO user_settings (user_id, telegram_bot_token, telegram_chat_id, telegram_notify_events) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE telegram_bot_token = VALUES(telegram_bot_token), telegram_chat_id = VALUES(telegram_chat_id), telegram_notify_events = VALUES(telegram_notify_events)',
            [$userId, $botToken, $chatId, $notifyEvents]
        );
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

    public static function importAllData(int $userId, array $data): bool
    {
        $tables = [
            'tasks', 'notes', 'note_blocks', 'note_tags', 'note_tag_relations',
            'calendar_events', 'daily_todos', 'workouts',
            'finance_categories', 'finances', 'subscriptions', 'dashboard_layout',
            'food_notes', 'skills', 'skill_logs', 'stock_transactions', 'stock_watchlists'
        ];

        $conn = DB::conn();
        $conn->beginTransaction();

        try {
            // Disable FK checks
            $conn->exec('SET FOREIGN_KEY_CHECKS = 0');

            // Delete old data for this user
            $userTables = [
                'tasks', 'notes', 'note_tags', 'calendar_events', 'daily_todos',
                'workouts', 'finance_categories', 'finances', 'subscriptions',
                'dashboard_layout', 'food_notes', 'skills', 'skill_logs',
                'stock_transactions', 'stock_watchlists'
            ];
            foreach ($userTables as $t) {
                DB::run("DELETE FROM $t WHERE user_id = ?", [$userId]);
            }

            // Clean child blocks and relations that cascade deleted but strictly clean
            DB::run("DELETE nb FROM note_blocks nb JOIN notes n ON nb.note_id = n.id WHERE n.user_id = ?", [$userId]);
            DB::run("DELETE r FROM note_tag_relations r JOIN notes n ON r.note_id = n.id WHERE n.user_id = ?", [$userId]);

            // Now, import new data if present in the data array
            foreach ($tables as $t) {
                if (!isset($data[$t]) || !is_array($data[$t])) {
                    continue;
                }

                foreach ($data[$t] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    // Enforce current user_id for safety
                    if (in_array($t, $userTables)) {
                        $row['user_id'] = $userId;
                    }

                    // Build dynamic INSERT query
                    $columns = array_keys($row);
                    $placeholders = array_fill(0, count($columns), '?');
                    $colStr = implode('`, `', $columns);
                    $valStr = implode(', ', $placeholders);

                    $sql = "INSERT INTO `$t` (`$colStr`) VALUES ($valStr)";
                    DB::run($sql, array_values($row));
                }
            }

            // Enable FK checks
            $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
            $conn->commit();
            return true;
        } catch (\Throwable $e) {
            $conn->rollBack();
            try { $conn->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable $_) {}
            throw $e;
        }
    }
}
