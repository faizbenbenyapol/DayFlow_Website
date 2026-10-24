<?php
require_once dirname(__DIR__) . '/models/Skill.php';
require_once dirname(__DIR__) . '/models/SkillLog.php';

class SkillController
{
    private function validateSkill(string $name, int $targetHours, string $color): void
    {
        if ($name === '' || mb_strlen($name) > 255 || $targetHours < 1 || $targetHours > 100000
            || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            Response::json(['error' => 'ข้อมูลทักษะไม่ถูกต้อง'], 422);
        }
    }

    public function index(): void
    {
        $pageTitle = 'ทักษะ';
        $pageStyle = 'skills';
        $pageScript = 'skills';
        require dirname(__DIR__) . '/views/layout/header.php';
        require dirname(__DIR__) . '/views/skills/index.php';
        require dirname(__DIR__) . '/views/layout/footer.php';
    }

    public function apiList(): void
    {
        $userId = Auth::userId();
        $skills = Skill::all($userId);
        Response::json($skills);
    }

    public function apiCreate(): void
    {
        $userId = Auth::userId();

        $name = (string)Request::input('name', '');
        $targetHours = (int)Request::input('target_hours', 10000);
        $color = (string)Request::input('color', '#3b82f6');

        if (!$name) {
            Response::json(['error' => 'กรุณาระบุชื่อทักษะ / เป้าหมาย'], 400);
        }
        $this->validateSkill($name, $targetHours, $color);

        $id = Skill::create($userId, $name, $targetHours, $color);
        Response::json(['success' => true, 'id' => $id]);
    }

    public function apiUpdate(string $id): void
    {
        $userId = Auth::userId();

        $name = (string)Request::input('name', '');
        $targetHours = (int)Request::input('target_hours', 10000);
        $color = (string)Request::input('color', '#3b82f6');

        if (!$name) {
            Response::json(['error' => 'กรุณาระบุชื่อทักษะ / เป้าหมาย'], 400);
        }
        $this->validateSkill($name, $targetHours, $color);

        // An unchanged save affects no rows, so existence is checked rather
        // than the update's own row count.
        if (!Skill::belongsTo($id, $userId)) {
            Response::json(['error' => 'ไม่พบทักษะ'], 404);
        }

        Skill::update($id, $userId, $name, $targetHours, $color);
        Response::json(['success' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!Skill::delete($id, $userId)) {
            Response::json(['error' => 'ไม่พบทักษะ'], 404);
        }
        Response::json(['success' => true]);
    }

    // --- Logs & Timers ---

    public function apiLogsList(): void
    {
        $userId = Auth::userId();
        $window = Paginator::fromRequest(50);
        $logs   = SkillLog::all($userId, $window['limit'], $window['offset']);
        $meta   = Paginator::meta($window, count($logs), SkillLog::countForUser($userId));

        // The response is a bare array that the page already reads as one, so
        // the window travels in headers instead of wrapping (and breaking) it.
        header('X-Pagination-Total: ' . $meta['total']);
        header('X-Pagination-Offset: ' . $meta['offset']);
        header('X-Pagination-Has-More: ' . ($meta['has_more'] ? '1' : '0'));

        Response::json($logs);
    }

    public function apiStats(): void
    {
        $userId = Auth::userId();
        $stats = SkillLog::getStats($userId);
        $activeTimer = SkillLog::getActiveTimer($userId);

        // start_time is a wall clock in the server's zone. A browser in
        // another zone read it as its own and saw a start in the future, so
        // the clock stood at 00:00:00. The elapsed time needs no zone at all.
        if ($activeTimer) {
            $activeTimer['elapsed_seconds'] = max(0, time() - (new DateTime($activeTimer['start_time']))->getTimestamp());
        }

        $stats['active_timer'] = $activeTimer;
        // Adjust skill progress based on active timer if needed
        Response::json($stats);
    }

    public function apiStartTimer(): void
    {
        $userId = Auth::userId();
        $skillId = (string)Request::input('skill_id', '');
        $notes = (string)Request::input('notes', '');

        if (!$skillId) {
            Response::json(['error' => 'กรุณาเลือกทักษะที่ต้องการจับเวลา'], 400);
        }

        if (!Skill::find($skillId, $userId)) Response::json(['error' => 'ไม่พบทักษะที่เลือก'], 422);
        SkillLog::startTimer($userId, $skillId, mb_substr($notes, 0, 2000));
        Response::json(['success' => true]);
    }

    public function apiUpdateTimer(): void
    {
        $userId = Auth::userId();
        $notes = (string)Request::input('notes', '');
        SkillLog::updateTimerNotes($userId, $notes);
        Response::json(['success' => true]);
    }

    public function apiStopTimer(): void
    {
        $userId = Auth::userId();
        SkillLog::stopTimerAndLog($userId);
        Response::json(['success' => true]);
    }

    public function apiDeleteLog(string $id): void
    {
        $userId = Auth::userId();
        if (!SkillLog::delete($id, $userId)) {
            Response::json(['error' => 'ไม่พบรายการบันทึก'], 404);
        }
        Response::json(['success' => true]);
    }
}
