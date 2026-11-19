<?php
// =====================================================
// controllers/TaskController.php
// =====================================================

require_once ROOT . '/models/Task.php';
require_once ROOT . '/models/User.php';
require_once ROOT . '/models/PushSubscription.php';
require_once ROOT . '/core/TelegramService.php';

class TaskController
{
    /**
     * ตรวจสอบข้อมูลงานที่ส่งเข้ามา รองรับ quadrant 0 เป็น inbox และ normalise remind_at
     *
     * @param array<string, mixed> $data ข้อมูลงาน
     * @param bool $requireTitle บังคับให้มีชื่องานหรือไม่
     * @return array<string, mixed> ข้อมูลที่ผ่านการตรวจสอบ
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
            $date = DateTime::createFromFormat('Y-m-d', (string)$data['due_date']);
            if (!$date || $date->format('Y-m-d') !== $data['due_date']) {
                Response::json(['error' => 'รูปแบบวันที่ไม่ถูกต้อง'], 422);
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
        if (array_key_exists('remind_at', $data)) {
            $raw = (string)$data['remind_at'];
            if ($raw === '') {
                $data['remind_at'] = null;
            } else {
                $dt = DateTime::createFromFormat('Y-m-d H:i', $raw);
                if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw);
                if (!$dt || ($dt->format('Y-m-d H:i') !== $raw && $dt->format('Y-m-d H:i:s') !== $raw)) {
                    Response::json(['error' => 'รูปแบบเวลาเตือนไม่ถูกต้อง'], 422);
                }
                $data['remind_at'] = $dt->format('Y-m-d H:i:00');
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

        $userId = Auth::userId();
        $settings = User::getSettings($userId);
        $hasTelegram = !empty($settings['telegram_bot_token']) && !empty($settings['telegram_chat_id']);
        $hasPush = PushSubscription::countForUser($userId) > 0;
        $needsChannelHint = !$hasTelegram && !$hasPush;

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

    /**
     * สร้างงานใหม่ พร้อมตรวจสอบ remind_at ไม่ให้เป็นเวลาที่ผ่านมาแล้ว
     */
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
            'remind_at'    => Request::input('remind_at', ''),
        ], true);

        if (!empty($payload['remind_at']) && strtotime($payload['remind_at']) < time()) {
            Response::json(['error' => 'เวลาเตือนผ่านไปแล้ว'], 422);
        }

        $id = Task::create($userId, $payload);

        $task = Task::getById($id, $userId);
        Response::json(['ok' => true, 'task' => $task], 201);
    }

    /**
     * อัปเดตงาน รองรับการลบ remind_at ด้วย '' และตรวจสอบเวลาผ่านไปแล้วเฉพาะเมื่อเปลี่ยน
     *
     * @param string $id รหัสงาน
     */
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
            'remind_at'    => Request::input('remind_at'),
        ], fn($v) => $v !== null);

        // Allow explicit empty string for due_date and remind_at
        if (array_key_exists('due_date', Request::json())) {
            $data['due_date'] = Request::input('due_date', '');
        }
        if (array_key_exists('remind_at', Request::json())) {
            $data['remind_at'] = Request::input('remind_at', '');
        }

        $data = $this->validatePayload($data);

        if (isset($data['remind_at'])) {
            $oldNormalized = $task['remind_at'] ? date('Y-m-d H:i:00', strtotime($task['remind_at'])) : null;
            if ($data['remind_at'] !== $oldNormalized && strtotime($data['remind_at']) < time()) {
                Response::json(['error' => 'เวลาเตือนผ่านไปแล้ว'], 422);
            }
        }

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
