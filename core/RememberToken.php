<?php

class RememberToken
{
    public const COOKIE = 'dayflow_remember';
    private const DAYS = 30;

    public static function issue(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + (self::DAYS * 86400);

        DB::run(
            'INSERT INTO remember_tokens (user_id, selector, token_hash, user_agent, ip_address, expires_at)
             VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))',
            [$userId, $selector, hash('sha256', $validator), self::userAgent(), self::ip(), $expires]
        );
        self::setCookie($selector . '.' . $validator, $expires);
    }

    public static function consume(string $value): ?array
    {
        [$selector, $validator] = array_pad(explode('.', $value, 2), 2, '');
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) return null;

        $row = DB::run(
            'SELECT rt.*, u.id AS uid, u.username, u.email, u.display_name, u.password_hash, u.avatar_path
             FROM remember_tokens rt JOIN users u ON u.id = rt.user_id
             WHERE rt.selector = ? AND rt.expires_at > NOW()',
            [$selector]
        )->fetch();
        if (!$row || !hash_equals($row['token_hash'], hash('sha256', $validator))) {
            if ($row) DB::run('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
            return null;
        }

        DB::run('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
        self::issue((int)$row['user_id']);
        DB::run('UPDATE remember_tokens SET last_used_at = NOW() WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int)$row['user_id']]);
        return [
            'id' => (int)$row['uid'], 'username' => $row['username'], 'email' => $row['email'],
            'display_name' => $row['display_name'], 'password_hash' => $row['password_hash'], 'avatar_path' => $row['avatar_path'],
        ];
    }

    public static function revokeCurrent(): void
    {
        $value = $_COOKIE[self::COOKIE] ?? '';
        [$selector] = array_pad(explode('.', $value, 2), 2, '');
        if (preg_match('/^[a-f0-9]{24}$/', $selector)) DB::run('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        setcookie(self::COOKIE, '', self::cookieOptions(time() - 3600));
    }

    public static function listForUser(int $userId): array
    {
        $current = self::currentSelector();
        $rows = DB::run(
            'SELECT id, selector, user_agent, ip_address, created_at, last_used_at, expires_at
             FROM remember_tokens WHERE user_id = ? AND expires_at > NOW()
             ORDER BY COALESCE(last_used_at, created_at) DESC, id DESC',
            [$userId]
        )->fetchAll();

        return array_map(static function (array $row) use ($current): array {
            return [
                'id' => (int)$row['id'],
                'user_agent' => $row['user_agent'] ?? '',
                'ip_address' => $row['ip_address'] ?? '',
                'created_at' => $row['created_at'],
                'last_used_at' => $row['last_used_at'],
                'expires_at' => $row['expires_at'],
                'is_current' => $current !== '' && hash_equals((string)$row['selector'], $current),
            ];
        }, $rows);
    }

    public static function revokeForUser(int $tokenId, int $userId): bool
    {
        $stmt = DB::run('DELETE FROM remember_tokens WHERE id = ? AND user_id = ?', [$tokenId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function revokeOthers(int $userId): int
    {
        $current = self::currentSelector();
        if ($current === '') {
            $stmt = DB::run('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
        } else {
            $stmt = DB::run('DELETE FROM remember_tokens WHERE user_id = ? AND selector <> ?', [$userId, $current]);
        }
        return $stmt->rowCount();
    }

    public static function revokeAll(int $userId): int
    {
        $stmt = DB::run('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
        return $stmt->rowCount();
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, self::cookieOptions($expires));
    }

    private static function currentSelector(): string
    {
        $value = $_COOKIE[self::COOKIE] ?? '';
        [$selector] = array_pad(explode('.', $value, 2), 2, '');
        return preg_match('/^[a-f0-9]{24}$/', $selector) ? $selector : '';
    }

    private static function cookieOptions(int $expires): array
    {
        return ['expires' => $expires, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax'];
    }

    private static function userAgent(): string { return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255); }
    private static function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
}
