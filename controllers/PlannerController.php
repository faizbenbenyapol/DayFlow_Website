<?php
// =====================================================
// controllers/PlannerController.php
// =====================================================

require_once ROOT . '/models/CalendarEvent.php';
require_once ROOT . '/models/DailyTodo.php';
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

        return [
            'title'          => $title,
            'description'    => mb_substr(Request::input('description', ''), 0, 2000),
            'start_datetime' => $start,
            'end_datetime'   => $end,
            'is_all_day'     => (int)Request::input('is_all_day', 0),
            'color'          => Request::input('color', '#555555'),
        ];
    }

    // --- Todos ---

    public function apiTodosList(): void
    {
        $userId = Auth::userId();
        $date   = Request::query('date', date('Y-m-d'));
        $todos  = DailyTodo::getForDate($userId, $date);
        Response::json(['todos' => $todos]);
    }

    public function apiTodoCreate(): void
    {
        $userId = Auth::userId();
        $title  = Request::input('title', '');
        $date   = Request::input('date', date('Y-m-d'));

        if (!$title) Response::json(['error' => 'กรุณากรอกรายการ'], 422);

        $title = trim($title);
        if (mb_strlen($title) > 255 || !$this->validDate($date)) Response::json(['error' => 'ข้อมูลรายการประจำวันไม่ถูกต้อง'], 422);
        $id   = DailyTodo::create($userId, $date, $title);
        $todo = DailyTodo::getById($id, $userId);
        Response::json(['ok' => true, 'todo' => $todo], 201);
    }

    public function apiTodoUpdate(string $id): void
    {
        $userId  = Auth::userId();
        $todoId  = (int)$id;
        $todo    = DailyTodo::getById($todoId, $userId);
        if (!$todo) Response::json(['error' => 'ไม่พบรายการ'], 404);

        $data = array_filter([
            'title'   => Request::input('title'),
            'is_done' => Request::input('is_done') !== null ? (int)Request::input('is_done') : null,
        ], fn($v) => $v !== null);

        DailyTodo::update($todoId, $userId, $data);
        Response::json(['ok' => true]);
    }

    public function apiTodoDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!DailyTodo::delete((int)$id, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    public function apiTodoReorder(): void
    {
        $userId = Auth::userId();
        $items  = Request::json()['items'] ?? [];
        DailyTodo::reorder($userId, $items);
        Response::json(['ok' => true]);
    }
}
