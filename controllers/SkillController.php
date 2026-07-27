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

    public function index()
    {
        $pageTitle = 'เป้าหมายเวลา';
        $pageStyle = 'skills';
        require dirname(__DIR__) . '/views/layout/header.php';
        require dirname(__DIR__) . '/views/skills/index.php';
        require dirname(__DIR__) . '/views/layout/footer.php';
    }

    public function apiList()
    {
        $userId = Auth::userId();
        $skills = Skill::all($userId);
        Response::json($skills);
    }

    public function apiCreate()
    {
        $userId = Auth::userId();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) $data = $_POST;

        $name = trim($data['name'] ?? '');
        $targetHours = (int)($data['target_hours'] ?? 10000);
        $color = trim($data['color'] ?? '#3b82f6');

        if (!$name) {
            Response::json(['error' => 'กรุณาระบุชื่อทักษะ / เป้าหมาย'], 400);
        }
        $this->validateSkill($name, $targetHours, $color);

        $id = Skill::create($userId, $name, $targetHours, $color);
        Response::json(['success' => true, 'id' => $id]);
    }

    public function apiUpdate(string $id)
    {
        $userId = Auth::userId();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) $data = $_POST;

        $name = trim($data['name'] ?? '');
        $targetHours = (int)($data['target_hours'] ?? 10000);
        $color = trim($data['color'] ?? '#3b82f6');

        if (!$name) {
            Response::json(['error' => 'กรุณาระบุชื่อทักษะ / เป้าหมาย'], 400);
        }
        $this->validateSkill($name, $targetHours, $color);

        Skill::update($id, $userId, $name, $targetHours, $color);
        Response::json(['success' => true]);
    }

    public function apiDelete(string $id)
    {
        $userId = Auth::userId();
        Skill::delete($id, $userId);
        Response::json(['success' => true]);
    }

    // --- Logs & Timers ---

    public function apiLogsList()
    {
        $userId = Auth::userId();
        $logs = SkillLog::all($userId);
        Response::json($logs);
    }

    public function apiStats()
    {
        $userId = Auth::userId();
        $stats = SkillLog::getStats($userId);
        $activeTimer = SkillLog::getActiveTimer($userId);
        
        $stats['active_timer'] = $activeTimer;
        // Adjust skill progress based on active timer if needed
        Response::json($stats);
    }

    public function apiStartTimer()
    {
        $userId = Auth::userId();
        $data = json_decode(file_get_contents('php://input'), true);
        $skillId = trim($data['skill_id'] ?? '');
        $notes = trim($data['notes'] ?? '');

        if (!$skillId) {
            Response::json(['error' => 'กรุณาเลือกทักษะที่ต้องการจับเวลา'], 400);
        }

        if (!Skill::find($skillId, $userId)) Response::json(['error' => 'ไม่พบทักษะที่เลือก'], 422);
        SkillLog::startTimer($userId, $skillId, mb_substr($notes, 0, 2000));
        Response::json(['success' => true]);
    }

    public function apiUpdateTimer()
    {
        $userId = Auth::userId();
        $data = json_decode(file_get_contents('php://input'), true);
        $notes = trim($data['notes'] ?? '');
        SkillLog::updateTimerNotes($userId, $notes);
        Response::json(['success' => true]);
    }

    public function apiStopTimer()
    {
        $userId = Auth::userId();
        SkillLog::stopTimerAndLog($userId);
        Response::json(['success' => true]);
    }

    public function apiDeleteLog(string $id)
    {
        $userId = Auth::userId();
        SkillLog::delete($id, $userId);
        Response::json(['success' => true]);
    }
}
