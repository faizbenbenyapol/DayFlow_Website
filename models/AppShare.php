<?php
// =====================================================
// models/AppShare.php
// =====================================================

class AppShare
{
    public static function allowedMenus(): array
    {
        return ['tasks', 'notes', 'planner', 'focus', 'exercise', 'food-notes',
                'finance', 'subscriptions', 'stocks'];
    }

    public static function sanitizeMenus(array $menus): array
    {
        $menus = array_map('strval', $menus);
        return array_values(array_unique(array_filter(
            $menus,
            fn(string $menu): bool => in_array($menu, self::allowedMenus(), true)
        )));
    }
    // ------------------------------------------------------------------ //
    // Create a new app share link
    // ------------------------------------------------------------------ //
    public static function create(
        int    $userId,
        string $label,
        array  $menus,
        ?string $expiresAt
    ): string {
        $token = bin2hex(random_bytes(32)); // 64-char hex token
        $menusJson = json_encode(self::sanitizeMenus($menus), JSON_THROW_ON_ERROR);

        DB::run(
            'INSERT INTO app_shares (user_id, token, label, menus, expires_at)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $token, $label, $menusJson, $expiresAt]
        );

        return $token;
    }

    // ------------------------------------------------------------------ //
    // Get share by token
    // ------------------------------------------------------------------ //
    public static function getByToken(string $token): ?array
    {
        return DB::run(
            'SELECT * FROM app_shares WHERE token = ?',
            [$token]
        )->fetch() ?: null;
    }

    // ------------------------------------------------------------------ //
    // List all app shares for a user
    // ------------------------------------------------------------------ //
    public static function listByUser(int $userId): array
    {
        return DB::run(
            'SELECT * FROM app_shares
             WHERE user_id = ?
             ORDER BY created_at DESC',
            [$userId]
        )->fetchAll();
    }

    // ------------------------------------------------------------------ //
    // Delete a share
    // ------------------------------------------------------------------ //
    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM app_shares WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }

    // ------------------------------------------------------------------ //
    // Validate that a token is still active (not expired)
    // ------------------------------------------------------------------ //
    public static function isValid(array $share): bool
    {
        if (!$share['expires_at']) return true;
        return strtotime($share['expires_at']) > time();
    }
}
