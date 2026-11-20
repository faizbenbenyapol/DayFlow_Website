<?php
// =====================================================
// controllers/TaskController.php
// =====================================================

require_once ROOT . '/models/Task.php';
require_once ROOT . '/core/TelegramService.php';

class TaskController
{
    /**
     * ตรวจสอบข้อมูลงานที่ส่งเข้ามา รองรับ quadrant 0 เป็น inbox (null)
     *
     * @param array<string, mixed> $data ข้อมูลงาน
     * @param bool $requireTitle บังคับให้มีชื่องานหรือไม่
     * @return array<string, mixed> ข้อมูลที่ผ่านการตรวจสอบ quadrant 0 จะถูกแปลงเป็น null
     */
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
            // 0 = inbox (stored as NULL); 1-4 = the matrix boxes
            if ($quadrant < 0 || $quadrant > 4) Response::json(['error' => 'หมวดหมู่งานไม่ถูกต้อง'], 422);
            $data['quadrant'] = $quadrant === 0 ? null : $quadrant;
        }
        if (array_key_exists('status', $data) && !in_array($data['status'], ['open', 'done'], true)) {
            Response::json(['error' => 'สถานะงานไม่ถูกต้อง'], 422);
        }
        if (array_key_exists('due_date', $data) && $data['due_date'] !== '') {
            $raw = (string)$data['due_date'];
            if ($raw === 'today') {
                $data['due_date'] = date('Y-m-d');
            } elseif ($raw === 'tomorrow') {
                $data['due_date'] = date('Y-m-d', strtotime('+1 day'));
            } else {
                $date = DateTime::createFromFormat('Y-m-d', $raw);
                if (!$date || $date->format('Y-m-d') !== $raw) {
                    Response::json(['error' => 'รูปแบบวันที่ไม่ถูกต้อง'], 422);
                }
            }
        }
        if (array_key_exists('repeat_rule', $data) && !Recurrence::isValid((string)$data['repeat_rule'])) {
            Response::json(['error' => 'รูปแบบการทำซ้ำไม่ถูกต้อง'], 422);
        }
        if (array_key_exists('repeat_until', $data) && $data['repeat_until'] !== '') {
            $until = DateTime::createFromFormat('Y-m-d', (string)$data['repeat_until']);
            if (!$until || $until->format('Y-m-d') !== $data['repeat_until']) {
                Response::json(['error' => 'รูปแบบวันสิ้นสุดการทำซ้ำไม่ถูกต้อง'], 422);
            }
        }
        // A repeat needs a first date to count from. Only checked when the
        // caller sends a due date, so a partial update that touches nothing but
        // the rule still works on a task that already has one.
        $rule = $data['repeat_rule'] ?? null;
        if ($rule !== null && $rule !== 'none'
            && array_key_exists('due_date', $data) && $data['due_date'] === '') {
            Response::json(['error' => 'งานที่ทำซ้ำต้องมีวันครบกำหนด'], 422);
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

    /**
     * คืนรายการงานทั้งหมดของผู้ใช้ จัดกลุ่มตาม quadrant
     *
     * งานที่ยังไม่ได้จัดหมวดหมู่ (quadrant = null) อยู่ในกลุ่ม 0
     * ถ้า query ?show=<id> ระบุมา งานนั้นจะถูกบังคับแสดงแม้จะถูกกฎ 7 วันซ่อน
     */
    public function apiList(): void
    {
        $userId = Auth::userId();
        $show   = Request::query('show');
        $showId = is_string($show) && ctype_digit($show) && (int)$show > 0 ? (int)$show : null;
        $tasks  = Task::getAllForUser($userId, $showId);

        // Group by quadrant; null quadrant becomes group 0 so inbox tasks are visible too
        $quadrants = [0 => [], 1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($tasks as $t) {
            $q = $t['quadrant'] === null ? 0 : (int)$t['quadrant'];
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
            'title'        => $title,
            'description'  => Request::input('description', ''),
            'quadrant'     => (int)Request::input('quadrant', 1),
            'status'       => 'open',
            'due_date'     => Request::input('due_date', ''),
            'repeat_rule'  => Request::input('repeat_rule', 'none'),
            'repeat_until' => Request::input('repeat_until', ''),
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
            'title'        => Request::input('title'),
            'description'  => Request::rawInput('description'),
            'quadrant'     => Request::input('quadrant') !== null ? (int)Request::input('quadrant') : null,
            'status'       => Request::input('status'),
            'due_date'     => Request::input('due_date'),
            'position'     => Request::input('position') !== null ? (int)Request::input('position') : null,
            'repeat_rule'  => Request::input('repeat_rule'),
            'repeat_until' => Request::input('repeat_until'),
        ], fn($v) => $v !== null);

        // Allow explicit empty string for due_date
        if (array_key_exists('due_date', Request::json())) {
            $data['due_date'] = Request::input('due_date', '');
        }

        $data = $this->validatePayload($data);
        Task::update($taskId, $userId, $data);
        $updated = Task::getById($taskId, $userId);
        
        $nextTask = null;
        if (isset($data['status']) && $data['status'] === 'done' && $task['status'] !== 'done') {
            // A repeating task schedules its next occurrence when it is ticked
            // off, so the finished one stays as history.
            $nextId   = Task::scheduleNextOccurrence($updated ?: $task, $userId);
            $nextTask = $nextId !== null ? Task::getById($nextId, $userId) : null;

            $msg = TelegramService::formatMessage(
                "✅ งานส่วนตัวเสร็จสมบูรณ์",
                [
                    'ชื่องาน' => htmlspecialchars($task['title'])
                ],
                'เสร็จสิ้น'
            );
            TelegramService::sendNotification($userId, 'task', $msg);
        }

        Response::json(array_filter([
            'ok'        => true,
            'task'      => $updated,
            'next_task' => $nextTask,
        ], static fn($v) => $v !== null));
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

    /**
     * จัดการงานค้างทั้งหมดของผู้ใช้ในครั้งเดียว
     *
     * รองรับ action: today | tomorrow | date | clear | delete
     * สำหรับ today/tomorrow/date จะย้ายทุกงานไปวันนั้น แล้ววางไว้ท้ายรายการตามลำดับวันเดิม
     * สำหรับ clear จะลบวันที่ออก สำหรับ delete จะลบถาวร
     *
     * @return void ส่ง JSON {ok: true, count: N}
     */
    public function apiOverdueBulk(): void
    {
        $userId = Auth::userId();
        $payload = Request::json();
        // ค่าที่ไม่ใช่สตริง (เช่น array จาก JSON) ถือว่าไม่ถูกต้อง ไม่แปลงชนิดให้เกิด warning
        $action  = is_string($payload['action'] ?? null) ? $payload['action'] : '';

        $allowed = ['today', 'tomorrow', 'date', 'clear', 'delete'];
        if (!in_array($action, $allowed, true)) {
            Response::json(['error' => 'action ไม่ถูกต้อง'], 422);
        }

        $date = null;
        if ($action === 'today') {
            $date = date('Y-m-d');
        } elseif ($action === 'tomorrow') {
            $date = date('Y-m-d', strtotime('+1 day'));
        } elseif ($action === 'date') {
            $date = is_string($payload['date'] ?? null) ? $payload['date'] : '';
            $parsed = DateTime::createFromFormat('Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                Response::json(['error' => 'รูปแบบวันที่ไม่ถูกต้อง'], 422);
            }
        }

        if ($action === 'delete') {
            $stmt = DB::run(
                'DELETE FROM tasks
                 WHERE user_id = ? AND status = "open" AND due_date < CURDATE()',
                [$userId]
            );
        } elseif ($action === 'clear') {
            $stmt = DB::run(
                'UPDATE tasks
                 SET due_date = NULL
                 WHERE user_id = ? AND status = "open" AND due_date < CURDATE()',
                [$userId]
            );
        } else {
            // ย้ายทุกงานค้างไปวันเป้าหมาย โดยรักษาลำดับวันเดิมด้วย ROW_NUMBER()
            // EDGE-12: งานทำซ้ำที่ค้างแล้วถูกเลื่อน จะเปลี่ยนแค่วันของรอบนี้
            // เมื่อติ๊กเสร็จภายหลังระบบจะสร้างรอบถัดไปจากวันที่ถูกเลื่อนมา
            $stmt = DB::run(
                'UPDATE tasks t
                 JOIN (
                     SELECT id, ROW_NUMBER() OVER (ORDER BY due_date ASC, id ASC) AS rn
                     FROM tasks
                     WHERE user_id = ? AND status = "open" AND due_date < CURDATE()
                 ) src ON t.id = src.id
                 JOIN (
                     SELECT COALESCE(MAX(day_position), -1) + 1 AS base
                     FROM tasks
                     WHERE user_id = ? AND due_date = ?
                 ) base
                 SET t.due_date = ?, t.day_position = base.base + src.rn - 1
                 WHERE t.user_id = ?',
                [$userId, $userId, $date, $date, $userId]
            );
        }

        Response::json(['ok' => true, 'count' => $stmt->rowCount()]);
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
                || (int)($item['quadrant'] ?? -1) < 0 || (int)($item['quadrant'] ?? -1) > 4
                || (int)($item['position'] ?? -1) < 0) {
                Response::json(['error' => 'ข้อมูลการเรียงงานไม่ถูกต้อง'], 422);
            }
        }

        Task::reorder($userId, $items);
        Response::json(['ok' => true]);
    }
}
