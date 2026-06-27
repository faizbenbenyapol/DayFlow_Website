<?php
// =====================================================
// cron.php — Time-based Telegram Notifications
// Run this file via CLI or Web URL (Cron Job)
// =====================================================

if (!defined('ROOT')) define('ROOT', __DIR__);

require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/core/TelegramService.php';

// Security check: Only allow CLI, logged-in session, or with a valid token
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    require_once ROOT . '/config/session.php';
}
$isLoggedIn = isset($_SESSION['user_id']);
$token = $_GET['token'] ?? '';
$expectedToken = hash('sha256', appKey() . 'cron');

if (!$isCli && !$isLoggedIn && $token !== $expectedToken) {
    http_response_code(403);
    die("Access Denied. Invalid or missing token.\n");
}

echo "Starting DayFlow Cron Job...\n";

// 1. Auto-migrate if table doesn't exist
try {
    DB::run('SELECT 1 FROM `telegram_cron_logs` LIMIT 1');
} catch (Exception $e) {
    $sqlFile = ROOT . '/sql/migrate_telegram_cron.sql';
    if (file_exists($sqlFile)) {
        try {
            DB::conn()->exec(file_get_contents($sqlFile));
            echo "Created telegram_cron_logs table.\n";
        } catch (Exception $ex) {
            echo "Error running migration: " . $ex->getMessage() . "\n";
        }
    } else {
        echo "Migration file not found: {$sqlFile}\n";
    }
}

// 2. Fetch users with valid Telegram settings
$users = DB::run("
    SELECT user_id, telegram_bot_token, telegram_chat_id, telegram_notify_events, timezone 
    FROM user_settings 
    WHERE telegram_bot_token != '' AND telegram_chat_id != ''
")->fetchAll();

if (empty($users)) {
    echo "No users configured for Telegram.\n";
    return;
}

$sentCount = 0;

foreach ($users as $u) {
    $userId = (int)$u['user_id'];
    $eventsStr = $u['telegram_notify_events'] ?: '{}';
    $events = json_decode($eventsStr, true) ?: [];

    // Check if user has disabled notifications for specific types
    $notifyPlanner = !isset($events['planner']) || $events['planner'];
    $notifyTask = !isset($events['task']) || $events['task'];
    $notifySub = !isset($events['subscription']) || $events['subscription'];

    // Get user's timezone (fallback to system default if not set/invalid)
    $tzName = $u['timezone'] ?: 'Asia/Bangkok';
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Exception $e) {
        $tz = new DateTimeZone('Asia/Bangkok');
    }

    $userTodayObj = new DateTime('now', $tz);
    $today = $userTodayObj->format('Y-m-d');

    $userTomorrowObj = clone $userTodayObj;
    $userTomorrowObj->modify('+1 day');
    $tomorrow = $userTomorrowObj->format('Y-m-d');

    // --- A. Planner Events (calendar_events) ---
    if ($notifyPlanner) {
        $evs = DB::run("
            SELECT id, title, start_datetime 
            FROM calendar_events 
            WHERE user_id = ? AND DATE(start_datetime) IN (?, ?)
        ", [$userId, $today, $tomorrow])->fetchAll();
        foreach ($evs as $ev) {
            $date = substr($ev['start_datetime'], 0, 10);
            $isToday = ($date === $today);
            $dayLabel = $isToday ? 'วันนี้' : 'พรุ่งนี้';
            
            if (shouldNotify($userId, 'planner', $ev['id'], $date)) {
                $timeStr = TelegramService::formatThaiDateTime($ev['start_datetime']);
                $msg = TelegramService::formatMessage(
                    "📅 กิจกรรม{$dayLabel}",
                    [
                        'หัวข้อ' => htmlspecialchars($ev['title']),
                        'เริ่ม' => $timeStr
                    ]
                );
                TelegramService::sendMessage($u['telegram_bot_token'], $u['telegram_chat_id'], $msg);
                logNotification($userId, 'planner', $ev['id'], $date);
                $sentCount++;
            }
        }
    }

    // --- B. Tasks (Personal Tasks) ---
    if ($notifyTask) {
        $tasks = DB::run("
            SELECT id, title, due_date 
            FROM tasks 
            WHERE user_id = ? AND status != 'done' AND due_date IN (?, ?)
        ", [$userId, $today, $tomorrow])->fetchAll();

        foreach ($tasks as $t) {
            $isToday = ($t['due_date'] === $today);
            $dayLabel = $isToday ? 'วันนี้' : 'พรุ่งนี้';

            if (shouldNotify($userId, 'task', $t['id'], $t['due_date'])) {
                $dueDate = TelegramService::formatThaiDate($t['due_date']);
                $msg = TelegramService::formatMessage(
                    "📌 งานที่ถึงกำหนด{$dayLabel}",
                    [
                        'ชื่องาน' => htmlspecialchars($t['title']),
                        'กำหนดส่ง' => $dueDate
                    ]
                );
                TelegramService::sendMessage($u['telegram_bot_token'], $u['telegram_chat_id'], $msg);
                logNotification($userId, 'task', $t['id'], $t['due_date']);
                $sentCount++;
            }
        }
    }

    // --- C. Project Tasks ---
    if ($notifyTask) {
        // Only fetch project tasks where the project is not completed and task is not done
        $pTasks = DB::run("
            SELECT pt.id, pt.title, pt.due_date, p.name as project_name
            FROM project_tasks pt
            JOIN projects p ON pt.project_id = p.id
            WHERE pt.user_id = ? AND pt.status != 'Done' AND pt.due_date IN (?, ?) AND p.status != 'Completed'
        ", [$userId, $today, $tomorrow])->fetchAll();

        foreach ($pTasks as $pt) {
            $isToday = ($pt['due_date'] === $today);
            $dayLabel = $isToday ? 'วันนี้' : 'พรุ่งนี้';

            if (shouldNotify($userId, 'project_task', $pt['id'], $pt['due_date'])) {
                $dueDate = TelegramService::formatThaiDate($pt['due_date']);
                $msg = TelegramService::formatMessage(
                    "📌 งานโปรเจคที่ถึงกำหนด{$dayLabel}",
                    [
                        'ชื่องาน' => htmlspecialchars($pt['title']),
                        'โปรเจค' => htmlspecialchars($pt['project_name']),
                        'กำหนดส่ง' => $dueDate
                    ]
                );
                TelegramService::sendMessage($u['telegram_bot_token'], $u['telegram_chat_id'], $msg);
                logNotification($userId, 'project_task', $pt['id'], $pt['due_date']);
                $sentCount++;
            }
        }
    }

    // --- D. Subscriptions (Alerts) ---
    if ($notifySub) {
        // Fetch active subscriptions where next_due_date <= today + alert_days
        $subs = DB::run("
            SELECT id, name, next_due_date, alert_days, amount
            FROM subscriptions
            WHERE user_id = ? AND is_active = 1
        ", [$userId])->fetchAll();

        foreach ($subs as $sub) {
            $dueTime = strtotime($sub['next_due_date']);
            $alertTime = strtotime("-{$sub['alert_days']} days", $dueTime);
            $nowTime = strtotime($today);

            // If today is on or after the alert date, and before or on the due date
            if ($nowTime >= $alertTime && $nowTime <= $dueTime) {
                // If we haven't notified for this specific due date cycle
                if (shouldNotify($userId, 'subscription', $sub['id'], $sub['next_due_date'])) {
                    $formattedDate = TelegramService::formatThaiDate($sub['next_due_date']);
                    $msg = TelegramService::formatMessage(
                        "🔔 บิลใกล้ถึงกำหนดชำระ",
                        [
                            'รายการ' => htmlspecialchars($sub['name']),
                            'วันครบกำหนด' => $formattedDate,
                            'ยอดชำระทั้งหมด' => "฿" . number_format($sub['amount'], 2)
                        ],
                        'รอการชำระ'
                    );
                    TelegramService::sendMessage($u['telegram_bot_token'], $u['telegram_chat_id'], $msg);
                    logNotification($userId, 'subscription', $sub['id'], $sub['next_due_date']);
                    $sentCount++;
                }
            }
        }
    }
}

echo "Done! Sent {$sentCount} notifications.\n";

// --- Helpers ---
if (!function_exists('shouldNotify')) {
    function shouldNotify(int $userId, string $type, int $id, string $refDate): bool {
        $exists = DB::run("
            SELECT 1 FROM telegram_cron_logs 
            WHERE item_type = ? AND item_id = ? AND reference_date = ?
        ", [$type, $id, $refDate])->fetchColumn();
        return !$exists;
    }
}

if (!function_exists('logNotification')) {
    function logNotification(int $userId, string $type, int $id, string $refDate): void {
        DB::run("
            INSERT IGNORE INTO telegram_cron_logs (user_id, item_type, item_id, reference_date) 
            VALUES (?, ?, ?, ?)
        ", [$userId, $type, $id, $refDate]);
    }
}
