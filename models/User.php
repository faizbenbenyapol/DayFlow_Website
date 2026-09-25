<?php
// =====================================================
// models/User.php
// =====================================================

class User
{
    /** Every value user_settings.theme accepts. */
    public const THEMES = ['light', 'dark', 'soft', 'lavender', 'ocean', 'peach', 'auto'];

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
}
