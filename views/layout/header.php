<?php
// Determine active page for nav highlighting
$currentPath = Request::path();
$user = Auth::user();
$theme = Auth::theme();

$isReadOnly = Auth::isReadOnly();
// A visitor on a public project link, signed in to no account.
$isGuest = empty($_SESSION['user_id']) && !empty($_SESSION['active_project_share_token']);
$sharedMenus = $isReadOnly ? Auth::getSharedMenus() : [];
$shareToken = $isReadOnly ? (string)($_SESSION['app_share_token'] ?? '') : '';
$shareQuery = $shareToken !== '' ? '?share=' . rawurlencode($shareToken) : '';
$shareHomeUrl = $shareToken !== '' ? APP_URL . '/shared/' . rawurlencode($shareToken) : APP_URL . '/';

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


function isActive(string $path): string
{
    global $currentPath;
    if ($path === '/' && $currentPath === '/')
        return 'active';
    if ($path !== '/' && strpos((string)$currentPath, $path) === 0)
        return 'active';
    return '';
}

// "auto" is a preference, not a palette. The server picks light as the safe
// default and the script below swaps in the real answer before the first paint.
$themeAttr = $theme === 'auto' ? 'light' : $theme;
?>
<!DOCTYPE html>
<html lang="th" data-theme="<?= h($themeAttr) ?>" data-theme-pref="<?= h($theme) ?>">

<head>
    <meta charset="UTF-8">
    <script nonce="<?= h(Security::nonce()) ?>">
    // Runs while the head is parsed, so the page never flashes the wrong theme.
    (function () {
        var root = document.documentElement;
        if (root.dataset.themePref !== 'auto') return;
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var apply = function () { root.setAttribute('data-theme', mq.matches ? 'dark' : 'light'); };
        apply();
        mq.addEventListener('change', apply);
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <meta name="csrf-token" content="<?= h(Csrf::token()) ?>">
    <meta name="theme-color" content="#111827">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?= h(APP_URL . '/manifest.json') ?>">
    <!-- Without it browsers ask for /favicon.ico, which does not exist. -->
    <link rel="icon" type="image/png" href="<?= h(APP_URL . '/assets/icons/icon-192.png') ?>">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' — ' : '' ?><?= h(APP_NAME) ?></title>
    <?php
    // Fonts are self-hosted and declared in fonts.css, so there is no remote
    // stylesheet to block the first paint. The two faces below cover almost
    // all body text, so they are fetched in parallel with the CSS rather than
    // after it.
    foreach (['plexthai-thai-400', 'inter-latin-400'] as $criticalFont): ?>
    <link rel="preload" as="font" type="font/woff2" crossorigin
        href="<?= APP_URL ?>/assets/fonts/<?= $criticalFont ?>.woff2">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/components.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/components.css') ?>">
    <?php if (isset($pageStyle)): ?>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/modules/<?= h($pageStyle) ?>.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/modules/' . $pageStyle . '.css') ?>">
    <?php endif; ?>
    <?php if (isset($pageStyleExtra)): ?>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/modules/<?= h($pageStyleExtra) ?>.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/modules/' . $pageStyleExtra . '.css') ?>">
    <?php endif; ?>
    <?php if ($isReadOnly || $isGuest): ?>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/share-mode.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/share-mode.css') ?>">
    <?php endif; ?>
    <!-- escHtml()/cssColor(): loaded before any page or inline script needs them. -->
    <script src="<?= APP_URL ?>/assets/js/html.js?v=<?= @filemtime(PUBLIC_ROOT . '/assets/js/html.js') ?>"></script>
</head>

<body<?= $isReadOnly ? ' class="is-readonly"' : '' ?>>
<?php if ($isReadOnly || $isGuest): ?>
    <?php if ($isReadOnly): ?>
        <!-- Shared Mode Top Bar -->
        <header class="share-topbar">
            <div class="share-topbar-brand">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span class="badge badge-gray share-topbar-badge">โหมดแชร์</span>
            </div>
            <div class="share-topbar-menu">
                <?php
                $menuLabels = [
                    'tasks' => 'งาน', 'notes' => 'โน้ต', 'planner' => 'แพลนเนอร์', 'focus' => 'โฟกัส', 'habits' => 'นิสัยประจำวัน',
                    'exercise' => 'ออกกำลังกาย', 'food-notes' => 'อาหาร',
                    'finance' => 'การเงิน', 'subscriptions' => 'แจ้งเตือน', 'stocks' => 'หุ้น'
                ];
                foreach ($sharedMenus as $m):
                ?>
                    <a href="<?= h(APP_URL . '/' . $m . $shareQuery) ?>" class="share-topbar-link <?= isActive('/' . $m) ? 'is-active' : '' ?>"><?= h($menuLabels[$m] ?? $m) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="share-topbar-actions">
                <?php if (!empty($_SESSION['user_id'])): ?>
                    <a href="<?= APP_URL ?>/exit-share" class="btn btn-ghost btn-sm share-topbar-btn">
                        กลับหน้าหลักของคุณ
                    </a>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/login" class="btn btn-primary btn-sm share-topbar-btn">
                        เข้าสู่ระบบ
                    </a>
                <?php endif; ?>
            </div>
        </header>
    <?php else: ?>
        <!-- Guest Public Share Mode Top Bar -->
        <header class="share-topbar">
            <div class="share-topbar-brand">
                <svg class="share-topbar-icon--guest" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <span class="share-topbar-title">บอร์ดโครงการสาธารณะ</span>
                <span class="badge share-topbar-badge share-topbar-badge--guest">ผู้เยี่ยมชม</span>
            </div>
            <div class="flex-1"></div>
            <div class="share-topbar-actions">
                <a href="<?= APP_URL ?>/login" class="btn btn-ghost btn-sm share-topbar-btn">
                    เข้าสู่ระบบ
                </a>
                <a href="<?= APP_URL ?>/register" class="btn btn-primary btn-sm share-topbar-btn share-topbar-signup">
                    สมัครสมาชิก
                </a>
            </div>
        </header>
    <?php endif; ?>
    <main class="app-main share-main">
        <div class="app-content">
            <div class="toast-container" id="toastContainer" role="status" aria-live="polite" aria-atomic="true"></div>
<?php else: ?>


    <!-- Mobile Top Bar -->
    <div class="app-topbar" id="appTopbar">
        <span class="app-topbar-title"><?= h(APP_NAME) ?></span>
        <div class="app-topbar-actions">
            <a href="<?= APP_URL ?>/" class="topbar-home-btn" aria-label="แดชบอร์ด">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </a>
            <button class="topbar-menu-btn" id="menuToggle" aria-label="เมนู" aria-controls="appSidebar" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
    </div>

    <!-- Sidebar -->
    <aside class="app-sidebar" id="appSidebar">
        <nav class="sidebar-nav">
            <?php if (!$isReadOnly): ?>
            <div class="sidebar-section">
                <a href="<?= APP_URL ?>/" class="nav-item <?= isActive('/') ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    <span>แดชบอร์ด</span>
                </a>
                <a href="<?= APP_URL ?>/review" class="nav-item <?= isActive('/review') ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx="1"/><rect x="12" y="8" width="3" height="10" rx="1"/><rect x="17" y="4" width="3" height="14" rx="1"/></svg>
                    <span>สรุปผล</span>
                </a>
            </div>
            <?php endif; ?>

            <?php
            $sidebarSettings = $isReadOnly ? [] : User::getSettings(Auth::userId());
            $sidebarOrder = AppMenus::ordered(json_decode($sidebarSettings['menu_order'] ?? 'null', true));
            $sidebarMenus = [
                'projects' => ['group' => 'จัดการ', 'label' => 'โปรเจค', 'path' => '/projects',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M11 3v18"/><path d="M16 3v18"/><path d="M3 9h18"/><path d="M3 15h18"/></svg>'],
                'tasks' => ['group' => 'จัดการ', 'label' => 'งาน', 'path' => '/tasks',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>'],
                'notes' => ['group' => 'จัดการ', 'label' => 'โน้ต', 'path' => '/notes',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>'],
                'planner' => ['group' => 'จัดการ', 'label' => 'แพลนเนอร์', 'path' => '/planner',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>'],
                'focus' => ['group' => 'จัดการ', 'label' => 'โฟกัส (Focus)', 'path' => '/focus',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'],
                'habits' => ['group' => 'จัดการ', 'label' => 'นิสัยประจำวัน', 'path' => '/habits',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v18"/><path d="M5 7h14"/><path d="M5 17h14"/><circle cx="12" cy="7" r="2"/><circle cx="12" cy="17" r="2"/></svg>'],
                'exercise' => ['group' => 'ติดตาม', 'label' => 'ออกกำลังกาย', 'path' => '/exercise',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.4 14.4 9.6 9.6"/><path d="M18.657 21.485a2 2 0 1 1-2.829-2.828l-1.767 1.768a2 2 0 1 1-2.829-2.829l6.364-6.364a2 2 0 1 1 2.829 2.829l-1.768 1.767a2 2 0 1 1 2.828 2.829z"/><path d="m21.5 21.5-1.4-1.4"/><path d="M3.9 3.9 2.5 2.5"/><path d="M6.404 2.768a2 2 0 1 1 2.829 2.829l1.768-1.767a2 2 0 1 1 2.828 2.829L7.465 13.023a2 2 0 1 1-2.829-2.829l1.768-1.768a2 2 0 1 1-2.829-2.828z"/></svg>'],
                'food-notes' => ['group' => 'ติดตาม', 'label' => 'อาหาร-เครื่องดื่ม', 'path' => '/food-notes',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2v2"/><path d="M14 2v2"/><path d="M16 8a1 1 0 0 1 1 1v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1h14a4 4 0 1 1 0 8h-1"/><path d="M6 2v2"/></svg>'],
                'finance' => ['group' => 'ติดตาม', 'label' => 'การเงิน', 'path' => '/finance',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
                'subscriptions' => ['group' => 'ติดตาม', 'label' => 'การแจ้งเตือน', 'path' => '/subscriptions',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>'],
                'stocks' => ['group' => 'ติดตาม', 'label' => 'ระบบหุ้น (Stocks)', 'path' => '/stocks',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>'],
                'ai' => ['group' => 'เครื่องมือ', 'label' => 'ผู้ช่วยอัจฉริยะ (AI)', 'path' => '/ai',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4"/><path d="M12 18v4"/><path d="M4.93 4.93l2.83 2.83"/><path d="M16.24 16.24l2.83 2.83"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="M4.93 19.07l2.83-2.83"/><path d="M16.24 7.76l2.83-2.83"/></svg>'],
                'file-tools' => ['group' => 'เครื่องมือ', 'label' => 'เครื่องมือจัดการไฟล์', 'path' => '/file-tools',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg>'],
                'transfer' => ['group' => 'เครื่องมือ', 'label' => 'ย้ายไฟล์', 'path' => '/transfer',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9l20-7z"/></svg>'],
                'files' => ['group' => 'อื่น ๆ', 'label' => 'ไฟล์', 'path' => '/files',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>'],
                'quick-notes' => ['group' => 'อื่น ๆ', 'label' => 'จดด่วน', 'path' => '/quick-notes',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>'],
                'bookmarks' => ['group' => 'อื่น ๆ', 'label' => 'ลิงก์สำคัญ', 'path' => '/bookmarks',
                    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m19 21-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>'],
            ];
            $currentSidebarGroup = null;
            foreach ($sidebarOrder as $menuKey):
                if (!isset($sidebarMenus[$menuKey]) || !showMenu($menuKey)) continue;
                $menu = $sidebarMenus[$menuKey];
                if ($currentSidebarGroup !== $menu['group']):
                    if ($currentSidebarGroup !== null) echo '</div>';
                    $currentSidebarGroup = $menu['group'];
                    echo '<div class="sidebar-section"><div class="sidebar-section-label">' . h($currentSidebarGroup) . '</div>';
                endif;
            ?>
                <a href="<?= APP_URL . h($menu['path']) ?>" class="nav-item <?= isActive($menu['path']) ?>" data-menu-key="<?= h($menuKey) ?>">
                    <?= $menu['icon'] ?? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 9h8M8 13h8M8 17h5"/></svg>' ?>
                    <span><?= h($menu['label']) ?></span>
                </a>
            <?php endforeach; if ($currentSidebarGroup !== null) echo '</div>'; ?>

            <?php if (!$isReadOnly): ?>
            <div class="sidebar-section">
                <div class="sidebar-section-label">อื่น ๆ</div>
                <a href="<?= APP_URL ?>/settings" class="nav-item <?= isActive('/settings') ?>" data-menu-key="settings">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span>ตั้งค่า</span>
                </a>
            </div>
            <?php endif; ?>

        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <strong><?= h($user['display_name'] ?? $user['username'] ?? '') ?></strong>
                <span><?= h($user['email'] ?? '') ?></span>
            </div>
            <?php if (!$isReadOnly): ?>
            <a href="<?= APP_URL ?>/logout" class="btn btn-ghost btn-sm btn-block">
                ออกจากระบบ
            </a>
            <?php endif; ?>
        </div>
    </aside>

<!-- Main Content -->
    <main class="app-main" id="appMain">
        <div class="app-content">

            <!-- Toast container -->

            <div class="toast-container" id="toastContainer" role="status" aria-live="polite" aria-atomic="true"></div>
            <div class="global-search" id="globalSearch" role="search">
                <svg class="global-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input id="globalSearchInput" type="search" autocomplete="off" placeholder="ค้นหางาน โน้ต โปรเจค ไฟล์..." aria-label="ค้นหาทั้งระบบ">
                <kbd>/</kbd>
                <button type="button" class="global-search-cmdk" aria-label="เปิดแถบคำสั่ง"
                        data-act="openCommandPalette">
                    <kbd>Ctrl</kbd><kbd>K</kbd>
                </button>
                <div class="global-search-results" id="globalSearchResults" hidden></div>
            </div>

<?php endif; ?>
