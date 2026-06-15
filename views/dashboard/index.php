<?php
usort($layout, fn($a, $b) => $a['position'] <=> $b['position']);
?>

<div class="dashboard-header-wrap">
    <div class="dashboard-title-area">
        <h1 class="dashboard-title-gradient">
            <span>แดชบอร์ด</span>
            <span class="active-status-dot" title="ระบบทำงานปกติ"></span>
        </h1>
        <p class="text-xs text-muted" style="margin-top: 4px;">แผงควบคุมหลัก • อัปเดตล่าสุด: <span id="headerLastUpdate">—</span></p>
    </div>
    <div class="flex items-center gap-3">
        <!-- Live Clock Pill -->
        <div class="header-pill">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-muted); opacity: 0.8;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span id="headerLiveClock">--:--:--</span>
        </div>
        <!-- Refresh Button -->
        <button class="btn btn-ghost btn-sm" id="btnRefreshDashboard" title="รีเฟรชข้อมูล" style="padding: 0; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: var(--color-surface-2); border: 1px solid var(--color-border); box-shadow: var(--shadow-sm); cursor: pointer; transition: all var(--transition);">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
        </button>
    </div>
</div>

<!-- Quick stat strip -->
<div class="dash-strip">
    <?php if (showMenu('tasks')): ?>
    <div class="dash-strip-card" id="ds-tasks">
        <div class="dash-strip-icon-wrap">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        </div>
        <div class="dash-strip-content">
            <div class="dash-strip-val" id="ds-tasks-val">—</div>
            <div class="dash-strip-lbl" id="ds-tasks-lbl">งานใกล้ครบกำหนด</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (showMenu('finance')): ?>
    <div class="dash-strip-card" id="ds-balance">
        <div class="dash-strip-icon-wrap">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        </div>
        <div class="dash-strip-content">
            <div class="dash-strip-val" id="ds-balance-val">—</div>
            <div class="dash-strip-lbl" id="ds-balance-lbl">คงเหลือเดือนนี้</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (showMenu('exercise')): ?>
    <div class="dash-strip-card" id="ds-workout">
        <div class="dash-strip-icon-wrap">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18.657 21.485a2 2 0 1 1-2.829-2.828l-1.767 1.768a2 2 0 1 1-2.829-2.829l6.364-6.364a2 2 0 1 1 2.829 2.829l-1.768 1.767a2 2 0 1 1 2.828 2.829z"/></svg>
        </div>
        <div class="dash-strip-content">
            <div class="dash-strip-val" id="ds-workout-val">—</div>
            <div class="dash-strip-lbl" id="ds-workout-lbl">ออกกำลังกายล่าสุด</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (showMenu('subscriptions')): ?>
    <div class="dash-strip-card" id="ds-subs">
        <div class="dash-strip-icon-wrap">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        </div>
        <div class="dash-strip-content">
            <div class="dash-strip-val" id="ds-subs-val">—</div>
            <div class="dash-strip-lbl" id="ds-subs-lbl">ต่ออายุที่ใกล้ถึง</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Widget grid -->
<div class="dashboard-grid" id="dashboardGrid">

    <?php foreach ($layout as $widget):
        if (!$widget['is_visible']) continue;
        
        $widgetMenuMap = [
            'tasks' => 'tasks',
            'calendar' => 'planner',
            'finance' => 'finance',
            'workout' => 'exercise',
            'subscriptions' => 'subscriptions',
            'projects' => 'projects',
            'notes' => 'notes',
            'stocks' => 'stocks',
            'transfer' => 'transfer'
        ];
        $menuKey = $widgetMenuMap[$widget['widget_key']] ?? null;
        if ($menuKey && !showMenu($menuKey)) continue;
    ?>
    <div class="widget" data-widget="<?= h($widget['widget_key']) ?>">
        <?php switch ($widget['widget_key']):
            case 'tasks': ?>
                <div class="widget-header">
                    <span class="widget-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        งานใกล้ครบกำหนด
                    </span>
                    <div class="widget-header-right">
                        <span class="drag-handle">&#8942;</span>
                        <a href="<?= APP_URL ?>/tasks" class="widget-link">ดูทั้งหมด →</a>
                    </div>
                </div>
                <div class="widget-body" id="widget-tasks">
                    <div class="widget-loading"><span class="spinner"></span></div>
                </div>
            <?php break;
            case 'calendar': ?>
                <div class="widget-header">
                    <span class="widget-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                        กำหนดการวันนี้
                    </span>
                    <div class="widget-header-right">
                        <span class="drag-handle">&#8942;</span>
                        <a href="<?= APP_URL ?>/planner" class="widget-link">ดูปฏิทิน →</a>
                    </div>
                </div>
                <div class="widget-body" id="widget-calendar">
                    <div class="widget-loading"><span class="spinner"></span></div>
                </div>
            <?php break;
            case 'finance': ?>
                <div class="widget-header">
                    <span class="widget-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        การเงินเดือนนี้
                    </span>
                    <div class="widget-header-right">
                        <span class="drag-handle">&#8942;</span>
                        <a href="<?= APP_URL ?>/finance" class="widget-link">ดูรายละเอียด →</a>
                    </div>
                </div>
                <div class="widget-body" id="widget-finance">
                    <div class="widget-loading"><span class="spinner"></span></div>
                </div>
            <?php break;
            case 'workout': ?>
                <div class="widget-header">
                    <span class="widget-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18.657 21.485a2 2 0 1 1-2.829-2.828l-1.767 1.768a2 2 0 1 1-2.829-2.829l6.364-6.364a2 2 0 1 1 2.829 2.829l-1.768 1.767a2 2 0 1 1 2.828 2.829z"/></svg>
                        ออกกำลังกายล่าสุด
                    </span>
                    <div class="widget-header-right">
                        <span class="drag-handle">&#8942;</span>
                        <a href="<?= APP_URL ?>/exercise" class="widget-link">บันทึกใหม่ →</a>
                    </div>
                </div>
                <div class="widget-body" id="widget-workout">
                    <div class="widget-loading"><span class="spinner"></span></div>
                </div>
            <?php break;
            case 'subscriptions': ?>
                <div class="widget-header">
                    <span class="widget-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                        การแจ้งเตือนที่ใกล้ถึง
                    </span>
                    <div class="widget-header-right">
                        <span class="drag-handle">&#8942;</span>
                        <a href="<?= APP_URL ?>/subscriptions" class="widget-link">ดูทั้งหมด →</a>
                    </div>
                </div>
                <div class="widget-body" id="widget-subscriptions">
                    <div class="widget-loading"><span class="spinner"></span></div>
                </div>
            <?php break;
            case 'projects': ?>
                <div class="widget-header">
                    <span class="widget-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M11 3v18"/><path d="M16 3v18"/><path d="M3 9h18"/><path d="M3 15h18"/></svg>
                        โปรเจคล่าสุด
                    </span>
                    <div class="widget-header-right">
                        <span class="drag-handle">&#8942;</span>
                        <a href="<?= APP_URL ?>/projects" class="widget-link">ดูบอร์ดโครงการ →</a>
                    </div>
                </div>
                <div class="widget-body" id="widget-projects">
                    <div class="widget-loading"><span class="spinner"></span></div>
                </div>
            <?php break;
            case 'notes': ?>
                <div class="widget-header">
                    <span class="widget-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                        โน้ตล่าสุด
                    </span>
                    <div class="widget-header-right">
                        <span class="drag-handle">&#8942;</span>
                        <a href="<?= APP_URL ?>/notes" class="widget-link">เปิดดูโน้ต →</a>
                    </div>
                </div>
                <div class="widget-body" id="widget-notes">
                    <div class="widget-loading"><span class="spinner"></span></div>
                </div>
            <?php break;
            case 'stocks': ?>
                <div class="widget-header">
                    <span class="widget-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                        หุ้นและพอร์ตโฟลิโอ
                    </span>
                    <div class="widget-header-right">
                        <span class="drag-handle">&#8942;</span>
                        <a href="<?= APP_URL ?>/stocks" class="widget-link">ดูระบบหุ้น →</a>
                    </div>
                </div>
                <div class="widget-body" id="widget-stocks">
                    <div class="widget-loading"><span class="spinner"></span></div>
                </div>
            <?php break;
            case 'transfer': ?>
                <div class="widget-header">
                    <span class="widget-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9l20-7z"/></svg>
                        ย้ายไฟล์ล่าสุด
                    </span>
                    <div class="widget-header-right">
                        <span class="drag-handle">&#8942;</span>
                        <a href="<?= APP_URL ?>/transfer" class="widget-link">ย้ายไฟล์ →</a>
                    </div>
                </div>
                <div class="widget-body" id="widget-transfer">
                    <div class="widget-loading"><span class="spinner"></span></div>
                </div>
            <?php break;
        endswitch; ?>
    </div>
    <?php endforeach; ?>

</div>

<script>
window.dashboardLayout = <?= json_encode($layout, JSON_UNESCAPED_UNICODE) ?>;
</script>
