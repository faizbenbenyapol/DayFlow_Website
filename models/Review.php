<?php
// =====================================================
// models/Review.php — cross-module period summary
//
// The dashboard answers "what is happening today". This answers "how did the
// week (or month) actually go" by reading the same modules over a date range.
//
// Every section is optional: a user who never opens the finance module should
// get a review without it rather than an error.
// =====================================================

class Review
{
    /**
     * Half-open [start, end) bounds for a period ending today.
     *
     * @return array{0: string, 1: string, 2: string} start, end, label
     */
    public static function bounds(string $period, ?string $anchor = null): array
    {
        $today = new DateTimeImmutable($anchor ?? 'today');

        if ($period === 'month') {
            $start = $today->modify('first day of this month');
            $end   = $start->modify('+1 month');
            return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'เดือนนี้'];
        }

        // Weeks run Monday to Sunday, which is how the calendar renders them.
        $start = $today->modify('monday this week');
        $end   = $start->modify('+1 week');
        return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'สัปดาห์นี้'];
    }

    /**
     * Builds the whole review. Each section is wrapped so a missing table or
     * an unmigrated module degrades to an empty section.
     */
    public static function build(int $userId, string $period, array &$warnings = []): array
    {
        [$start, $end, $label] = self::bounds($period);

        $section = static function (string $name, callable $fn, mixed $fallback) use (&$warnings) {
            try {
                return $fn();
            } catch (Throwable $e) {
                $warnings[] = $name;
                error_log('Review section failed [' . $name . ']: ' . $e->getMessage());
                return $fallback;
            }
        };

        return [
            'period' => ['key' => $period, 'label' => $label, 'start' => $start, 'end' => $end],
            'tasks'    => $section('tasks',    fn() => self::tasks($userId, $start, $end), self::emptyTasks()),
            'focus'    => $section('focus',    fn() => self::focus($userId, $start, $end), self::emptyFocus()),
            'habits'   => $section('habits',   fn() => self::habits($userId, $start, $end), self::emptyHabits()),
            'exercise' => $section('exercise', fn() => self::exercise($userId, $start, $end), self::emptyExercise()),
            'finance'  => $section('finance',  fn() => self::finance($userId, $start, $end), self::emptyFinance()),
            'notes'    => $section('notes',    fn() => self::notes($userId, $start, $end), ['created' => 0]),
        ];
    }

    // --- Sections ---

    private static function tasks(int $userId, string $start, string $end): array
    {
        // "Completed in the period" uses updated_at: a task is stamped when it
        // is ticked off, and there is no separate completed_at column.
        $row = DB::run(
            'SELECT
               COUNT(*) AS created,
               SUM(CASE WHEN status = "done" THEN 1 ELSE 0 END) AS created_and_done
             FROM tasks
             WHERE user_id = ? AND created_at >= ? AND created_at < ?',
            [$userId, $start, $end]
        )->fetch();

        $completed = (int)DB::run(
            'SELECT COUNT(*) FROM tasks
             WHERE user_id = ? AND status = "done" AND updated_at >= ? AND updated_at < ?',
            [$userId, $start, $end]
        )->fetchColumn();

        $stillOpen = (int)DB::run(
            'SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = "open"',
            [$userId]
        )->fetchColumn();

        $overdue = (int)DB::run(
            'SELECT COUNT(*) FROM tasks
             WHERE user_id = ? AND status = "open" AND due_date IS NOT NULL AND due_date < CURDATE()',
            [$userId]
        )->fetchColumn();

        return [
            'created'   => (int)($row['created'] ?? 0),
            'completed' => $completed,
            'open'      => $stillOpen,
            'overdue'   => $overdue,
        ];
    }

    private static function focus(int $userId, string $start, string $end): array
    {
        $row = DB::run(
            'SELECT COUNT(*) AS sessions, COALESCE(SUM(duration_min), 0) AS minutes
             FROM focus_sessions
             WHERE user_id = ? AND type = "work" AND completed_at >= ? AND completed_at < ?',
            [$userId, $start, $end]
        )->fetch();

        $byDay = DB::run(
            'SELECT DATE(completed_at) AS day, COALESCE(SUM(duration_min), 0) AS minutes
             FROM focus_sessions
             WHERE user_id = ? AND type = "work" AND completed_at >= ? AND completed_at < ?
             GROUP BY day ORDER BY day ASC',
            [$userId, $start, $end]
        )->fetchAll();

        return [
            'sessions' => (int)($row['sessions'] ?? 0),
            'minutes'  => (int)($row['minutes'] ?? 0),
            'by_day'   => $byDay,
        ];
    }

    private static function habits(int $userId, string $start, string $end): array
    {
        $rows = DB::run(
            'SELECT h.id, h.name, h.target_days,
                    COUNT(l.id) AS done_days
             FROM habits h
             LEFT JOIN habit_logs l
                    ON l.habit_id = h.id AND l.user_id = h.user_id
                   AND l.log_date >= ? AND l.log_date < ?
             WHERE h.user_id = ? AND h.is_archived = 0
             GROUP BY h.id, h.name, h.target_days
             ORDER BY done_days DESC, h.name ASC',
            [$start, $end, $userId]
        )->fetchAll();

        $totalDone   = 0;
        $totalTarget = 0;
        foreach ($rows as $habit) {
            $totalDone   += (int)$habit['done_days'];
            $totalTarget += (int)$habit['target_days'];
        }

        return [
            'items'        => $rows,
            'done_days'    => $totalDone,
            'target_days'  => $totalTarget,
        ];
    }

    private static function exercise(int $userId, string $start, string $end): array
    {
        $row = DB::run(
            'SELECT COUNT(*) AS sessions, COALESCE(SUM(duration_min), 0) AS minutes
             FROM workouts
             WHERE user_id = ? AND workout_date >= ? AND workout_date < ?',
            [$userId, $start, $end]
        )->fetch();

        return [
            'sessions' => (int)($row['sessions'] ?? 0),
            'minutes'  => (int)($row['minutes'] ?? 0),
        ];
    }

    private static function finance(int $userId, string $start, string $end): array
    {
        $row = DB::run(
            'SELECT
               COALESCE(SUM(CASE WHEN type = "income"  THEN amount ELSE 0 END), 0) AS income,
               COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS expense
             FROM finances
             WHERE user_id = ? AND txn_date >= ? AND txn_date < ?',
            [$userId, $start, $end]
        )->fetch();

        $topCategories = DB::run(
            'SELECT COALESCE(c.name, "ไม่ระบุหมวด") AS name, SUM(f.amount) AS total
             FROM finances f
             LEFT JOIN finance_categories c ON c.id = f.category_id
             WHERE f.user_id = ? AND f.type = "expense" AND f.txn_date >= ? AND f.txn_date < ?
             GROUP BY name
             ORDER BY total DESC
             LIMIT 5',
            [$userId, $start, $end]
        )->fetchAll();

        $income  = (float)($row['income'] ?? 0);
        $expense = (float)($row['expense'] ?? 0);

        return [
            'income'         => $income,
            'expense'        => $expense,
            'balance'        => $income - $expense,
            'top_categories' => $topCategories,
        ];
    }

    private static function notes(int $userId, string $start, string $end): array
    {
        return ['created' => (int)DB::run(
            'SELECT COUNT(*) FROM notes WHERE user_id = ? AND created_at >= ? AND created_at < ?',
            [$userId, $start, $end]
        )->fetchColumn()];
    }

    // --- Fallbacks, so a failed section still has the shape the view expects ---

    private static function emptyTasks(): array    { return ['created' => 0, 'completed' => 0, 'open' => 0, 'overdue' => 0]; }
    private static function emptyFocus(): array    { return ['sessions' => 0, 'minutes' => 0, 'by_day' => []]; }
    private static function emptyHabits(): array   { return ['items' => [], 'done_days' => 0, 'target_days' => 0]; }
    private static function emptyExercise(): array { return ['sessions' => 0, 'minutes' => 0]; }
    private static function emptyFinance(): array  { return ['income' => 0.0, 'expense' => 0.0, 'balance' => 0.0, 'top_categories' => []]; }
}
