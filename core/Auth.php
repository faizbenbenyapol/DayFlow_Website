<?php
// =====================================================
// core/Auth.php — Authentication Gate
// =====================================================

class Auth
{
    private static ?array $cachedUser = null;
    private static bool $isShareMode = false;
    private static int $sharedUserId = 0;
    private static array $sharedMenus = [];

    public static function setShareMode(int $userId, array $menus): void
    {
        self::$isShareMode = true;
        self::$sharedUserId = $userId;
        self::$sharedMenus = $menus;
    }

    public static function isReadOnly(): bool
    {
        return self::$isShareMode;
    }

    public static function getSharedMenus(): array
    {
        return self::$sharedMenus;
    }

    public static function requireLogin(): void
    {
        if (self::$isShareMode) return; // Allow access in share mode

        // บายพาสสิทธิ์ล็อกอินสำหรับผู้เยี่ยมชมผ่านลิงก์สาธารณะ (ในเส้นทางที่เกี่ยวข้องกับโครงการ)
        if (!empty($_SESSION['active_project_share_token'])) {
            $path = Request::path();
            if (strpos($path, '/projects') === 0 || strpos($path, '/api/projects') === 0) {
                return;
            }
        }

        if (empty($_SESSION['user_id'])) {
            if (Request::isApi()) {
                Response::json(['error' => 'กรุณาเข้าสู่ระบบ'], 401);
            }
            Response::redirect('/login');
        }
    }

    public static function userId(): int
    {
        if (self::$isShareMode) return self::$sharedUserId;
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function user(): ?array
    {
        if (self::$cachedUser !== null) return self::$cachedUser;
        
        $uid = self::userId();
        if (!$uid) return null;

        self::$cachedUser = DB::run(
            'SELECT id, username, email, display_name, avatar_path FROM users WHERE id = ?',
            [$uid]
        )->fetch() ?: null;
        return self::$cachedUser;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']      = $user['id'];
        $_SESSION['display_name'] = $user['display_name'] ?: $user['username'];
        self::$cachedUser = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$cachedUser = null;
    }

    public static function check(): bool
    {
        return self::$isShareMode || !empty($_SESSION['user_id']);
    }

    public static function theme(): string
    {
        if (!self::check()) return 'light';
        $row = DB::run(
            'SELECT theme FROM user_settings WHERE user_id = ?',
            [self::userId()]
        )->fetch();
        return $row['theme'] ?? 'light';
    }
}
