<?php
// =====================================================
// controllers/TaskController.php
// =====================================================

require_once ROOT . '/models/Task.php';

class TaskController
{
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

        $id = Task::create($userId, [
            'title'       => $title,
            'description' => Request::input('description', ''),
            'quadrant'    => (int)Request::input('quadrant', 1),
            'status'      => 'open',
            'due_date'    => Request::input('due_date', ''),
        ]);

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

        Task::update($taskId, $userId, $data);
        $updated = Task::getById($taskId, $userId);
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

        Task::reorder($userId, $items);
        Response::json(['ok' => true]);
    }
}
