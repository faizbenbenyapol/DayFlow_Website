<?php
// =====================================================
// controllers/DashboardController.php
// =====================================================

require_once ROOT . '/models/DashboardLayout.php';

class DashboardController
{
    /** Widgets the summary endpoint knows how to build. */
    private const MODULES = [
        'tasks', 'calendar', 'finance', 'workout',
        'subscriptions', 'projects', 'notes', 'stocks', 'transfer',
    ];

    /**
     * Dashboard is an aggregation endpoint: one optional module must not take
     * the whole dashboard down when its table/migration is unavailable.
     */
    private function safeModule(string $name, callable $callback, array &$warnings, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            $warnings[] = $name;
            error_log('Dashboard module failed [' . $name . ']: ' . $e->getMessage());
            return $fallback;
        }
    }

    /**
     * The page renders only the widgets a user kept visible, so the client
     * sends the list it actually needs. Building all nine for everybody meant a
     * hidden stocks widget still ran a full portfolio valuation on every load.
     * A request without the hint keeps the original behaviour.
     */
    private function requestedModules(): array
    {
        $raw = Request::query('modules');
        if ($raw === null) return self::MODULES;

        $asked = array_filter(array_map('trim', explode(',', (string)$raw)));
        return array_values(array_intersect(self::MODULES, $asked));
    }

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
        $warnings = [];
        $need = array_flip($this->requestedModules());
        $payload = [];

        // Tasks: upcoming and overdue
        if (isset($need['tasks'])) {
            $tasks = $this->safeModule('tasks', fn() => DB::run(
                'SELECT id, title, due_date, status, quadrant
                 FROM tasks
                 WHERE user_id = ? AND status = "open"
                   AND due_date IS NOT NULL
                   AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                 ORDER BY due_date ASC
                 LIMIT 5',
                [$userId]
            )->fetchAll(), $warnings, []);

            $overdueCount = (int)$this->safeModule('tasks', fn() => DB::run(
                'SELECT COUNT(*) FROM tasks
                 WHERE user_id = ? AND status = "open" AND due_date < CURDATE()',
                [$userId]
            )->fetchColumn(), $warnings, 0);

            $payload['tasks'] = [
                'overdue' => $overdueCount,
                'items'   => $tasks,
            ];
        }

        // Calendar: today's events. Comparing the raw column against a day range
        // keeps the (user_id, start_datetime) index usable; DATE(start_datetime)
        // would force a full scan.
        if (isset($need['calendar'])) {
            $calEvents = $this->safeModule('calendar', fn() => DB::run(
                'SELECT id, title, start_datetime, end_datetime, is_all_day
                 FROM calendar_events
                 WHERE user_id = ?
                   AND start_datetime >= CURDATE()
                   AND start_datetime < CURDATE() + INTERVAL 1 DAY
                 ORDER BY start_datetime ASC
                 LIMIT 8',
                [$userId]
            )->fetchAll(), $warnings, []);

            $payload['calendar'] = ['today_events' => $calEvents];
        }

        // Finance: current month summary, as a half-open date range so the
        // index on txn_date still applies.
        if (isset($need['finance'])) {
            $month      = date('Y-m');
            $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
            $monthEnd   = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');

            $finStmt = $this->safeModule('finance', fn() => DB::run(
                'SELECT
                   SUM(CASE WHEN type="income"  THEN amount ELSE 0 END) AS income,
                   SUM(CASE WHEN type="expense" THEN amount ELSE 0 END) AS expense
                 FROM finances
                 WHERE user_id = ? AND txn_date >= ? AND txn_date < ?',
                [$userId, $monthStart, $monthEnd]
            )->fetch(), $warnings, []);
            $finIncome  = (float)($finStmt['income']  ?? 0);
            $finExpense = (float)($finStmt['expense'] ?? 0);

            $payload['finance'] = [
                'month'   => $month,
                'income'  => $finIncome,
                'expense' => $finExpense,
                'balance' => $finIncome - $finExpense,
            ];
        }

        // Workout: last session
        if (isset($need['workout'])) {
            $lastWorkout = $this->safeModule('exercise', fn() => DB::run(
                'SELECT id, workout_date, type, duration_min
                 FROM workouts
                 WHERE user_id = ?
                 ORDER BY workout_date DESC, id DESC
                 LIMIT 1',
                [$userId]
            )->fetch() ?: null, $warnings, null);

            $payload['workout'] = ['last_session' => $lastWorkout];
        }

        // Subscriptions: upcoming (within 7 days)
        if (isset($need['subscriptions'])) {
            $upcomingSubs = $this->safeModule('subscriptions', fn() => DB::run(
                'SELECT id, name, amount, next_due_date, alert_days
                 FROM subscriptions
                 WHERE user_id = ? AND is_active = 1
                   AND next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                 ORDER BY next_due_date ASC
                 LIMIT 5',
                [$userId]
            )->fetchAll(), $warnings, []);

            $payload['subscriptions'] = ['upcoming' => $upcomingSubs];
        }

        // Projects: Top 3 recently updated active projects
        if (isset($need['projects'])) {
            $projects = $this->safeModule('projects', function () use ($userId) {
                return DB::run(
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
            }, $warnings, []);

            $payload['projects'] = ['items' => $projects];
        }

        // Notes: Top 3 recently updated notes
        if (isset($need['notes'])) {
            $notes = $this->safeModule('notes', function () use ($userId) {
                return DB::run(
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
            }, $warnings, []);

            $payload['notes'] = ['items' => $notes];
        }

        // Stocks: Top 4 watchlisted stocks or portfolio holdings
        if (isset($need['stocks'])) {
            $stocks = $this->safeModule('stocks', function () use ($userId) {
                require_once ROOT . '/models/Stock.php';
                require_once ROOT . '/models/StockPriceCache.php';
                $stocks = [];
                $watchlist = Stock::getWatchlistsForUser($userId);
                if (!empty($watchlist)) {
                    $stocks = array_slice($watchlist, 0, 4);
                } else {
                    $portfolio = Stock::portfolioForUser($userId);
                    if (!empty($portfolio['holdings'])) {
                        $stocks = array_slice($portfolio['holdings'], 0, 4);
                    }
                }
                return $stocks;
            }, $warnings, []);

            $payload['stocks'] = ['items' => $stocks];
        }

        // File transfers: Top 3 recently created transfers
        if (isset($need['transfer'])) {
            $transfers = $this->safeModule('transfer', function () use ($userId) {
                $transfers = DB::run(
                    'SELECT id, code, token, files_json, total_size, download_count, expires_at, created_at
                     FROM file_transfers
                     WHERE user_id = ?
                     ORDER BY created_at DESC
                     LIMIT 3',
                    [$userId]
                )->fetchAll();
                foreach ($transfers as &$t) {
                    $t['is_expired'] = strtotime($t['expires_at']) <= time();
                }
                return $transfers;
            }, $warnings, []);

            $payload['transfer'] = ['items' => $transfers];
        }

        Response::json([
            'meta' => [
                'generated_at' => gmdate('c'),
                'partial'  => !empty($warnings),
                'warnings' => array_values(array_unique($warnings)),
                'modules'  => array_keys($need),
            ],
        ] + $payload);
    }

    public function layout(): void
    {
        $widgets = Request::json()['widgets'] ?? [];
        if (!is_array($widgets) || empty($widgets)) {
            Response::json(['error' => 'ข้อมูลไม่ถูกต้อง'], 422);
        }

        try {
            DashboardLayout::saveLayout(Auth::userId(), $widgets);
        } catch (InvalidArgumentException $e) {
            Response::json(['error' => 'รูปแบบการจัดวาง Dashboard ไม่ถูกต้อง'], 422);
        }
        Response::json(['ok' => true]);
    }
}
