<?php

require_once ROOT . '/models/Habit.php';

class HabitController
{
    public function index(): void
    {
        $pageTitle = 'นิสัยประจำวัน';
        $pageStyle = 'habits';
        $pageScript = 'habits';
        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/habits/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    /**
     * The habits, each with its streak. With from and to (at most 42 days apart)
     * it also returns which days each was done in that window, as logs.
     */
    public function apiList(): void
    {
        $userId = Auth::userId();
        $streaks = Habit::streaks($userId);
        $habits = array_map(
            fn(array $h): array => $h + ['streak' => $streaks[(int)$h['id']] ?? 0],
            Habit::listForUser($userId)
        );

        $payload = ['habits' => $habits];

        $from = (string)Request::query('from', '');
        $to = (string)Request::query('to', '');
        if ($from !== '' || $to !== '') {
            $start = $this->validDate($from);
            $end = $this->validDate($to);
            if ($start === null || $end === null || $end < $start || strtotime($end) - strtotime($start) > 42 * 86400) {
                Response::json(['error' => 'ช่วงวันที่ไม่ถูกต้อง'], 422);
            }
            $payload['logs'] = (object)Habit::logsBetween($userId, $start, $end);
        }

        Response::json($payload);
    }

    /** A Y-m-d date, or null when the text is not exactly one. */
    private function validDate(string $text): ?string
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $text);
        return $parsed && $parsed->format('Y-m-d') === $text ? $text : null;
    }

    public function apiCreate(): void
    {
        $name = trim((string)Request::input('name', ''));
        $color = trim((string)Request::input('color', '#6366f1'));
        $target = max(1, min(7, (int)Request::input('target_days', 7)));
        if ($name === '' || mb_strlen($name) > 160) Response::json(['error' => 'กรุณากรอกชื่อนิสัยไม่เกิน 160 ตัวอักษร'], 422);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6366f1';
        Response::json(['ok' => true, 'id' => Habit::create(Auth::userId(), $name, $color, $target)], 201);
    }

    public function apiUpdate(string $id): void
    {
        $name = trim((string)Request::input('name', ''));
        $color = trim((string)Request::input('color', '#6366f1'));
        $target = max(1, min(7, (int)Request::input('target_days', 7)));
        if ($name === '' || mb_strlen($name) > 160) Response::json(['error' => 'ข้อมูลนิสัยไม่ถูกต้อง'], 422);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6366f1';
        if (!Habit::update((int)$id, Auth::userId(), $name, $color, $target)) Response::json(['error' => 'ไม่พบรายการ'], 404);
        Response::json(['ok' => true]);
    }

    /**
     * Ticks a habit for today, or for an earlier day when "date" is given. A day
     * that has not come yet cannot be ticked, and nor can one more than a year back.
     */
    public function apiToggle(string $id): void
    {
        $userId = Auth::userId();
        if (!Habit::get((int)$id, $userId)) Response::json(['error' => 'ไม่พบรายการ'], 404);

        $date = trim((string)Request::input('date', ''));
        if ($date === '') {
            $done = Habit::toggleToday((int)$id, $userId);
            Response::json(['ok' => true, 'completed' => $done, 'completed_today' => $done]);
        }

        $valid = $this->validDate($date);
        $today = Habit::today();
        if ($valid === null) Response::json(['error' => 'วันที่ไม่ถูกต้อง'], 422);
        if ($valid > $today) Response::json(['error' => 'ติ๊กวันที่ยังมาไม่ถึงไม่ได้'], 422);
        if ($valid < date('Y-m-d', strtotime($today . ' -365 day'))) Response::json(['error' => 'ย้อนหลังได้ไม่เกินหนึ่งปี'], 422);

        $done = Habit::toggleOn((int)$id, $userId, $valid);
        Response::json(['ok' => true, 'completed' => $done, 'completed_today' => $valid === $today ? $done : self::doneToday((int)$id, $userId)]);
    }

    private static function doneToday(int $id, int $userId): bool
    {
        return isset(Habit::logsBetween($userId, Habit::today(), Habit::today())[$id]);
    }

    public function apiDelete(string $id): void
    {
        if (!Habit::archive((int)$id, Auth::userId())) Response::json(['error' => 'ไม่พบรายการ'], 404);
        Response::json(['ok' => true]);
    }
}
