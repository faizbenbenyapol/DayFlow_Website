<?php
// =====================================================
// core/NotificationDigest.php — what is worth notifying about right now
//
// Shared by two callers: the cron decides whether to wake a browser at all,
// and the service worker asks for the same list to decide what to display.
// Keeping it in one place means the push and the notification always agree.
// =====================================================

final class NotificationDigest
{
    /**
     * @return array<int, array{title: string, body: string, url: string, tag: string}>
     */
    public static function pendingFor(int $userId): array
    {
        $items = [];

        $overdue = (int)DB::run(
            'SELECT COUNT(*) FROM tasks
             WHERE user_id = ? AND status = "open" AND due_date IS NOT NULL AND due_date < CURDATE()',
            [$userId]
        )->fetchColumn();

        if ($overdue > 0) {
            $items[] = [
                'title' => 'มีงานเกินกำหนด ' . $overdue . ' รายการ',
                'body'  => 'เปิดดูรายการงานที่ค้างอยู่',
                'url'   => APP_URL . '/tasks',
                'tag'   => 'tasks-overdue',
            ];
        }

        $dueToday = (int)DB::run(
            'SELECT COUNT(*) FROM tasks
             WHERE user_id = ? AND status = "open" AND due_date = CURDATE()',
            [$userId]
        )->fetchColumn();

        if ($dueToday > 0) {
            $items[] = [
                'title' => 'งานครบกำหนดวันนี้ ' . $dueToday . ' รายการ',
                'body'  => 'อย่าลืมเคลียร์ก่อนหมดวัน',
                'url'   => APP_URL . '/tasks',
                'tag'   => 'tasks-today',
            ];
        }

        $subscriptions = DB::run(
            'SELECT name, next_due_date FROM subscriptions
             WHERE user_id = ? AND is_active = 1
               AND next_due_date >= CURDATE()
               AND next_due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
             ORDER BY next_due_date ASC LIMIT 3',
            [$userId]
        )->fetchAll();

        foreach ($subscriptions as $subscription) {
            $items[] = [
                'title' => 'ใกล้ถึงกำหนดชำระ: ' . $subscription['name'],
                'body'  => 'ครบกำหนด ' . thaiDate((string)$subscription['next_due_date']),
                'url'   => APP_URL . '/subscriptions',
                'tag'   => 'subscription-' . md5((string)$subscription['name']),
            ];
        }

        // Only events still ahead: a reminder for something that finished two
        // hours ago is noise.
        $events = DB::run(
            'SELECT title, start_datetime FROM calendar_events
             WHERE user_id = ?
               AND start_datetime >= NOW()
               AND start_datetime < CURDATE() + INTERVAL 1 DAY
             ORDER BY start_datetime ASC LIMIT 3',
            [$userId]
        )->fetchAll();

        foreach ($events as $event) {
            $items[] = [
                'title' => 'กิจกรรมวันนี้: ' . $event['title'],
                'body'  => 'เวลา ' . date('H:i', strtotime((string)$event['start_datetime'])) . ' น.',
                'url'   => APP_URL . '/planner',
                'tag'   => 'event-' . md5((string)$event['title'] . $event['start_datetime']),
            ];
        }

        return $items;
    }
}
