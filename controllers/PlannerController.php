<?php
// =====================================================
// controllers/PlannerController.php
// =====================================================

require_once ROOT . '/models/CalendarEvent.php';
require_once ROOT . '/models/Task.php';
require_once ROOT . '/core/TelegramService.php';

class PlannerController
{
    private function validDate(string $value, bool $dateTime = false): bool
    {
        $format = $dateTime ? 'Y-m-d H:i:s' : 'Y-m-d';
        $parsed = DateTime::createFromFormat($format, $value);
        return $parsed && $parsed->format($format) === $value;
    }

    public function index(): void
    {
        $pageTitle  = 'แพลนเนอร์';
        $pageStyle  = 'planner';
        $pageScript = 'planner';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/planner/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    // --- Events ---

    public function apiEventsList(): void
    {
        $userId = Auth::userId();
        $year   = (int)Request::query('year', (int)date('Y'));
        $month  = (int)Request::query('month', (int)date('n'));

        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) Response::json(['error' => 'ช่วงเดือนไม่ถูกต้อง'], 422);
        $events = CalendarEvent::getForMonth($userId, $year, $month);
        Response::json(['events' => $events]);
    }

    public function apiEventCreate(): void
    {
        $userId = Auth::userId();
        $data   = $this->validateEventData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        $id = CalendarEvent::create($userId, $data);
        $ev = CalendarEvent::getById($id, $userId);
        $timeStr = TelegramService::formatThaiDateTime($data['start_datetime']);
        $msg = TelegramService::formatMessage(
            "📅 กิจกรรมใหม่ในแพลนเนอร์",
            [
                'หัวข้อ' => htmlspecialchars($data['title']),
                'เริ่ม' => $timeStr
            ]
        );
        TelegramService::sendNotification($userId, 'planner', $msg);

        Response::json(['ok' => true, 'event' => $ev], 201);
    }

    public function apiEventUpdate(string $id): void
    {
        $userId  = Auth::userId();
        $eventId = (int)$id;

        $ev = CalendarEvent::getById($eventId, $userId);
        if (!$ev) Response::json(['error' => 'ไม่พบรายการ'], 404);

        $data = $this->validateEventData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        CalendarEvent::update($eventId, $userId, $data);
        Response::json(['ok' => true]);
    }

    public function apiEventDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!CalendarEvent::delete((int)$id, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    /**
     * GET /api/planner/events/export.ics
     *
     * Exports the stored events, not the expanded occurrences: a repeating
     * event travels as one VEVENT with an RRULE, which is how every calendar
     * app expects to receive it.
     */
    public function apiEventsExport(): void
    {
        $userId = Auth::userId();

        $events = DB::run(
            'SELECT id, title, description, start_datetime, end_datetime,
                    is_all_day, repeat_rule, repeat_until
             FROM calendar_events
             WHERE user_id = ?
             ORDER BY start_datetime ASC
             LIMIT 5000',
            [$userId]
        )->fetchAll();

        $body = Ics::export($events, APP_NAME . ' — ปฏิทิน');

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: ' . contentDisposition('dayflow-calendar.ics'));
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store, private');
        echo $body;
        exit;
    }

    /**
     * POST /api/planner/events/import
     *
     * Accepts an .ics upload. Events already present — same title on the same
     * start — are skipped, so importing the same file twice is harmless.
     */
    public function apiEventsImport(): void
    {
        $userId = Auth::userId();

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'กรุณาเลือกไฟล์ .ics'], 422);
        }
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            Response::json(['error' => 'ไฟล์ใหญ่เกิน 2 MB'], 422);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            Response::json(['error' => 'ไฟล์ไม่ถูกต้อง'], 422);
        }

        $raw = (string)file_get_contents($file['tmp_name']);
        if (stripos($raw, 'BEGIN:VEVENT') === false) {
            Response::json(['error' => 'ไม่พบกิจกรรมในไฟล์นี้'], 422);
        }

        $parsed = Ics::parse($raw);
        if ($parsed === []) {
            Response::json(['error' => 'อ่านกิจกรรมจากไฟล์ไม่ได้'], 422);
        }
        if (count($parsed) > 1000) {
            Response::json(['error' => 'ไฟล์มีกิจกรรมมากเกินไป (สูงสุด 1,000 รายการ)'], 422);
        }

        $imported = 0;
        $skipped  = 0;
        foreach ($parsed as $event) {
            $exists = DB::run(
                'SELECT 1 FROM calendar_events
                 WHERE user_id = ? AND title = ? AND start_datetime = ? LIMIT 1',
                [$userId, $event['title'], $event['start_datetime']]
            )->fetchColumn();

            if ($exists) { $skipped++; continue; }

            CalendarEvent::create($userId, $event + ['color' => '#3b82f6']);
            $imported++;
        }

        Response::json(['ok' => true, 'imported' => $imported, 'skipped' => $skipped]);
    }

    private function validateEventData(): array
    {
        $title    = Request::input('title', '');
        $start    = Request::input('start_datetime', '');
        if (!$title) return ['error' => 'กรุณากรอกชื่อกิจกรรม'];
        if (!$start) return ['error' => 'กรุณากรอกวันที่เริ่มต้น'];

        $title = trim($title);
        $end = Request::input('end_datetime', '');
        if (mb_strlen($title) > 255 || !$this->validDate($start, true)) return ['error' => 'ข้อมูลกิจกรรมไม่ถูกต้อง'];
        if ($end !== '' && (!$this->validDate($end, true) || strtotime($end) < strtotime($start))) return ['error' => 'เวลาสิ้นสุดไม่ถูกต้อง'];

        $repeatRule  = (string)Request::input('repeat_rule', 'none');
        $repeatUntil = (string)Request::input('repeat_until', '');
        if (!Recurrence::isValid($repeatRule)) return ['error' => 'รูปแบบการทำซ้ำไม่ถูกต้อง'];
        if ($repeatUntil !== '') {
            if (!$this->validDate($repeatUntil, false)) return ['error' => 'วันสิ้นสุดการทำซ้ำไม่ถูกต้อง'];
            if ($repeatUntil < substr($start, 0, 10)) return ['error' => 'วันสิ้นสุดการทำซ้ำต้องไม่มาก่อนวันเริ่มต้น'];
        }

        return [
            'title'          => $title,
            'description'    => mb_substr(Request::input('description', ''), 0, 2000),
            'start_datetime' => $start,
            'end_datetime'   => $end,
            'is_all_day'     => (int)Request::input('is_all_day', 0),
            'repeat_rule'    => $repeatRule,
            'repeat_until'   => $repeatUntil,
            'color'          => Request::input('color', '#555555'),
        ];
    }

    // --- Todos ---

    /**
     * คืนรายการ to-do ของวันที่ระบุในรูปแบบเดิม (id, title, is_done, position)
     *
     * ถ้าอยู่ในโหมดแชร์ read-only ที่แชร์ planner แต่ไม่แชร์ tasks
     * จะคืนเฉพาะงานที่ไม่มี quadrant (เทียบเท่า to-do รายวันเดิม)
     */
    public function apiTodosList(): void
    {
        $userId = Auth::userId();
        $date   = Request::query('date', date('Y-m-d'));
        $inboxOnly = Auth::isReadOnly() && !in_array('tasks', Auth::getSharedMenus(), true);
        $rows   = Task::getForDate($userId, $date, $inboxOnly);
        Response::json(['todos' => array_map([$this, 'formatTodo'], $rows)]);
    }

    /**
     * สร้าง to-do ใหม่ในวันที่ระบุ เก็บเป็น task ที่มี quadrant null
     */
    public function apiTodoCreate(): void
    {
        $userId = Auth::userId();
        $title  = Request::input('title', '');
        $date   = Request::input('date', date('Y-m-d'));

        if (!$title) Response::json(['error' => 'กรุณากรอกรายการ'], 422);

        $title = trim($title);
        if (mb_strlen($title) > 255 || !$this->validDate($date)) Response::json(['error' => 'ข้อมูลรายการประจำวันไม่ถูกต้อง'], 422);

        $id = Task::create($userId, [
            'title'        => $title,
            'due_date'     => $date,
            'quadrant'     => null,
            'day_position' => Task::nextPositionForDate($userId, $date),
        ]);

        $row = Task::getById($id, $userId);
        Response::json(['ok' => true, 'todo' => $row ? $this->formatTodo($row) : null], 201);
    }

    /**
     * อัปเดตชื่อและ/หรือสถานะของ to-do
     *
     * ถ้า task เปลี่ยนจาก open เป็น done จะสร้าง occurrence ถัดไปให้งานที่ทำซ้ำ
     *
     * @param string $id รหัส task
     */
    public function apiTodoUpdate(string $id): void
    {
        $userId  = Auth::userId();
        $todoId  = (int)$id;
        $todo    = Task::getById($todoId, $userId);
        if (!$todo) Response::json(['error' => 'ไม่พบรายการ'], 404);

        $payload = [];
        $title   = Request::input('title');
        if ($title !== null) {
            $title = trim($title);
            if ($title === '' || mb_strlen($title) > 255) Response::json(['error' => 'ข้อมูลรายการประจำวันไม่ถูกต้อง'], 422);
            $payload['title'] = $title;
        }

        $isDone = Request::input('is_done');
        if ($isDone !== null) {
            $payload['status'] = (int)$isDone ? 'done' : 'open';
        }

        Task::update($todoId, $userId, $payload);
        $updated = Task::getById($todoId, $userId);

        $nextTask = null;
        if (isset($payload['status']) && $payload['status'] === 'done' && $todo['status'] !== 'done') {
            $nextId   = Task::scheduleNextOccurrence($updated ?: $todo, $userId);
            $nextTask = $nextId !== null ? Task::getById($nextId, $userId) : null;
        }

        Response::json(array_filter([
            'ok'        => true,
            'todo'      => $updated ? $this->formatTodo($updated) : null,
            'next_task' => $nextTask,
        ], static fn($v) => $v !== null));
    }

    /**
     * ลบ to-do ตามรหัส
     *
     * @param string $id รหัส task
     */
    public function apiTodoDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!Task::delete((int)$id, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    /**
     * เรียงลำดับ to-do ในวันเดียวกัน
     */
    public function apiTodoReorder(): void
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
                || (int)($item['position'] ?? -1) < 0) {
                Response::json(['error' => 'ข้อมูลการเรียงงานไม่ถูกต้อง'], 422);
            }
        }

        Task::reorderPositions($userId, $items);
        Response::json(['ok' => true]);
    }

    /**
     * แปลงแถว task เป็นรูปแบบ to-do ที่ planner.js คาดหวัง
     *
     * @param array<string, mixed> $row แถวจาก Task
     * @return array<string, mixed> แถวพร้อม id, title, is_done, position, quadrant
     */
    private function formatTodo(array $row): array
    {
        return [
            'id'       => (int)$row['id'],
            'title'    => $row['title'],
            'is_done'  => ($row['status'] ?? 'open') === 'done' ? 1 : 0,
            'position' => (int)$row['day_position'],
            'quadrant' => $row['quadrant'] === null ? null : (int)$row['quadrant'],
        ];
    }
}
