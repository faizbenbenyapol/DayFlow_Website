<?php
// =====================================================
// controllers/DashboardController.php
// =====================================================

require_once ROOT . '/models/DashboardLayout.php';

class DashboardController
{
    public function index(): void
    {
        $pageTitle = 'แดชบอร์ด';
        $pageStyle  = 'dashboard';
        $pageScript = 'dashboard';

        $userId = Auth::userId();
        $layout  = DashboardLayout::getForUser($userId);

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/dashboard/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function summary(): void
    {
        $userId = Auth::userId();

        // Tasks: upcoming and overdue
        $tasks = DB::run(
            'SELECT id, title, due_date, status, quadrant
             FROM tasks
             WHERE user_id = ? AND status = "open"
               AND due_date IS NOT NULL
               AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY due_date ASC
             LIMIT 5',
            [$userId]
        )->fetchAll();

        $overdueCount = (int)DB::run(
            'SELECT COUNT(*) FROM tasks
             WHERE user_id = ? AND status = "open" AND due_date < CURDATE()',
            [$userId]
        )->fetchColumn();

        // Calendar: today's events
        $calEvents = DB::run(
            'SELECT id, title, start_datetime, end_datetime, is_all_day
             FROM calendar_events
             WHERE user_id = ? AND DATE(start_datetime) = CURDATE()
             ORDER BY start_datetime ASC
             LIMIT 8',
            [$userId]
        )->fetchAll();

        // Finance: current month summary
        $month = date('Y-m');
        $finStmt = DB::run(
            'SELECT
               SUM(CASE WHEN type="income"  THEN amount ELSE 0 END) AS income,
               SUM(CASE WHEN type="expense" THEN amount ELSE 0 END) AS expense
             FROM finances
             WHERE user_id = ? AND DATE_FORMAT(txn_date, "%Y-%m") = ?',
            [$userId, $month]
        )->fetch();
        $finIncome  = (float)($finStmt['income']  ?? 0);
        $finExpense = (float)($finStmt['expense'] ?? 0);

        // Workout: last session
        $lastWorkout = DB::run(
            'SELECT id, workout_date, type, duration_min
             FROM workouts
             WHERE user_id = ?
             ORDER BY workout_date DESC, id DESC
             LIMIT 1',
            [$userId]
        )->fetch() ?: null;

        // Subscriptions: upcoming (within 7 days)
        $upcomingSubs = DB::run(
            'SELECT id, name, amount, next_due_date, alert_days
             FROM subscriptions
             WHERE user_id = ? AND is_active = 1
               AND next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY next_due_date ASC
             LIMIT 5',
            [$userId]
        )->fetchAll();

        // Projects: Top 3 recently updated active projects
        $projects = [];
        try {
            $projects = DB::run(
                'SELECT p.id, p.name, p.status, p.priority, p.due_date,
                        COUNT(DISTINCT t.id) AS total_tasks,
                        COUNT(DISTINCT CASE WHEN t.status = "Done" THEN t.id END) AS completed_tasks
                 FROM projects p
                 LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = ?
                 LEFT JOIN project_tasks t ON p.id = t.project_id
                 WHERE p.user_id = ? OR pm.user_id = ?
                 GROUP BY p.id
                 ORDER BY p.updated_at DESC, p.id DESC
                 LIMIT 3',
                [$userId, $userId, $userId]
            )->fetchAll();
        } catch (PDOException $e) {
            $projects = [];
        }

        // Notes: Top 3 recently updated notes
        $notes = [];
        try {
            $notes = DB::run(
                'SELECT n.id, n.title, n.is_encrypted, n.pinned, n.updated_at,
                        (SELECT content FROM note_blocks WHERE note_id = n.id AND type = "text"
                         ORDER BY position ASC LIMIT 1) AS preview,
                        (SELECT GROUP_CONCAT(t.name ORDER BY t.name ASC SEPARATOR ",")
                         FROM note_tags t
                         JOIN note_tag_relations r ON r.tag_id = t.id
                         WHERE r.note_id = n.id) AS tags_list
                 FROM notes n
                 WHERE n.user_id = ?
                 ORDER BY n.pinned DESC, n.updated_at DESC
                 LIMIT 3',
                [$userId]
            )->fetchAll();
        } catch (PDOException $e) {
            $notes = [];
        }

        // Stocks: Top 4 watchlisted stocks or portfolio holdings
        $stocks = [];
        try {
            require_once ROOT . '/models/Stock.php';
            require_once ROOT . '/models/StockPriceCache.php';
            $watchlist = Stock::getWatchlistsForUser($userId);
            if (!empty($watchlist)) {
                $stocks = array_slice($watchlist, 0, 4);
            } else {
                $portfolio = Stock::portfolioForUser($userId);
                if (!empty($portfolio['holdings'])) {
                    $stocks = array_slice($portfolio['holdings'], 0, 4);
                }
            }
        } catch (Exception $e) {
            $stocks = [];
        }

        Response::json([
            'tasks' => [
                'overdue' => $overdueCount,
                'items'   => $tasks,
            ],
            'calendar' => [
                'today_events' => $calEvents,
            ],
            'finance' => [
                'month'   => $month,
                'income'  => $finIncome,
                'expense' => $finExpense,
                'balance' => $finIncome - $finExpense,
            ],
            'workout' => [
                'last_session' => $lastWorkout,
            ],
            'subscriptions' => [
                'upcoming' => $upcomingSubs,
            ],
            'projects' => [
                'items' => $projects,
            ],
            'notes' => [
                'items' => $notes,
            ],
            'stocks' => [
                'items' => $stocks,
            ],
        ]);
    }

    public function layout(): void
    {
        $widgets = Request::json()['widgets'] ?? [];
        if (!is_array($widgets) || empty($widgets)) {
            Response::json(['error' => 'ข้อมูลไม่ถูกต้อง'], 422);
        }

        DashboardLayout::saveLayout(Auth::userId(), $widgets);
        Response::json(['ok' => true]);
    }
}
