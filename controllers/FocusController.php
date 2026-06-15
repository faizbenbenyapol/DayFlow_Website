<?php
// =====================================================
// controllers/FocusController.php
// =====================================================

require_once ROOT . '/models/FocusSession.php';
require_once ROOT . '/models/Task.php';

class FocusController
{
    /**
     * Show Focus Page
     * Automatically runs DB migrations on first visit
     */
    public function index(): void
    {
        $userId = Auth::userId();

        // 1. Automatically run migration script if table focus_sessions doesn't exist
        try {
            DB::run('SELECT 1 FROM `focus_sessions` LIMIT 1');
        } catch (PDOException $e) {
            $sqlFile = ROOT . '/sql/migrate_focus.sql';
            if (file_exists($sqlFile)) {
                try {
                    $queries = file_get_contents($sqlFile);
                    DB::conn()->exec($queries);
                } catch (PDOException $ex) {
                    Response::abort(500, 'ไม่สามารถติดตั้งตารางโฟกัสอัตโนมัติได้: ' . $ex->getMessage());
                }
            } else {
                Response::abort(500, 'ไม่พบไฟล์สคริปต์สำหรับติดตั้งโครงสร้างตารางข้อมูล: ' . $sqlFile);
            }
        }

        // 2. Fetch all open tasks for user
        $allTasks = Task::getAllForUser($userId);
        $openTasks = array_filter($allTasks, fn($t) => $t['status'] === 'open');

        $pageTitle  = 'โฟกัส';
        $pageScript = 'focus';
        $pageStyle  = 'focus';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/focus/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    /**
     * GET /api/focus
     * List user focus sessions and summary stats
     */
    public function apiList(): void
    {
        $userId = Auth::userId();
        $sessions = FocusSession::listForUser($userId);
        $stats = FocusSession::getStats($userId);

        Response::json([
            'sessions' => $sessions,
            'stats' => $stats
        ]);
    }

    /**
     * POST /api/focus
     * Log a new completed Focus / Break session
     */
    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $type = Request::input('type', 'work');
        $duration = (int)Request::input('duration_min', 25);
        $taskId = Request::input('task_id');
        $title = trim(Request::input('title', ''));

        if (!$title) {
            if ($type === 'work') {
                $title = 'โฟกัสรอบการทำงาน';
            } elseif ($type === 'short_break') {
                $title = 'พักระยะสั้น';
            } else {
                $title = 'พักระยะยาว';
            }
        }

        $data = [
            'task_id' => $taskId ? (int)$taskId : null,
            'title' => $title,
            'duration_min' => $duration,
            'type' => $type
        ];

        $sessionId = FocusSession::create($userId, $data);

        Response::json([
            'ok' => true,
            'id' => $sessionId
        ], 201);
    }

    /**
     * DELETE /api/focus/{id}
     * Remove a focus session entry from logs
     */
    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        $sessionId = (int)$id;

        if (FocusSession::delete($sessionId, $userId)) {
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'ไม่พบรายการที่ต้องการลบ'], 404);
        }
    }
}
