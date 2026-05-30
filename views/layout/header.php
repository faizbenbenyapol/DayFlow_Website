<?php
// Determine active page for nav highlighting
$currentPath = Request::path();
$user = Auth::user();
$theme = Auth::theme();

$isReadOnly = Auth::isReadOnly();
$sharedMenus = $isReadOnly ? Auth::getSharedMenus() : [];

function showMenu(string $menu): bool
{
    global $isReadOnly, $sharedMenus;
    if ($isReadOnly) {
        return in_array($menu, $sharedMenus);
    }
    
    static $cachedHidden = null;
    if ($cachedHidden === null) {
        require_once ROOT . '/models/User.php';
        $userId = Auth::userId();
        $settings = $userId ? User::getSettings($userId) : [];
        $cachedHidden = !empty($settings['hidden_menus']) ? json_decode($settings['hidden_menus'], true) : [];
        if (!is_array($cachedHidden)) {
            $cachedHidden = [];
        }
    }
    
    return !in_array($menu, $cachedHidden);
}

$showManage = showMenu('tasks') || showMenu('notes') || showMenu('planner') || showMenu('projects');
$showTrack = showMenu('exercise') || showMenu('food-notes') || showMenu('finance') || showMenu('subscriptions') || showMenu('stocks');
$showTools = showMenu('ai') || showMenu('file-tools');
$showOthers = showMenu('files') || !$isReadOnly;

function isActive(string $path): string
{
    global $currentPath;
    if ($path === '/' && $currentPath === '/')
        return 'active';
    if ($path !== '/' && strpos((string)$currentPath, $path) === 0)
        return 'active';
    return '';
}
?>
<!DOCTYPE html>
<html lang="th" data-theme="<?= h($theme) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(Csrf::token()) ?>">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' — ' : '' ?><?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/components.css">
    <?php if (isset($pageStyle)): ?>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/modules/<?= h($pageStyle) ?>.css?v=<?= @filemtime(ROOT . '/assets/css/modules/' . $pageStyle . '.css') ?>">
    <?php endif; ?>
    <?php if (isset($pageStyleExtra)): ?>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/modules/<?= h($pageStyleExtra) ?>.css?v=<?= @filemtime(ROOT . '/assets/css/modules/' . $pageStyleExtra . '.css') ?>">
    <?php endif; ?>
</head>

<body>
<?php 
$isGuest = empty($_SESSION['user_id']) && !empty($_SESSION['active_project_share_token']);
if ($isReadOnly || $isGuest): 
?>
    <?php if ($isReadOnly): ?>
        <!-- Shared Mode Top Bar -->
        <header style="background:var(--color-surface);border-bottom:1px solid var(--color-border);padding:0 24px;height:60px;display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:100;gap:16px;">
            <div style="font-weight:600;font-size:1.1rem;color:var(--color-primary);display:flex;align-items:center;gap:8px;flex-shrink:0;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span class="badge badge-gray" style="font-size:0.7rem;font-weight:500;margin-left:4px;padding:2px 6px;">โหมดแชร์</span>
            </div>
            <div style="display:flex; gap:16px; overflow-x:auto; flex:1; justify-content:center;">
                <?php 
                $menuLabels = [
                    'tasks' => 'งาน', 'notes' => 'โน้ต', 'planner' => 'แพลนเนอร์',
                    'exercise' => 'ออกกำลังกาย', 'food-notes' => 'อาหาร',
                    'finance' => 'การเงิน', 'subscriptions' => 'แจ้งเตือน', 'stocks' => 'หุ้น'
                ];
                foreach ($sharedMenus as $m): 
                ?>
                    <a href="<?= APP_URL ?>/<?= $m ?>" style="white-space:nowrap; text-decoration:none;color:<?= isActive('/'.$m) ? 'var(--color-primary)' : 'var(--color-text)' ?>;font-weight:<?= isActive('/'.$m) ? '600' : '400' ?>;border-bottom: <?= isActive('/'.$m) ? '2px solid var(--color-primary)' : 'none' ?>;padding:18px 8px;"><?= $menuLabels[$m] ?? $m ?></a>
                <?php endforeach; ?>
            </div>
            <div style="flex-shrink:0; display:flex; align-items:center; gap:12px;">
                <?php if (!empty($_SESSION['user_id'])): ?>
                    <a href="<?= APP_URL ?>/exit-share" class="btn btn-ghost btn-sm" style="font-size:0.8rem; border-color:var(--color-border-2);">
                        กลับหน้าหลักของคุณ
                    </a>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/login" class="btn btn-primary btn-sm" style="font-size:0.8rem;">
                        เข้าสู่ระบบ
                    </a>
                <?php endif; ?>
            </div>
        </header>
    <?php else: ?>
        <!-- Guest Public Share Mode Top Bar -->
        <header style="background:var(--color-surface);border-bottom:1px solid var(--color-border);padding:0 24px;height:60px;display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:100;gap:16px;">
            <div style="font-weight:600;font-size:1.1rem;color:var(--color-primary);display:flex;align-items:center;gap:8px;flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:#06b6d4;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <span style="color:var(--color-text); font-size:1rem; font-weight:600;">บอร์ดโครงการสาธารณะ</span>
                <span class="badge" style="background:rgba(6, 182, 212, 0.12); color:#06b6d4; font-size:0.7rem;font-weight:600;margin-left:4px;padding:2px 6px;">ผู้เยี่ยมชม</span>
            </div>
            <div style="flex:1;"></div>
            <div style="flex-shrink:0; display:flex; align-items:center; gap:12px;">
                <a href="<?= APP_URL ?>/login" class="btn btn-ghost btn-sm" style="font-size:0.8rem; border-color:var(--color-border-2);">
                    เข้าสู่ระบบ
                </a>
                <a href="<?= APP_URL ?>/register" class="btn btn-primary btn-sm" style="font-size:0.8rem; background:#06b6d4; border:none; color:#ffffff;">
                    สมัครสมาชิก
                </a>
            </div>
        </header>
    <?php endif; ?>
    <style>
        /* --- Premium Read-Only & Layout Tuning for Share Mode --- */
        .app-main {
            flex: 1 !important;
            width: 100% !important;
            min-height: 100vh !important;
            display: block !important;
        }
        
        <?php if ($isReadOnly): ?>
        /* Hide all mutating actions in Read-Only Mode */
        .mode-readonly-hide,
        .task-actions,
        .btn-link,
        .action-btn,
        .delete-btn,
        .edit-btn,
        .btn-delete,
        .add-task-form,
        .quick-add-bar,
        .todo-actions,
        .modal-footer button[onclick*="save"],
        .modal-footer button[onclick*="Save"],
        .modal-footer button[onclick*="submit"],
        .modal-footer button[onclick*="Submit"] {
            display: none !important;
        }

        /* Hide add/record buttons within the main content of Share Mode */
        .app-main button:not([class*="tabs"]):not([class*="toggle"]):not([class*="filter"]):not([class*="close"]),
        .app-main .btn:not([class*="tabs"]):not([class*="toggle"]):not([class*="filter"]):not([class*="close"]):not([class*="btn-secondary"]) {
            display: none !important;
        }

        /* Make interactive inputs and checkboxes look read-only */
        .task-checkbox,
        .todo-checkbox {
            pointer-events: none !important;
            opacity: 0.7 !important;
            cursor: not-allowed !important;
        }

        /* Disable click-to-edit interactions */
        .task-title,
        .todo-text,
        .calendar-event {
            pointer-events: none !important;
            cursor: default !important;
        }
        <?php endif; ?>
    </style>
    <main class="app-main" style="padding-top:80px; padding-bottom:40px;">
        <div class="app-content">
            <div class="toast-container" id="toastContainer"></div>
<?php else: ?>


    <!-- Mobile Top Bar -->
    <div class="app-topbar" id="appTopbar">
        <span class="app-topbar-title"><?= h(APP_NAME) ?></span>
        <button class="topbar-menu-btn" id="menuToggle" aria-label="เมนู">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>

    <!-- Sidebar -->
    <aside class="app-sidebar" id="appSidebar">
        <nav class="sidebar-nav">
            <?php if (!$isReadOnly): ?>
            <div class="sidebar-section">
                <a href="<?= APP_URL ?>/" class="nav-item <?= isActive('/') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    <span>แดชบอร์ด</span>
                </a>
            </div>
            <?php endif; ?>

            <?php if ($showManage): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-label">จัดการ</div>
                <?php if (showMenu('projects')): ?>
                <a href="<?= APP_URL ?>/projects" class="nav-item <?= isActive('/projects') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M11 3v18"/><path d="M16 3v18"/><path d="M3 9h18"/><path d="M3 15h18"/></svg>
                    <span>โปรเจค</span>
                </a>
                <?php endif; ?>
                <?php if (showMenu('tasks')): ?>
                <a href="<?= APP_URL ?>/tasks" class="nav-item <?= isActive('/tasks') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    <span>งาน</span>
                </a>
                <?php endif; ?>
                <?php if (showMenu('notes')): ?>
                <a href="<?= APP_URL ?>/notes" class="nav-item <?= isActive('/notes') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                    <span>โน้ต</span>
                </a>
                <?php endif; ?>
                <?php if (showMenu('planner')): ?>
                <a href="<?= APP_URL ?>/planner" class="nav-item <?= isActive('/planner') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                    <span>แพลนเนอร์</span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($showTrack): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-label">ติดตาม</div>
                <?php if (showMenu('exercise')): ?>
                <a href="<?= APP_URL ?>/exercise" class="nav-item <?= isActive('/exercise') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.4 14.4 9.6 9.6"/><path d="M18.657 21.485a2 2 0 1 1-2.829-2.828l-1.767 1.768a2 2 0 1 1-2.829-2.829l6.364-6.364a2 2 0 1 1 2.829 2.829l-1.768 1.767a2 2 0 1 1 2.828 2.829z"/><path d="m21.5 21.5-1.4-1.4"/><path d="M3.9 3.9 2.5 2.5"/><path d="M6.404 2.768a2 2 0 1 1 2.829 2.829l1.768-1.767a2 2 0 1 1 2.828 2.829L7.465 13.023a2 2 0 1 1-2.829-2.829l1.768-1.768a2 2 0 1 1-2.829-2.828z"/></svg>
                    <span>ออกกำลังกาย</span>
                </a>
                <?php endif; ?>
                <?php if (showMenu('food-notes')): ?>
                <a href="<?= APP_URL ?>/food-notes" class="nav-item <?= isActive('/food-notes') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2v2"/><path d="M14 2v2"/><path d="M16 8a1 1 0 0 1 1 1v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1h14a4 4 0 1 1 0 8h-1"/><path d="M6 2v2"/></svg>
                    <span>อาหาร-เครื่องดื่ม</span>
                </a>
                <?php endif; ?>
                <?php if (showMenu('finance')): ?>
                <a href="<?= APP_URL ?>/finance" class="nav-item <?= isActive('/finance') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    <span>การเงิน</span>
                </a>
                <?php endif; ?>
                <?php if (showMenu('subscriptions')): ?>
                <a href="<?= APP_URL ?>/subscriptions" class="nav-item <?= isActive('/subscriptions') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                    <span>การแจ้งเตือน</span>
                </a>
                <?php endif; ?>
                <?php if (showMenu('stocks')): ?>
                <a href="<?= APP_URL ?>/stocks" class="nav-item <?= isActive('/stocks') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    <span>ระบบหุ้น (Stocks)</span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($showTools): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-label">เครื่องมือ</div>
                <?php if (showMenu('ai')): ?>
                <a href="<?= APP_URL ?>/ai" class="nav-item <?= isActive('/ai') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4"/><path d="M12 18v4"/><path d="M4.93 4.93l2.83 2.83"/><path d="M16.24 16.24l2.83 2.83"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="M4.93 19.07l2.83-2.83"/><path d="M16.24 7.76l2.83-2.83"/></svg>
                    <span>ผู้ช่วยอัจฉริยะ (AI)</span>
                </a>
                <?php endif; ?>
                <?php if (showMenu('file-tools')): ?>
                <a href="<?= APP_URL ?>/file-tools" class="nav-item <?= isActive('/file-tools') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg>
                    <span>เครื่องมือจัดการไฟล์</span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($showOthers): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-label">อื่น ๆ</div>
                <?php if (showMenu('files')): ?>
                <a href="<?= APP_URL ?>/files" class="nav-item <?= isActive('/files') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>
                    <span>ไฟล์</span>
                </a>
                <?php endif; ?>
                <?php if (!$isReadOnly): ?>
                <a href="<?= APP_URL ?>/settings" class="nav-item <?= isActive('/settings') ?>" style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span>ตั้งค่า</span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <strong><?= h($user['display_name'] ?? $user['username'] ?? '') ?></strong>
                <span><?= h($user['email'] ?? '') ?></span>
            </div>
            <?php if (!$isReadOnly): ?>
            <a href="<?= APP_URL ?>/logout" class="btn btn-ghost btn-sm btn-block" style="justify-content:center">
                ออกจากระบบ
            </a>
            <?php endif; ?>
        </div>
    </aside>

<!-- Main Content -->
    <main class="app-main" id="appMain">
        <div class="app-content">

            <!-- Toast container -->
            <div class="toast-container" id="toastContainer"></div>

<?php endif; ?>

