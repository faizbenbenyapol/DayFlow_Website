<?php
// =====================================================
// models/PushSubscription.php — one row per browser
// =====================================================

class PushSubscription
{
    /** Guard against one account hoarding endpoints. */
    private const MAX_PER_USER = 20;

    public static function listForUser(int $userId): array
    {
        return DB::run(
            'SELECT id, endpoint, user_agent, created_at, last_used_at
             FROM push_subscriptions WHERE user_id = ? ORDER BY created_at DESC',
            [$userId]
        )->fetchAll();
    }

    /**
     * Stores (or refreshes) a browser's subscription.
     *
     * A browser that re-subscribes sends the same endpoint, so this updates in
     * place rather than adding a duplicate.
     */
    public static function store(int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): void
    {
        DB::run(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth),
                                     user_agent = VALUES(user_agent)',
            [$userId, $endpoint, $p256dh, $auth, mb_substr($userAgent, 0, 255)]
        );

        self::pruneOldest($userId);
    }

    public static function deleteByEndpoint(int $userId, string $endpoint): bool
    {
        return DB::run(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?',
            [$userId, $endpoint]
        )->rowCount() > 0;
    }

    public static function deleteById(int $userId, int $id): bool
    {
        return DB::run(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND id = ?',
            [$userId, $id]
        )->rowCount() > 0;
    }

    public static function countForUser(int $userId): int
    {
        return (int)DB::run(
            'SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?',
            [$userId]
        )->fetchColumn();
    }

    public static function touch(int $id): void
    {
        DB::run('UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?', [$id]);
    }

    /**
     * Sends a wake-up push to every browser an account has registered, and
     * drops the ones the push service says are gone.
     *
     * @return array{sent: int, removed: int}
     */
    public static function notifyUser(int $userId): array
    {
        if (!WebPush::isConfigured()) return ['sent' => 0, 'removed' => 0];

        $sent = 0;
        $removed = 0;

        foreach (self::listForUser($userId) as $subscription) {
            $result = WebPush::send($subscription);

            if ($result['ok']) {
                self::touch((int)$subscription['id']);
                $sent++;
            } elseif ($result['gone']) {
                // The browser unsubscribed or the endpoint expired; keeping it
                // would mean retrying a dead endpoint on every run.
                self::deleteById($userId, (int)$subscription['id']);
                $removed++;
            }
        }

        return ['sent' => $sent, 'removed' => $removed];
    }

    private static function pruneOldest(int $userId): void
    {
        $extra = self::countForUser($userId) - self::MAX_PER_USER;
        if ($extra <= 0) return;

        // MySQL will not DELETE from a table it is selecting from in a
        // subquery, so the ids are read first.
        $ids = DB::run(
            'SELECT id FROM push_subscriptions WHERE user_id = ?
             ORDER BY COALESCE(last_used_at, created_at) ASC LIMIT ' . (int)$extra,
            [$userId]
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            self::deleteById($userId, (int)$id);
        }
    }
}
