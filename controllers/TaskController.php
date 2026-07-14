<?php
// =====================================================
// controllers/TaskController.php
// =====================================================

require_once ROOT . '/models/Task.php';
require_once ROOT . '/core/TelegramService.php';

class TaskController
{
    private function validatePayload(array $data, bool $requireTitle = false): array
    {
        $title = array_key_exists('title', $data) ? trim((string)$data['title']) : null;
        if ($requireTitle || $title !== null) {
            if ($title === '') Response::json(['error' => 'กรุณากรอกชื่องาน'], 422);
            if (mb_strlen($title) > 255) Response::json(['error' => 'ชื่องานยาวเกิน 255 ตัวอักษร'], 422);
            $data['title'] = $title;
        }
        if (array_key_exists('quadrant', $data)) {
            $quadrant = (int)$data['quadrant'];
            if ($quadrant < 1 || $quadrant > 4) Response::json(['error' => 'หมวดหมู่งานไม่ถูกต้อง'], 422);
            $data['quadrant'] = $quadrant;
        }
        if (array_key_exists('status', $data) && !in_array($data['status'], ['open', 'done'], true)) {
            Response::json(['error' => 'สถานะงานไม่ถูกต้อง'], 422);
        }
        if (array_key_exists('due_date', $data) && $data['due_date'] !== '') {
            $date = DateTime::createFromFormat('Y-m-d', (string)$data['due_date']);
            if (!$date || $date->format('Y-m-d') !== $data['due_date']) {
                Response::json(['error' => 'รูปแบบวันที่ไม่ถูกต้อง'], 422);
            }
        }
        return $data;
    }

    public function index(): void
    {
        $pageTitle  = 'งาน';
        $pageStyle  = 'tasks';
        $pageScript = 'tasks';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/tasks/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    // --- API ---

    public function apiList(): void
    {
        $userId = Auth::userId();
        $tasks  = Task::getAllForUser($userId);

        // Group by quadrant
        $quadrants = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($tasks as $t) {
            $q = (int)$t['quadrant'];
            if (isset($quadrants[$q])) $quadrants[$q][] = $t;
        }

        Response::json(['quadrants' => $quadrants]);
    }

    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $title  = trim(Request::input('title', ''));

        if (empty($title)) {
            Response::json(['error' => 'กรุณากรอกชื่องาน'], 422);
        }

        $payload = $this->validatePayload([
            'title'       => $title,
            'description' => Request::input('description', ''),
            'quadrant'    => (int)Request::input('quadrant', 1),
            'status'      => 'open',
            'due_date'    => Request::input('due_date', ''),
        ], true);
        $id = Task::create($userId, $payload);

        $task = Task::getById($id, $userId);
        Response::json(['ok' => true, 'task' => $task], 201);
    }

    public function apiUpdate(string $id): void
    {
        $userId = Auth::userId();
        $taskId = (int)$id;

        $task = Task::getById($taskId, $userId);
        if (!$task) Response::json(['error' => 'ไม่พบรายการ'], 404);

        $data = array_filter([
            'title'       => Request::input('title'),
            'description' => Request::rawInput('description'),
            'quadrant'    => Request::input('quadrant') !== null ? (int)Request::input('quadrant') : null,
            'status'      => Request::input('status'),
            'due_date'    => Request::input('due_date'),
            'position'    => Request::input('position') !== null ? (int)Request::input('position') : null,
        ], fn($v) => $v !== null);

        // Allow explicit empty string for due_date
        if (array_key_exists('due_date', Request::json())) {
            $data['due_date'] = Request::input('due_date', '');
        }

        $data = $this->validatePayload($data);
        Task::update($taskId, $userId, $data);
        $updated = Task::getById($taskId, $userId);
        
        if (isset($data['status']) && $data['status'] === 'done' && $task['status'] !== 'done') {
            $msg = TelegramService::formatMessage(
                "✅ งานส่วนตัวเสร็จสมบูรณ์",
                [
                    'ชื่องาน' => htmlspecialchars($task['title'])
                ],
                'เสร็จสิ้น'
            );
            TelegramService::sendNotification($userId, 'task', $msg);
        }

        Response::json(['ok' => true, 'task' => $updated]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        $taskId = (int)$id;

        if (!Task::delete($taskId, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    public function apiReorder(): void
    {
        $userId = Auth::userId();
        $items  = Request::json()['items'] ?? [];

        if (!is_array($items)) {
            Response::json(['error' => 'ข้อมูลไม่ถูกต้อง'], 422);
        }

        if (count($items) > 500) {
            Response::json(['error' => 'จำนวนงานที่ส่งมามากเกินไป'], 422);
        }
        foreach ($items as $item) {
            if (!is_array($item) || (int)($item['id'] ?? 0) < 1
                || (int)($item['quadrant'] ?? 0) < 1 || (int)($item['quadrant'] ?? 0) > 4
                || (int)($item['position'] ?? -1) < 0) {
                Response::json(['error' => 'ข้อมูลการเรียงงานไม่ถูกต้อง'], 422);
            }
        }

        Task::reorder($userId, $items);
        Response::json(['ok' => true]);
    }
}
