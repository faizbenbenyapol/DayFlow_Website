<?php
// =====================================================
// models/Finance.php
// =====================================================

class Finance
{
    public static function listForUser(int $userId, string $month = '', string $type = '', string $startDate = '', string $endDate = ''): array
    {
        $sql = 'SELECT f.id, f.type, f.amount, f.description, f.txn_date,
                       c.name AS category_name, f.category_id
                FROM finances f
                LEFT JOIN finance_categories c ON c.id = f.category_id
                WHERE f.user_id = ?';
        $params = [$userId];

        if ($month) {
            $sql .= ' AND DATE_FORMAT(f.txn_date, "%Y-%m") = ?';
            $params[] = $month;
        }
        if ($startDate) {
            $sql .= ' AND f.txn_date >= ?';
            $params[] = $startDate;
        }
        if ($endDate) {
            $sql .= ' AND f.txn_date <= ?';
            $params[] = $endDate;
        }
        if ($type) {
            $sql .= ' AND f.type = ?';
            $params[] = $type;
        }

        $sql .= ' ORDER BY f.txn_date DESC, f.id DESC LIMIT 1000';
        return DB::run($sql, $params)->fetchAll();
    }

    public static function getById(int $id, int $userId): ?array
    {
        return DB::run(
            'SELECT * FROM finances WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->fetch() ?: null;
    }

    public static function create(int $userId, array $data): int
    {
        DB::run(
            'INSERT INTO finances (user_id, type, amount, category_id, description, txn_date)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['type'],
                (float)$data['amount'],
                $data['category_id'] ?: null,
                $data['description'] ?? null,
                $data['txn_date'],
            ]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        return DB::run(
            'UPDATE finances SET type=?, amount=?, category_id=?, description=?, txn_date=?
             WHERE id = ? AND user_id = ?',
            [
                $data['type'],
                (float)$data['amount'],
                $data['category_id'] ?: null,
                $data['description'] ?? null,
                $data['txn_date'],
                $id,
                $userId,
            ]
        )->rowCount() > 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run('DELETE FROM finances WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0;
    }

    public static function getMonthlySummary(int $userId, string $month): array
    {
        $row = DB::run(
            'SELECT
               SUM(CASE WHEN type="income"  THEN amount ELSE 0 END) AS income,
               SUM(CASE WHEN type="expense" THEN amount ELSE 0 END) AS expense
             FROM finances WHERE user_id = ? AND DATE_FORMAT(txn_date, "%Y-%m") = ?',
            [$userId, $month]
        )->fetch();

        $income  = (float)($row['income']  ?? 0);
        $expense = (float)($row['expense'] ?? 0);
        return ['income' => $income, 'expense' => $expense, 'balance' => $income - $expense];
    }

    public static function getYearlyChart(int $userId, int $year): array
    {
        $rows = DB::run(
            'SELECT DATE_FORMAT(txn_date, "%m") AS month,
                    SUM(CASE WHEN type="income"  THEN amount ELSE 0 END) AS income,
                    SUM(CASE WHEN type="expense" THEN amount ELSE 0 END) AS expense
             FROM finances
             WHERE user_id = ? AND YEAR(txn_date) = ?
             GROUP BY month ORDER BY month ASC',
            [$userId, $year]
        )->fetchAll();

        // Fill all 12 months
        $chart = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = str_pad($m, 2, '0', STR_PAD_LEFT);
            $row = array_filter($rows, fn($r) => $r['month'] === $key);
            $row = array_values($row)[0] ?? null;
            $chart[] = [
                'month'   => $m,
                'income'  => (float)($row['income']  ?? 0),
                'expense' => (float)($row['expense'] ?? 0),
            ];
        }
        return $chart;
    }
}

class FinanceCategory
{
    public static function listForUser(int $userId): array
    {
        return DB::run(
            'SELECT id, name, type FROM finance_categories WHERE user_id = ? ORDER BY name ASC',
            [$userId]
        )->fetchAll();
    }

    public static function create(int $userId, string $name, string $type): int
    {
        DB::run(
            'INSERT IGNORE INTO finance_categories (user_id, name, type) VALUES (?, ?, ?)',
            [$userId, $name, $type]
        );
        return (int)DB::conn()->lastInsertId();
    }

    public static function update(int $id, int $userId, string $name, string $type): bool
    {
        return DB::run(
            'UPDATE finance_categories SET name = ?, type = ? WHERE id = ? AND user_id = ?',
            [$name, $type, $id, $userId]
        )->rowCount() >= 0;
    }

    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            'DELETE FROM finance_categories WHERE id = ? AND user_id = ?',
            [$id, $userId]
        )->rowCount() > 0;
    }
}
