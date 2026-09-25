<?php
// =====================================================
// controllers/FocusController.php
// =====================================================

require_once ROOT . '/models/FocusSession.php';
require_once ROOT . '/models/Task.php';
require_once ROOT . '/core/TelegramService.php';

class FocusController
{
    /**
     * Show Focus Page
     * Automatically runs DB migrations on first visit
     */
    public function index(): void
    {
        $userId = Auth::userId();

        // Fetch all open tasks for user
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
        $userId   = Auth::userId();
        $window   = Paginator::fromRequest(50);
        $sessions = FocusSession::listForUser($userId, $window['limit'], $window['offset']);
        $stats    = FocusSession::getStats($userId);

        Response::json([
            'sessions'   => $sessions,
            'stats'      => $stats,
            'pagination' => Paginator::meta($window, count($sessions), FocusSession::countForUser($userId)),
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

        if (!in_array($type, ['work', 'short_break', 'long_break'], true) || $duration < 1 || $duration > 1440) {
            Response::json(['error' => 'ประเภทหรือระยะเวลา Focus ไม่ถูกต้อง'], 422);
        }
        if ($taskId !== null && $taskId !== '' && !Task::getById((int)$taskId, $userId)) {
            Response::json(['error' => 'ไม่พบงานที่เลือก'], 422);
        }

        if (!$title) {
            if ($type === 'work') {
                $title = 'โฟกัสรอบการทำงาน';
            } elseif ($type === 'short_break') {
                $title = 'พักระยะสั้น';
            } else {
                $title = 'พักระยะยาว';
            }
        }

        $title = mb_substr($title, 0, 255);
        $data = [
            'task_id' => $taskId ? (int)$taskId : null,
            'title' => $title,
            'duration_min' => $duration,
            'type' => $type
        ];

        $sessionId = FocusSession::create($userId, $data);

        $msg = TelegramService::formatMessage(
            "🍅 สิ้นสุดเซสชันโฟกัส",
            [
                'กิจกรรม' => htmlspecialchars($title),
                'ระยะเวลา' => "{$duration} นาที"
            ]
        );
        TelegramService::sendNotification($userId, 'focus', $msg);

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
