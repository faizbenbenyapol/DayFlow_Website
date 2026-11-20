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
     * หน้าโฟกัส: ตัวจับเวลาและรายการงานที่ยังไม่เสร็จให้เลือก
     *
     * รับ ?task=<id> จากปุ่ม ▶ บนหน้าวันนี้ (REQ-21) แล้วเลือกงานนั้นไว้ให้
     * id ที่ไม่ใช่งานเปิดของผู้ใช้จะถูกเมิน ไม่มีงานไหนถูกเลือก
     */
    public function index(): void
    {
        $userId = Auth::userId();

        $allTasks = Task::getAllForUser($userId);
        $openTasks = array_filter($allTasks, fn($t) => $t['status'] === 'open');
        $selectedTaskId = (int)Request::query('task', 0);

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
     * POST /api/focus บันทึกรอบโฟกัสหรือรอบพักที่จบแล้ว
     *
     * task_id ที่ไม่ใช่งานของผู้ใช้ (ถูกลบระหว่างจับเวลา หรือเป็นของคนอื่น) ไม่ทำให้รอบหาย
     * รอบนั้นถูกบันทึกโดยไม่ผูกกับงาน และใช้ task_title ที่หน้าเว็บส่งมาเป็นหัวข้อ (EDGE-13)
     * ตอบแบบเดียวกันทั้งสองกรณี คนนอกจึงใช้ API นี้เดาไม่ได้ว่างาน id ไหนมีอยู่
     */
    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $type = Request::input('type', 'work');
        $duration = (int)Request::input('duration_min', 25);
        // ค่าติดลบนับเป็น "ไม่ระบุงาน" ไม่อย่างนั้นจะหลุดไปถึงคอลัมน์ unsigned แล้วได้ 500
        $taskId = max(0, (int)Request::input('task_id', 0));
        $title = trim((string)Request::input('title', ''));

        if (!in_array($type, ['work', 'short_break', 'long_break'], true) || $duration < 1 || $duration > 1440) {
            Response::json(['error' => 'ประเภทหรือระยะเวลา Focus ไม่ถูกต้อง'], 422);
        }
        if ($taskId > 0 && !Task::getById($taskId, $userId)) {
            $taskId = 0;
            if ($title === '') {
                $title = trim((string)Request::input('task_title', ''));
            }
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
            'task_id' => $taskId ?: null,
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
