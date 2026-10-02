<?php
// =====================================================
// views/dashboard/index.php — the Today page
//
// The date, what needs doing, what is on, and where the money stands. The
// markup is only the frame: each section arrives as a skeleton and
// assets/js/dashboard.js fills it from /api/dashboard/summary.
// =====================================================

$thaiDays = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
$thaiMonths = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$thaiMonthsShort = [1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

$now = new DateTimeImmutable('now');
$month = (int)$now->format('n');
$fullDate = $now->format('j') . ' ' . $thaiMonths[$month] . ' ' . ((int)$now->format('Y') + 543);

// Which menu each section belongs to: hiding the menu hides its section.
$sectionMenu = [
    'tasks' => 'tasks', 'calendar' => 'planner', 'habits' => 'habits',
    'finance' => 'finance', 'subscriptions' => 'subscriptions', 'workout' => 'exercise',
    'projects' => 'projects', 'notes' => 'notes', 'stocks' => 'stocks', 'transfer' => 'transfer',
];

// title, the link on the right, where it goes
$sectionDef = [
    'tasks'         => ['ต้องทำ', 'งานทั้งหมด', '/tasks'],
    'calendar'      => ['กำหนดการ', 'เปิดแพลนเนอร์', '/planner'],
    'habits'        => ['นิสัยวันนี้', 'ดูทั้งสัปดาห์', '/habits'],
    'finance'       => ['เงินเดือน' . $thaiMonths[$month], 'ดูรายละเอียด', '/finance'],
    'subscriptions' => ['ตัดเงินใน 7 วัน', 'รายจ่ายประจำ', '/subscriptions'],
    'workout'       => ['ร่างกายและโฟกัส', 'ออกกำลังกาย', '/exercise'],
    'projects'      => ['โปรเจคล่าสุด', 'ดูบอร์ด', '/projects'],
    'notes'         => ['โน้ตล่าสุด', 'เปิดโน้ต', '/notes'],
    'stocks'        => ['หุ้น', 'เปิดหุ้น', '/stocks'],
    'transfer'      => ['ส่งไฟล์ล่าสุด', 'ส่งไฟล์', '/transfer'],
];

// The sections a person kept switched on, in their own order within each area.
$position = [];
$kept = [];
foreach ($layout as $widget) {
    $position[$widget['widget_key']] = (int)$widget['position'];
    $kept[$widget['widget_key']] = !empty($widget['is_visible']);
}
$areas = [];
foreach (DashboardLayout::AREAS as $area => $keys) {
    $list = array_values(array_filter($keys, fn(string $k): bool => ($kept[$k] ?? true) && showMenu($sectionMenu[$k])));
    usort($list, fn(string $a, string $b): int => ($position[$a] ?? 99) <=> ($position[$b] ?? 99));
    $areas[$area] = $list;
}
$anySection = array_merge(...array_values($areas)) !== [];

/** Three grey rule rows where the section's rows will be. */
$skeleton = '<div aria-busy="true"><div class="skel-row"><span class="skel box"></span><span class="skel skel-w-60"></span></div>'
    . '<div class="skel-row"><span class="skel box"></span><span class="skel skel-w-45"></span></div>'
    . '<div class="skel-row"><span class="skel box"></span><span class="skel skel-w-52"></span></div></div>';

$renderSection = function (string $key) use ($sectionDef, $skeleton): void {
    [$title, $linkText, $path] = $sectionDef[$key];
    ?>
    <section class="sec" data-section="<?= h($key) ?>" aria-labelledby="sec-<?= h($key) ?>">
        <div class="sec-head">
            <h2 id="sec-<?= h($key) ?>"><?= h($title) ?><span class="count" data-count></span></h2>
            <a href="<?= APP_URL . h($path) ?>"><?= h($linkText) ?></a>
        </div>
        <div data-body><?= $skeleton ?></div>
        <?php if ($key === 'tasks'): ?>
        <label class="add-row">
            <span class="plus" aria-hidden="true">+</span>
            <span class="sr-only">เพิ่มงานวันนี้</span>
            <input type="text" id="todayAddTask" maxlength="200" autocomplete="off" placeholder="เพิ่มงานวันนี้ เช่น โทรหาช่างแอร์">
        </label>
        <?php endif; ?>
    </section>
    <?php
};
?>
<div class="today" id="today">
    <header class="page-head today-head">
        <div class="date-block" aria-hidden="true">
            <span class="d"><?= (int)$now->format('j') ?></span>
            <span class="m"><?= h($thaiMonthsShort[$month]) ?></span>
        </div>
        <div class="today-title">
            <h1>วัน<?= h($thaiDays[(int)$now->format('w')]) ?></h1>
            <div class="sub"><?= h($fullDate) ?> · สัปดาห์ที่ <?= (int)$now->format('W') ?></div>
            <div class="tally" id="todayTally" aria-live="polite"><span class="skel skel-wide"></span></div>
        </div>
        <div class="page-head-actions">
            <button type="button" class="btn btn-search" data-act="openCommandPalette" aria-label="ค้นหาหรือสั่งงาน">
                <span class="btn-search-label"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>ค้นหาหรือสั่งงาน</span>
                <span class="kbd" data-shortcut>Ctrl K</span>
            </button>
            <button type="button" class="btn btn-icon btn-search-icon" data-act="openCommandPalette" aria-label="ค้นหาหรือสั่งงาน"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg></button>
            <button type="button" class="btn btn-primary" data-act="openQuickAdd"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>จดด่วน</button>
        </div>
    </header>

    <?php if (!$anySection): ?>
    <div class="empty-state">
        <p class="empty-state-title">ซ่อนทุกส่วนของหน้าวันนี้ไว้</p>
        <p class="empty-state-text">เปิดส่วนที่ต้องการกลับมาได้ในหน้าตั้งค่า</p>
        <a class="btn" href="<?= APP_URL ?>/settings">เปิดการตั้งค่า</a>
    </div>
    <?php else: ?>
    <div class="cols">
        <div class="col-main">
            <?php foreach ($areas['main'] as $key) $renderSection($key); ?>
        </div>
        <aside class="col-side">
            <?php foreach ($areas['side'] as $key) $renderSection($key); ?>
        </aside>
    </div>

    <?php if ($areas['more'] !== []): ?>
    <div class="more">
        <?php foreach ($areas['more'] as $key) $renderSection($key); ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
