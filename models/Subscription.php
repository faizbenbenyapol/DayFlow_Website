<?php
// =====================================================
// models/Subscription.php
// =====================================================

class Subscription
{
    public static function listForUser(int $userId): array
    {
        return DB::run(
            'SELECT id, name, amount, billing_cycle, next_due_date, alert_days, is_active, notes
             FROM subscriptions
             WHERE user_id = ?
             ORDER BY is_active DESC, next_due_date ASC',
            [$userId]
        )->fetchAll();
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM subscriptions WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    public static function create(int $userId, array $data): int
    {
        DB::run(
            'INSERT INTO subscriptions (user_id, name, amount, billing_cycle, next_due_date, alert_days, is_active, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['name'],
                (float)($data['amount'] ?? 0),
                $data['billing_cycle'] ?? 'monthly',
                $data['next_due_date'],
                (int)($data['alert_days'] ?? 3),
                1,
                $data['notes'] ?? null,
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        return DB::run(
            'UPDATE subscriptions
             SET name=?, amount=?, billing_cycle=?, next_due_date=?, alert_days=?, is_active=?, notes=?
             WHERE id = ? AND user_id = ?',
            [
                $data['name'],
                (float)$data['amount'],
                $data['billing_cycle'],
                $data['next_due_date'],
                (int)$data['alert_days'],
                (int)$data['is_active'],
                $data['notes'] ?? null,
                $id,
                $userId,
            ]
        )->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM subscriptions WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }

    /**
     * Advance next_due_date by one billing cycle
     */
    public static function renew(int $id, int $userId): bool
    {
        $sub = self::getById($id, $userId);
        if (!$sub) return false;

        // Recurrence keeps a bill due on the 31st inside the next month
        // (28 Feb) where strtotime('+1 month') would spill into March.
        $newDate = $sub['billing_cycle'] === 'one_time'
            ? null // one-time can't be renewed
            : Recurrence::next($sub['next_due_date'], $sub['billing_cycle']);
        if ($newDate === null) return false;

        DB::run(
            'UPDATE subscriptions SET next_due_date = ? WHERE id = ? AND user_id = ?',
            [$newDate, $id, $userId]
        );
        return true;
    }
}
