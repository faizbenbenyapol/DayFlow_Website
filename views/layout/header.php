<?php
// Determine active page for nav highlighting
$user = Auth::user();
$theme = Auth::theme();

$isReadOnly = Auth::isReadOnly();
// A visitor on a public project link, signed in to no account.
$isGuest = empty($_SESSION['user_id']) && !empty($_SESSION['active_project_share_token']);
$sharedMenus = $isReadOnly ? Auth::getSharedMenus() : [];
$shareToken = $isReadOnly ? (string)($_SESSION['app_share_token'] ?? '') : '';
$shareQuery = $shareToken !== '' ? '?share=' . rawurlencode($shareToken) : '';
$shareHomeUrl = $shareToken !== '' ? APP_URL . '/shared/' . rawurlencode($shareToken) : APP_URL . '/';

/**
 * Whether a menu (or a dashboard widget that belongs to it) is shown.
 *
 * A visitor on a share link sees only what the link shares. This used to read
 * "global $isReadOnly, $sharedMenus", but those variables are set inside the
 * controller method that requires this file, so the function saw null and
 * treated every visitor as the owner.
 */
function showMenu(string $menu): bool
{
    if (Auth::isReadOnly()) {
        return in_array($menu, Auth::getSharedMenus(), true);
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


/**
 * 'active' when the page being shown belongs to this menu path.
 *
 * It reads the request itself: this file is required from inside a controller
 * method, so a variable set above is local to that method and a function that
 * said "global $currentPath" saw nothing, which left every menu entry looking
 * unselected.
 */
function isActive(string $path): string
{
    static $current = null;
    $current ??= Request::path();

    if ($path === '/') return $current === '/' ? 'active' : '';
    return $current === $path || str_starts_with($current, $path . '/') ? 'active' : '';
}

// "auto" is a preference, not a palette. The server picks light as the safe
// default and the script below swaps in the real answer before the first paint.
$themeAttr = $theme === 'auto' ? 'light' : $theme;

// The weekday decides the colour of the date block and of the marker beside
// the open menu entry (docs/REDESIGN.md §1.3). date('D') is English whatever
// the page language, and matches the keys in tokens.css.
$dayKey = strtolower(date('D'));
$dayColor = Auth::appearance()['day_color'] ? 'on' : 'off';

// The browser chrome follows the paper. "auto" lets the system decide which.
$chromeColors = ['light' => '#FBFAF7', 'dark' => '#15181D'];
?>
<!DOCTYPE html>
<html lang="th" data-theme="<?= h($themeAttr) ?>" data-theme-pref="<?= h($theme) ?>" data-day="<?= h($dayKey) ?>" data-daycolor="<?= h($dayColor) ?>">

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
    <?php if ($theme === 'auto'): ?>
    <meta name="theme-color" content="<?= $chromeColors['light'] ?>" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="<?= $chromeColors['dark'] ?>" media="(prefers-color-scheme: dark)">
    <?php else: ?>
    <meta name="theme-color" content="<?= $chromeColors[$themeAttr] ?? $chromeColors['light'] ?>">
    <?php endif; ?>
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
    foreach (['plexthai-thai-400', 'plexsans-latin-var'] as $criticalFont): ?>
    <link rel="preload" as="font" type="font/woff2" crossorigin
        href="<?= APP_URL ?>/assets/fonts/<?= $criticalFont ?>.woff2">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/tokens.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/base.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/base.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/components.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/shell.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/shell.css') ?>">
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
                foreach ($sharedMenus as $m):
                ?>
                    <a href="<?= h(APP_URL . '/' . $m . $shareQuery) ?>" class="share-topbar-link <?= isActive('/' . $m) ? 'is-active' : '' ?>"><?= h(AppMenus::label($m)) ?></a>
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
<?php
// What the rail, the tab bar and the "all menus" sheet show, worked out once.
$navSettings = User::getSettings(Auth::userId());
$navHidden = json_decode($navSettings['hidden_menus'] ?? 'null', true);
$navHidden = is_array($navHidden) ? $navHidden : [];
$navGroups = AppMenus::grouped(AppMenus::ordered(json_decode($navSettings['menu_order'] ?? 'null', true)), $navHidden);
[$tabA, $tabB] = AppMenus::mobileTabs(json_decode($navSettings['mobile_tabs'] ?? 'null', true), $navHidden);
$onTab = fn(string $key): bool => isActive(AppMenus::MENUS[$key]['path']) !== '';
$allTabIsCurrent = !$onTab('today') && !$onTab($tabA) && !$onTab($tabB);
$navName = (string)($user['display_name'] ?? $user['username'] ?? '');
require ROOT . '/views/partials/icons.php';
?>
    <a class="skip-link" href="#appMain">ข้ามไปยังเนื้อหา</a>

    <!-- Side rail: text on a wide screen, icons only on a tablet, hidden on a phone -->
    <aside class="rail" id="appRail" aria-label="เมนูหลัก">
        <a class="rail-brand" href="<?= APP_URL ?>/" aria-label="DayFlow หน้าวันนี้">
            <span class="brand-full">DayFlow</span><span class="brand-short" aria-hidden="true">D</span>
        </a>
        <button type="button" class="rail-search" data-act="openCommandPalette" title="ค้นหาหรือสั่งงาน (Ctrl K)">
            <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
            <span class="nav-text">ค้นหา</span>
            <kbd class="nav-text" data-shortcut>Ctrl K</kbd>
        </button>

        <nav class="rail-nav">
            <?php foreach ($navGroups as $group): ?>
            <div class="nav-group">
                <?php if ($group['key'] !== 'today'): ?><div class="nav-label"><?= h($group['label']) ?></div><?php endif; ?>
                <?php foreach ($group['items'] as $item): ?>
                <a class="nav-item" href="<?= h(APP_URL . $item['path']) ?>" title="<?= h($item['label']) ?>"
                   data-menu-key="<?= h($item['key']) ?>"<?= isActive($item['path']) !== '' ? ' aria-current="page"' : '' ?>>
                    <svg class="icon nav-icon" aria-hidden="true"><use href="#i-<?= h($item['key']) ?>"/></svg>
                    <span class="nav-text"><?= h($item['label']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </nav>

        <div class="rail-foot">
            <a class="nav-item" href="<?= APP_URL ?>/settings" title="ตั้งค่า" data-menu-key="settings"<?= isActive('/settings') !== '' ? ' aria-current="page"' : '' ?>>
                <svg class="icon nav-icon" aria-hidden="true"><use href="#i-settings"/></svg>
                <span class="nav-text">ตั้งค่า</span>
            </a>
            <div class="rail-user nav-text">
                <strong><?= h($navName) ?></strong>
                <span><?= h($user['email'] ?? '') ?></span>
            </div>
            <a class="nav-item rail-logout" href="<?= APP_URL ?>/logout" title="ออกจากระบบ">
                <svg class="icon nav-icon" aria-hidden="true"><use href="#i-logout"/></svg>
                <span class="nav-text">ออกจากระบบ</span>
            </a>
        </div>
    </aside>

    <!-- Phone: five fixed places, the middle one adds something -->
    <nav class="tabbar" aria-label="เมนูลัด">
        <?php foreach ([['today', 'วันนี้'], [$tabA, AppMenus::label($tabA)]] as [$key, $label]): ?>
        <a class="tab" href="<?= h(APP_URL . AppMenus::MENUS[$key]['path']) ?>"<?= $onTab($key) ? ' aria-current="page"' : '' ?>>
            <svg class="icon" aria-hidden="true"><use href="#i-<?= h($key) ?>"/></svg><span><?= h($label) ?></span>
        </a>
        <?php endforeach; ?>
        <button type="button" class="tab tab-add" data-act="openQuickAdd" aria-label="เพิ่มงาน จดด่วน หรือรายจ่าย">
            <span class="sq"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg></span>
        </button>
        <a class="tab" href="<?= h(APP_URL . AppMenus::MENUS[$tabB]['path']) ?>"<?= $onTab($tabB) ? ' aria-current="page"' : '' ?>>
            <svg class="icon" aria-hidden="true"><use href="#i-<?= h($tabB) ?>"/></svg><span><?= h(AppMenus::label($tabB)) ?></span>
        </a>
        <button type="button" class="tab<?= $allTabIsCurrent ? ' is-current' : '' ?>" data-act="openMenuSheet" aria-haspopup="dialog">
            <svg class="icon" aria-hidden="true"><use href="#i-all"/></svg><span>ทั้งหมด</span>
        </button>
    </nav>

    <!-- Phone: every menu, plus search, settings and sign-out -->
    <dialog class="sheet" id="menuSheet" aria-labelledby="menuSheetTitle">
        <div class="sheet-head">
            <h2 id="menuSheetTitle">เมนูทั้งหมด</h2>
            <button type="button" class="icon-btn" data-act="closeSheet" data-args='["menuSheet"]' aria-label="ปิด">
                <svg class="icon" aria-hidden="true"><use href="#i-close"/></svg>
            </button>
        </div>
        <div class="sheet-body">
            <button type="button" class="rail-search" data-act="searchFromSheet">
                <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
                <span>ค้นหาหรือสั่งงาน</span>
            </button>
            <?php foreach ($navGroups as $group): ?>
            <div class="sheet-group">
                <?php if ($group['key'] !== 'today'): ?><div class="nav-label"><?= h($group['label']) ?></div><?php endif; ?>
                <div class="sheet-links">
                    <?php foreach ($group['items'] as $item): ?>
                    <a class="sheet-link" href="<?= h(APP_URL . $item['path']) ?>"<?= isActive($item['path']) !== '' ? ' aria-current="page"' : '' ?>><?= h($item['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="sheet-group sheet-account">
                <div class="nav-label"><?= h($navName) ?></div>
                <div class="sheet-links">
                    <a class="sheet-link" href="<?= APP_URL ?>/settings">ตั้งค่า</a>
                    <a class="sheet-link" href="<?= APP_URL ?>/logout">ออกจากระบบ</a>
                </div>
            </div>
        </div>
    </dialog>

    <!-- Add a task, a quick note or an expense from any page -->
    <dialog class="sheet" id="quickSheet" aria-labelledby="quickTitle">
        <div class="sheet-head">
            <h2 id="quickTitle">เพิ่มอย่างรวดเร็ว</h2>
            <button type="button" class="icon-btn" data-act="closeSheet" data-args='["quickSheet"]' aria-label="ปิด">
                <svg class="icon" aria-hidden="true"><use href="#i-close"/></svg>
            </button>
        </div>
        <div class="seg" role="tablist" aria-label="สิ่งที่จะเพิ่ม">
            <button type="button" role="tab" id="qaTab-task" aria-selected="true" aria-controls="qaPane-task" data-kind="task">งาน</button>
            <button type="button" role="tab" id="qaTab-note" aria-selected="false" aria-controls="qaPane-note" data-kind="note" tabindex="-1">จดด่วน</button>
            <button type="button" role="tab" id="qaTab-money" aria-selected="false" aria-controls="qaPane-money" data-kind="money" tabindex="-1">รายรับรายจ่าย</button>
        </div>
        <form id="quickForm" class="sheet-body" novalidate>
            <div id="qaPane-task" role="tabpanel" aria-labelledby="qaTab-task">
                <div class="form-group">
                    <label class="form-label" for="qaTaskTitle">ชื่องาน</label>
                    <input class="form-control" id="qaTaskTitle" name="title" maxlength="200" autocomplete="off" placeholder="เช่น ส่งใบเสนอราคาให้คุณสมชาย">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="qaTaskDue">กำหนดส่ง</label>
                        <input class="form-control" type="date" id="qaTaskDue" name="due_date">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="qaTaskQuadrant">ความสำคัญ</label>
                        <select class="form-control" id="qaTaskQuadrant" name="quadrant">
                            <option value="1">สำคัญ + เร่งด่วน</option>
                            <option value="2">สำคัญ + ไม่เร่งด่วน</option>
                            <option value="3">ไม่สำคัญ + เร่งด่วน</option>
                            <option value="4">ไม่สำคัญ + ไม่เร่งด่วน</option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="qaPane-note" role="tabpanel" aria-labelledby="qaTab-note" hidden>
                <div class="form-group">
                    <label class="form-label" for="qaNoteContent">ข้อความ</label>
                    <textarea class="form-control" id="qaNoteContent" name="content" rows="3" maxlength="500" placeholder="เช่น โทรถามราคาเปลี่ยนแบตเตอรี่รถ"></textarea>
                    <p class="form-hint" id="qaNoteCount">0 / 500</p>
                </div>
            </div>

            <div id="qaPane-money" role="tabpanel" aria-labelledby="qaTab-money" hidden>
                <div class="form-group">
                    <span class="form-label" id="qaTypeLabel">ประเภท</span>
                    <div class="seg seg-pair" role="radiogroup" aria-labelledby="qaTypeLabel">
                        <label><input type="radio" name="type" value="expense" checked><span>รายจ่าย</span></label>
                        <label><input type="radio" name="type" value="income"><span>รายรับ</span></label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="qaAmount">จำนวนเงิน (บาท)</label>
                        <input class="form-control" id="qaAmount" name="amount" inputmode="decimal" autocomplete="off" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="qaCategory">หมวดหมู่</label>
                        <select class="form-control" id="qaCategory" name="category_id"><option value="0">ไม่ระบุหมวด</option></select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="qaDescription">รายละเอียด</label>
                        <input class="form-control" id="qaDescription" name="description" maxlength="200" autocomplete="off" placeholder="เช่น ข้าวมันไก่ + ชาเย็น">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="qaMoneyDate">วันที่</label>
                        <input class="form-control" type="date" id="qaMoneyDate" name="txn_date">
                    </div>
                </div>
            </div>

            <p class="form-error" id="quickError" role="alert" hidden></p>
            <div class="sheet-foot">
                <button type="button" class="btn" data-act="closeSheet" data-args='["quickSheet"]'>ยกเลิก</button>
                <button type="submit" class="btn btn-primary" id="quickSubmit">เพิ่มงาน</button>
            </div>
        </form>
    </dialog>

<!-- Main Content -->
    <main class="app-main" id="appMain">
        <div class="app-content">

            <div class="toast-container" id="toastContainer" role="status" aria-live="polite" aria-atomic="true"></div>
<?php endif; ?>
