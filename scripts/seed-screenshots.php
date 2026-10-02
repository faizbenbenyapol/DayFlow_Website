<?php
// CLI-only: the web server must never run this, even if it is reachable.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}
// Gives the screenshot account a full, believable set of data.
//
//   php scripts/seed-screenshots.php
//
// scripts/screenshots.mjs signs in as this account to photograph every page
// before and after a UI change. Dates are counted from today, so the account
// always looks "in the middle of a month" instead of going stale. Running it
// again replaces the account's data, so the same command refreshes the dates.
//
// Development only: the account has a fixed, public password.

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/models/User.php';
require_once ROOT . '/models/AccountData.php';
require_once ROOT . '/models/StockPriceCache.php';

if (APP_ENV === 'production') {
    fwrite(STDERR, "ไม่รันบน production: บัญชีนี้มีรหัสผ่านที่เปิดเผยอยู่ในไฟล์นี้\n");
    exit(1);
}

const SHOTS_USERNAME = 'shots';
const SHOTS_EMAIL    = 'shots@example.test';
const SHOTS_PASSWORD = 'shots-dev-only-2569';

/** A date a number of days from today, as Y-m-d. */
function day(int $offset): string
{
    return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' day'));
}

/** A time on a day offset from today, as Y-m-d H:i:s. */
function at(int $offset, string $time): string
{
    return day($offset) . ' ' . $time . ':00';
}

$first = date('Y-m-01');

/** Like day(), but never before the 1st: the finance page shows one month at a time. */
function inMonth(int $offset): string
{
    return max(day($offset), date('Y-m-01'));
}

$data = [
    'format'   => AccountData::FORMAT,
    'version'  => AccountData::VERSION,
    'settings' => ['theme' => 'light', 'timezone' => 'Asia/Bangkok'],

    // quadrant 1 = do now, 2 = plan, 3 = delegate, 4 = drop
    'tasks' => [
        ['title' => 'ส่งรายงานสรุปโปรเจกต์ Q3 ให้หัวหน้าทีม', 'description' => 'แนบตารางงบประมาณฉบับล่าสุด', 'quadrant' => 1, 'status' => 'open', 'due_date' => day(-3), 'position' => 0],
        ['title' => 'เตรียมสไลด์นำเสนอลูกค้า', 'quadrant' => 1, 'status' => 'open', 'due_date' => day(0), 'position' => 1],
        ['title' => 'จ่ายบัตรเครดิต', 'description' => 'ยอด 12,480.00 บาท', 'quadrant' => 1, 'status' => 'open', 'due_date' => day(0), 'position' => 2],
        ['title' => 'วางแผนออกกำลังกายประจำสัปดาห์', 'quadrant' => 2, 'status' => 'open', 'due_date' => day(2), 'position' => 0],
        ['title' => 'อ่าน Clean Code บทที่ 4', 'quadrant' => 2, 'status' => 'open', 'due_date' => day(0), 'position' => 1],
        ['title' => 'อัพเดตเว็บไซต์ส่วนตัวให้ตรงกับผลงานล่าสุดและเพิ่มหน้า case study ของโปรเจกต์ที่เพิ่งส่งมอบ', 'quadrant' => 2, 'status' => 'open', 'due_date' => day(9), 'position' => 2],
        ['title' => 'ตอบอีเมลลูกค้าเรื่องใบเสนอราคา', 'quadrant' => 3, 'status' => 'open', 'due_date' => day(-1), 'position' => 0],
        ['title' => 'จัดโต๊ะทำงาน', 'quadrant' => 4, 'status' => 'open', 'due_date' => null, 'position' => 0],
        ['title' => 'จองโต๊ะร้านอาหารวันเสาร์', 'quadrant' => 1, 'status' => 'done', 'due_date' => day(0), 'position' => 3],
        ['title' => 'ส่งใบแจ้งหนี้เดือนที่แล้ว', 'quadrant' => 1, 'status' => 'done', 'due_date' => day(-4), 'position' => 4],
    ],

    'calendar_events' => [
        ['title' => 'ประชุมทีมประจำสัปดาห์', 'description' => 'Google Meet', 'start_datetime' => at(0, '09:00'), 'end_datetime' => at(0, '10:00'), 'is_all_day' => 0, 'repeat_rule' => 'weekly', 'repeat_until' => null, 'color' => '#3b82f6'],
        ['title' => 'นำเสนองานลูกค้า ABC', 'description' => 'ห้องประชุม 3', 'start_datetime' => at(0, '14:30'), 'end_datetime' => at(0, '16:00'), 'is_all_day' => 0, 'repeat_rule' => 'none', 'repeat_until' => null, 'color' => '#ef4444'],
        ['title' => 'วิ่ง 5 กม. สวนลุมพินี', 'description' => null, 'start_datetime' => at(0, '19:00'), 'end_datetime' => at(0, '20:00'), 'is_all_day' => 0, 'repeat_rule' => 'weekly', 'repeat_until' => null, 'color' => '#22c55e'],
        ['title' => 'ทันตแพทย์ขัดฟัน', 'description' => 'คลินิกใกล้บ้าน', 'start_datetime' => at(3, '11:00'), 'end_datetime' => at(3, '12:00'), 'is_all_day' => 0, 'repeat_rule' => 'none', 'repeat_until' => null, 'color' => '#f59e0b'],
        ['title' => 'วันเกิดแม่', 'description' => null, 'start_datetime' => at(6, '00:00'), 'end_datetime' => at(6, '23:59'), 'is_all_day' => 1, 'repeat_rule' => 'yearly', 'repeat_until' => null, 'color' => '#ec4899'],
        ['title' => 'ส่งภาษีหัก ณ ที่จ่าย', 'description' => null, 'start_datetime' => at(11, '00:00'), 'end_datetime' => at(11, '23:59'), 'is_all_day' => 1, 'repeat_rule' => 'monthly', 'repeat_until' => null, 'color' => '#8b5cf6'],
    ],

    'daily_todos' => [
        ['todo_date' => day(0), 'title' => 'โทรหาช่างแอร์', 'is_done' => 0, 'position' => 0],
        ['todo_date' => day(0), 'title' => 'ซื้อของเข้าบ้าน', 'is_done' => 0, 'position' => 1],
        ['todo_date' => day(0), 'title' => 'เติมน้ำมัน', 'is_done' => 1, 'position' => 2],
    ],

    'finance_categories' => [
        ['id' => 'c1', 'name' => 'เงินเดือน', 'type' => 'income'],
        ['id' => 'c2', 'name' => 'งานฟรีแลนซ์', 'type' => 'income'],
        ['id' => 'c3', 'name' => 'อาหาร', 'type' => 'expense'],
        ['id' => 'c4', 'name' => 'เดินทาง', 'type' => 'expense'],
        ['id' => 'c5', 'name' => 'ที่พัก', 'type' => 'expense'],
        ['id' => 'c6', 'name' => 'ช้อปปิ้ง', 'type' => 'expense'],
    ],
    'finances' => [
        ['type' => 'income',  'amount' => 45000.00, 'category_id' => 'c1', 'description' => 'เงินเดือนเดือนนี้', 'txn_date' => $first],
        ['type' => 'income',  'amount' => 8500.00,  'category_id' => 'c2', 'description' => 'ออกแบบโลโก้ร้านกาแฟ', 'txn_date' => inMonth(-6)],
        ['type' => 'expense', 'amount' => 9500.00,  'category_id' => 'c5', 'description' => 'ค่าเช่าคอนโด', 'txn_date' => $first],
        ['type' => 'expense', 'amount' => 641.93,   'category_id' => 'c5', 'description' => 'อินเทอร์เน็ตบ้าน', 'txn_date' => inMonth(-4)],
        ['type' => 'expense', 'amount' => 285.00,   'category_id' => 'c3', 'description' => 'ข้าวมันไก่ + ชาเย็น', 'txn_date' => day(0)],
        ['type' => 'expense', 'amount' => 1200.00,  'category_id' => 'c4', 'description' => 'เติมน้ำมัน', 'txn_date' => day(0)],
        ['type' => 'expense', 'amount' => 3650.50,  'category_id' => 'c6', 'description' => 'รองเท้าวิ่งคู่ใหม่', 'txn_date' => inMonth(-2)],
        ['type' => 'expense', 'amount' => 420.00,   'category_id' => 'c3', 'description' => 'อาหารเย็นกับเพื่อน', 'txn_date' => inMonth(-1)],
        ['type' => 'expense', 'amount' => 160.00,   'category_id' => 'c4', 'description' => 'BTS ทั้งสัปดาห์', 'txn_date' => inMonth(-3)],
    ],

    'subscriptions' => [
        ['name' => 'Spotify Family', 'amount' => 139.00, 'billing_cycle' => 'monthly', 'next_due_date' => day(2), 'alert_days' => 3, 'is_active' => 1, 'notes' => null],
        ['name' => 'Netflix Premium', 'amount' => 419.00, 'billing_cycle' => 'monthly', 'next_due_date' => day(5), 'alert_days' => 3, 'is_active' => 1, 'notes' => null],
        ['name' => 'อินเทอร์เน็ตบ้าน 1 Gbps', 'amount' => 641.93, 'billing_cycle' => 'monthly', 'next_due_date' => day(7), 'alert_days' => 3, 'is_active' => 1, 'notes' => null],
        ['name' => 'ประกันรถยนต์ชั้น 1', 'amount' => 18900.00, 'billing_cycle' => 'yearly', 'next_due_date' => day(41), 'alert_days' => 14, 'is_active' => 1, 'notes' => 'ต่อกับ ABC ประกันภัย'],
        ['name' => 'โดเมนเว็บไซต์ส่วนตัว', 'amount' => 590.00, 'billing_cycle' => 'yearly', 'next_due_date' => day(120), 'alert_days' => 14, 'is_active' => 1, 'notes' => null],
    ],

    'habits' => [
        ['id' => 'h1', 'name' => 'ดื่มน้ำ 8 แก้ว', 'color' => '#3b82f6', 'target_days' => 7, 'is_archived' => 0],
        ['id' => 'h2', 'name' => 'เดิน 8,000 ก้าว', 'color' => '#22c55e', 'target_days' => 5, 'is_archived' => 0],
        ['id' => 'h3', 'name' => 'อ่านหนังสือ 30 นาที', 'color' => '#f59e0b', 'target_days' => 5, 'is_archived' => 0],
        ['id' => 'h4', 'name' => 'นั่งสมาธิ 10 นาที', 'color' => '#8b5cf6', 'target_days' => 3, 'is_archived' => 0],
    ],
    'habit_logs' => array_merge(
        array_map(fn($i) => ['habit_id' => 'h1', 'log_date' => day(-$i)], range(0, 11)),
        array_map(fn($i) => ['habit_id' => 'h2', 'log_date' => day(-$i)], range(1, 3)),
        array_map(fn($i) => ['habit_id' => 'h3', 'log_date' => day(-$i)], range(0, 4)),
        [['habit_id' => 'h4', 'log_date' => day(-2)]]
    ),

    'focus_sessions' => [
        ['title' => 'เขียนรายงาน Q3', 'duration_min' => 25, 'type' => 'work', 'completed_at' => at(0, '08:10')],
        ['title' => 'เขียนรายงาน Q3', 'duration_min' => 25, 'type' => 'work', 'completed_at' => at(0, '08:40')],
        ['title' => 'เขียนรายงาน Q3', 'duration_min' => 5, 'type' => 'short_break', 'completed_at' => at(0, '08:45')],
        ['title' => 'อ่านบทความ', 'duration_min' => 25, 'type' => 'work', 'completed_at' => at(-1, '21:00')],
    ],

    'exercise_categories' => [
        ['name' => 'เวทเทรนนิ่ง'], ['name' => 'วิ่ง'], ['name' => 'โยคะ'],
    ],
    'workouts' => [
        ['workout_date' => day(-1), 'type' => 'เวทเทรนนิ่ง', 'duration_min' => 45, 'sets' => 4, 'reps' => 10, 'weight_kg' => 40.0, 'notes' => 'ช่วงบน'],
        ['workout_date' => day(-3), 'type' => 'วิ่ง', 'duration_min' => 35, 'sets' => null, 'reps' => null, 'weight_kg' => null, 'notes' => '5.2 กม.'],
        ['workout_date' => day(-5), 'type' => 'โยคะ', 'duration_min' => 30, 'sets' => null, 'reps' => null, 'weight_kg' => null, 'notes' => null],
    ],

    'food_notes' => [
        ['name' => 'กุ้ง', 'type' => 'food', 'reaction' => 'allergy', 'severity' => 'moderate', 'symptoms' => 'ผื่นคัน', 'notes' => 'ทานไม่ได้ทุกเมนู'],
        ['name' => 'นมวัว', 'type' => 'drink', 'reaction' => 'intolerance', 'severity' => 'mild', 'symptoms' => 'ท้องอืด', 'notes' => 'ใช้นมถั่วเหลืองแทน'],
    ],

    'notes' => [
        ['id' => 'n1', 'title' => 'ไอเดียเว็บไซต์ส่วนตัว', 'is_encrypted' => 0, 'pinned' => 1, 'created_at' => at(-8, '10:00'), 'updated_at' => at(-1, '22:15')],
        ['id' => 'n2', 'title' => 'ของที่ต้องซื้อ', 'is_encrypted' => 0, 'pinned' => 0, 'created_at' => at(-3, '18:00'), 'updated_at' => at(-3, '18:05')],
        ['id' => 'n3', 'title' => 'สรุปประชุมลูกค้า ABC', 'is_encrypted' => 0, 'pinned' => 0, 'created_at' => at(-10, '15:30'), 'updated_at' => at(-10, '16:45')],
    ],
    'note_blocks' => [
        ['note_id' => 'n1', 'type' => 'text', 'content' => 'หน้าแรกเปิดด้วยผลงานล่าสุด ไม่ต้องมีรูปประกอบ ให้ตัวหนังสือเป็นพระเอก', 'position' => 0],
        ['note_id' => 'n1', 'type' => 'link', 'content' => '{"url":"https://example.com/portfolio-reference","label":"พอร์ตโฟลิโอที่ชอบ"}', 'position' => 1],
        ['note_id' => 'n1', 'type' => 'checklist', 'content' => '[{"text":"เลือกฟอนต์","checked":true},{"text":"ร่างหน้า case study","checked":false},{"text":"ซื้อโดเมน","checked":true}]', 'position' => 2],
        ['note_id' => 'n2', 'type' => 'checklist', 'content' => '[{"text":"นมถั่วเหลือง","checked":false},{"text":"ไข่ไก่ 1 แผง","checked":false},{"text":"ผงซักฟอก","checked":true}]', 'position' => 0],
        ['note_id' => 'n3', 'type' => 'text', 'content' => 'ลูกค้าขอเลื่อนส่งมอบเป็นปลายเดือน ต้องปรับตารางงานของทีม และแจ้งฝ่ายบัญชีเรื่องใบแจ้งหนี้งวดที่สอง', 'position' => 0],
    ],
    'note_tags' => [['id' => 't1', 'name' => 'งาน'], ['id' => 't2', 'name' => 'ส่วนตัว']],
    'note_tag_relations' => [
        ['note_id' => 'n1', 'tag_id' => 't2'], ['note_id' => 'n3', 'tag_id' => 't1'],
    ],

    'quick_items' => [
        ['content' => 'โทรถามราคาเปลี่ยนแบตเตอรี่รถ', 'is_done' => 0],
        ['content' => 'อ่านรีวิวหูฟังตัวใหม่ก่อนซื้อ', 'is_done' => 0],
        ['content' => 'ส่งรูปงานแต่งให้พี่ตุ๊กตา', 'is_done' => 1],
    ],

    'bookmarks' => [
        ['title' => 'คู่มือ PHP', 'url' => 'https://www.php.net/manual/th/', 'category' => 'เอกสาร'],
        ['title' => 'IBM Plex', 'url' => 'https://www.ibm.com/plex/', 'category' => 'ฟอนต์'],
        ['title' => 'ตารางเดินรถไฟฟ้า', 'url' => 'https://www.bts.co.th/', 'category' => 'ชีวิตประจำวัน'],
    ],

    'skills' => [
        ['id' => 's1', 'name' => 'ภาษาญี่ปุ่น N4', 'target_hours' => 200, 'color' => '#ef4444', 'created_at' => at(-60, '09:00')],
        ['id' => 's2', 'name' => 'วาดรูป digital', 'target_hours' => 100, 'color' => '#8b5cf6', 'created_at' => at(-30, '09:00')],
    ],
    'skill_logs' => [
        ['skill_id' => 's1', 'start_time' => at(-1, '20:00'), 'end_time' => at(-1, '21:30'), 'duration_seconds' => 5400, 'notes' => 'คันจิ 20 ตัว', 'created_at' => at(-1, '21:30')],
        ['skill_id' => 's1', 'start_time' => at(-3, '20:00'), 'end_time' => at(-3, '22:00'), 'duration_seconds' => 7200, 'notes' => null, 'created_at' => at(-3, '22:00')],
        ['skill_id' => 's2', 'start_time' => at(-2, '14:00'), 'end_time' => at(-2, '15:00'), 'duration_seconds' => 3600, 'notes' => 'ฝึกลงสี', 'created_at' => at(-2, '15:00')],
    ],

    'stock_capital_flows' => [
        ['flow_type' => 'deposit', 'amount' => 12345678.90, 'currency' => 'THB', 'flow_date' => day(-90), 'notes' => 'เงินตั้งต้นพอร์ต'],
        ['flow_type' => 'deposit', 'amount' => 50000.00, 'currency' => 'THB', 'flow_date' => day(-20), 'notes' => null],
    ],
    'stock_transactions' => [
        ['ticker' => 'AAPL', 'market' => 'US',  'side' => 'buy',  'quantity' => 10,   'price' => 182.50, 'fee' => 1.00,  'currency' => 'USD', 'txn_date' => day(-60), 'notes' => null],
        ['ticker' => 'NVDA', 'market' => 'US',  'side' => 'buy',  'quantity' => 4,    'price' => 480.00, 'fee' => 1.00,  'currency' => 'USD', 'txn_date' => day(-45), 'notes' => null],
        ['ticker' => 'PTT',  'market' => 'SET', 'side' => 'buy',  'quantity' => 1000, 'price' => 34.25,  'fee' => 51.38, 'currency' => 'THB', 'txn_date' => day(-30), 'notes' => null],
        ['ticker' => 'AOT',  'market' => 'SET', 'side' => 'buy',  'quantity' => 500,  'price' => 61.00,  'fee' => 45.75, 'currency' => 'THB', 'txn_date' => day(-18), 'notes' => null],
        ['ticker' => 'AOT',  'market' => 'SET', 'side' => 'sell', 'quantity' => 200,  'price' => 63.50,  'fee' => 19.05, 'currency' => 'THB', 'txn_date' => day(-5),  'notes' => 'ขายทำกำไรบางส่วน'],
    ],
    'stock_watchlists' => [
        ['ticker' => 'MSFT', 'market' => 'US'], ['ticker' => 'CPALL', 'market' => 'SET'],
    ],
];

/** The account with this name, created with the shared password if it does not exist yet. */
function account(string $username, string $email, string $displayName): int
{
    $existing = DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn();
    return $existing ? (int)$existing : User::create($username, $email, SHOTS_PASSWORD, $displayName);
}

// A second account with nothing in it, for the screens a new user sees first.
// Importing an empty list for every table is how an account is emptied.
$empty = array_map(fn($v) => is_array($v) && array_is_list($v) ? [] : $v, $data);
$empty['settings'] = $data['settings'];

try {
    $shotsId = account(SHOTS_USERNAME, SHOTS_EMAIL, 'บัญชีถ่ายภาพ');
    $counts = AccountData::import($shotsId, $data);

    // The file manager's rows. Files and folders are not part of a backup, so they
    // are put in directly; the files have no bytes behind them, which is enough to
    // photograph the list.
    DB::run('DELETE FROM files WHERE user_id = ?', [$shotsId]);
    $folder = function (string $name, ?int $parent = null) use ($shotsId): int {
        DB::run("INSERT INTO files (user_id, parent_id, name, type) VALUES (?, ?, ?, 'folder')", [$shotsId, $parent, $name]);
        return (int)DB::conn()->lastInsertId();
    };
    $docs = $folder('เอกสารงาน');
    $folder('รูปภาพ');
    $folder('สัญญาและใบเสร็จ', $docs);
    foreach ([
        ['รายงาน Q3 ฉบับร่าง.pdf', 'application/pdf', 1843200],
        ['ตารางงบประมาณ.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 96256],
        ['โน้ตประชุมลูกค้า ABC.txt', 'text/plain', 3412],
        ['พอร์ตโฟลิโอ 2569.zip', 'application/zip', 24117248],
        ['ภาพหน้าจอ 2569-10-01.png', 'image/png', 512000],
    ] as [$name, $mime, $size]) {
        DB::run('INSERT INTO files (user_id, parent_id, name, type, mime_type, file_path, file_size) VALUES (?, NULL, ?, \'file\', ?, ?, ?)',
            [$shotsId, $name, $mime, 'seed/missing-' . md5($name), $size]);
    }
    AccountData::import(account(SHOTS_USERNAME . '_empty', 'shots-empty@example.test', 'ผู้ใช้ใหม่'), $empty);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . ($e->getPrevious() ? ' — ' . $e->getPrevious()->getMessage() : '') . "\n");
    exit(1);
}

// Quotes for the stocks above. The price cache is shared by every account, and a
// ratio left out here stays blank on the page, as it does with a real provider.
DB::run('DELETE FROM stock_price_cache');
foreach ([
    ['AAPL',  191.20, 189.80, 'USD', 31.25, 28.40, 1.42, 26.50, 6.45],
    ['NVDA',  502.30, 508.10, 'USD', 68.50, 32.40, 1.15, 44.80, 2.10],
    ['PTT',    33.50,  34.00, 'THB', 11.40, 10.20, 0.95,  8.50, 3.20],
    ['AOT',    63.00,  62.50, 'THB', 23.00, 19.55, 1.60, 29.00, 4.60],
    ['MSFT',  410.60, 408.90, 'USD', 35.40, 31.20, 1.85, 32.10, 11.60],
    ['CPALL',  58.25,  58.25, 'THB', null,  null,  null, null,  null],
] as [$ticker, $price, $prev, $currency, $pe, $forwardPe, $peg, $pFcf, $eps]) {
    StockPriceCache::upsert($ticker, $price, $prev, $currency, $pe, $forwardPe, $peg, $pFcf, $eps);
}

echo 'ใส่ข้อมูลให้บัญชี ' . SHOTS_USERNAME . ' แล้ว: ' . array_sum($counts) . ' แถวใน ' . count($counts) . " ตาราง\n";
echo 'บัญชี ' . SHOTS_USERNAME . "_empty ไม่มีข้อมูล (สำหรับถ่ายสถานะผู้ใช้ใหม่)\n";
