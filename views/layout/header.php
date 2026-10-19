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

// The place the page belongs to picks its pastel: the open index tab, the
// highlighter under the heading, the top edge of its cards (tokens.css).
$placeKey = AppMenus::groupForPath(Request::path());

// The browser chrome follows the desk. "auto" lets the system decide which.
$chromeColors = ['light' => '#DCE8E0', 'dark' => '#151A18'];
?>
<!DOCTYPE html>
<html lang="th" data-theme="<?= h($themeAttr) ?>" data-theme-pref="<?= h($theme) ?>" data-day="<?= h($dayKey) ?>" data-daycolor="<?= h($dayColor) ?>" data-place="<?= h($placeKey) ?>">

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
    foreach (['anuphan-thai-var', 'anuphan-latin-var'] as $criticalFont): ?>
    <link rel="preload" as="font" type="font/woff2" crossorigin
        href="<?= APP_URL ?>/assets/fonts/<?= $criticalFont ?>.woff2">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/tokens.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/base.css?v=<?= @filemtime(PUBLIC_ROOT . '/assets/css/base.css') ?>">
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

<body<?= $isReadOnly ? ' class="share-body is-readonly"' : ($isGuest ? ' class="share-body"' : '') ?>>
<?php if ($isReadOnly || $isGuest): ?>
    <?php require ROOT . '/views/partials/icons.php'; /* the pages draw their icons from it */ ?>
    <?php if ($isReadOnly): ?>
        <!-- Shared mode: a thin bar of ink says whose page this is and that it cannot be changed -->
        <header class="share-topbar">
            <div class="share-topbar-brand">
                <a class="share-topbar-name" href="<?= h($shareHomeUrl) ?>">DayFlow</a>
                <span class="share-topbar-note">คุณกำลังดูของที่ถูกแชร์ · อ่านอย่างเดียว</span>
            </div>
            <nav class="share-topbar-menu" aria-label="หน้าที่แชร์">
                <?php foreach ($sharedMenus as $m): ?>
                    <a href="<?= h(APP_URL . '/' . $m . $shareQuery) ?>" class="share-topbar-link"<?= isActive('/' . $m) ? ' aria-current="page"' : '' ?>><?= h(AppMenus::label($m)) ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="share-topbar-actions">
                <?php if (!empty($_SESSION['user_id'])): ?>
                    <a href="<?= APP_URL ?>/exit-share" class="btn btn-sm">กลับหน้าของคุณ</a>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/login" class="btn btn-sm">เข้าสู่ระบบ</a>
                <?php endif; ?>
            </div>
        </header>
    <?php else: ?>
        <!-- A visitor on a public project board -->
        <header class="share-topbar">
            <div class="share-topbar-brand">
                <a class="share-topbar-name" href="<?= APP_URL ?>/">DayFlow</a>
                <span class="share-topbar-note">บอร์ดโครงการสาธารณะ · คุณเป็นผู้เยี่ยมชม อ่านอย่างเดียว</span>
            </div>
            <div class="share-topbar-actions">
                <a href="<?= APP_URL ?>/login" class="btn btn-sm">เข้าสู่ระบบ</a>
                <a href="<?= APP_URL ?>/register" class="btn btn-sm share-topbar-signup">สมัครสมาชิก</a>
            </div>
        </header>
    <?php endif; ?>
    <main class="app-main share-main">
        <div class="app-content">
            <div class="toast-container" id="toastContainer" role="status" aria-live="polite" aria-atomic="true"></div>
<?php else: ?>
<?php
// What the index tabs, the sub-tabs and the "all menus" sheet show, worked out once.
$navSettings = User::getSettings(Auth::userId());
$navHidden = json_decode($navSettings['hidden_menus'] ?? 'null', true);
$navHidden = is_array($navHidden) ? $navHidden : [];
$navGroups = AppMenus::grouped(AppMenus::ordered(json_decode($navSettings['menu_order'] ?? 'null', true)), $navHidden);
$navByGroup = array_column($navGroups, null, 'key');
$navName = (string)($user['display_name'] ?? $user['username'] ?? '');
// The sub-tabs of the place being shown, when it has more than one page to
// offer. A page outside every menu (settings) belongs to no place's row.
$subItems = AppMenus::menuForPath(Request::path()) !== null ? ($navByGroup[$placeKey]['items'] ?? []) : [];
require ROOT . '/views/partials/icons.php';
?>
    <a class="skip-link" href="#appMain">ข้ามไปยังเนื้อหา</a>

    <!-- The desk's top edge: the brand, five index tabs, and the three things
         a person can do from anywhere. On a phone the tabs move to the bottom. -->
    <header class="desk-top" id="appTop">
        <a class="brand" href="<?= APP_URL ?>/" aria-label="DayFlow หน้าวันนี้">DayFlow</a>

        <nav class="flags" id="appFlags" aria-label="ที่หลัก">
            <?php foreach (AppMenus::PLACES as $place):
                if (!isset($navByGroup[$place])) continue; // every page in it is hidden
                $first = $navByGroup[$place]['items'][0];
            ?>
            <a class="flag" data-place="<?= h($place) ?>" href="<?= h(APP_URL . $first['path']) ?>"<?= $place === $placeKey ? ' aria-current="page"' : '' ?>>
                <svg class="icon" aria-hidden="true"><use href="#i-<?= h(AppMenus::PLACE_ICONS[$place]) ?>"/></svg>
                <span><?= h(AppMenus::GROUPS[$place]) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="top-actions">
            <button type="button" class="top-btn top-search" data-act="openCommandPalette" title="ค้นหาหรือสั่งงาน (Ctrl K)" aria-label="ค้นหาหรือสั่งงาน">
                <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
                <span class="top-label">ค้นหา</span>
                <kbd class="top-label" data-shortcut>Ctrl K</kbd>
            </button>
            <button type="button" class="btn btn-primary top-add" data-act="openQuickAdd">
                <svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>เพิ่ม
            </button>
            <button type="button" class="top-btn" data-act="openMenuSheet" aria-haspopup="dialog" aria-label="เมนูทั้งหมด ตั้งค่า และบัญชี">
                <svg class="icon" aria-hidden="true"><use href="#i-all"/></svg>
                <span class="top-label">ทั้งหมด</span>
            </button>
        </div>
    </header>

    <!-- Phone: add from anywhere, within reach of the thumb -->
    <button type="button" class="fab" data-act="openQuickAdd" aria-label="เพิ่มงาน จดด่วน หรือรายจ่าย">
        <svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>
    </button>

    <!-- Every menu, one pastel box per place, plus the account and sign-out -->
    <dialog class="sheet menu-sheet" id="menuSheet" aria-labelledby="menuSheetTitle">
        <div class="sheet-head menu-sheet-head">
            <h2 id="menuSheetTitle">เมนูทั้งหมด</h2>
            <button type="button" class="sheet-search" data-act="searchFromSheet">
                <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
                <span>ค้นหาหรือสั่งงาน</span>
                <kbd data-shortcut>Ctrl K</kbd>
            </button>
            <button type="button" class="icon-btn" data-act="closeSheet" data-args='["menuSheet"]' aria-label="ปิด">
                <svg class="icon" aria-hidden="true"><use href="#i-close"/></svg>
            </button>
        </div>
        <div class="sheet-body menu-places">
            <?php foreach ($navGroups as $group): ?>
            <section class="menu-place" data-place="<?= h($group['key']) ?>" aria-labelledby="menu-place-<?= h($group['key']) ?>">
                <h3 class="menu-place-name" id="menu-place-<?= h($group['key']) ?>"><?= h($group['label']) ?></h3>
                <div class="menu-tiles">
                    <?php foreach ($group['items'] as $item): ?>
                    <a class="sheet-link menu-tile" data-menu-key="<?= h($item['key']) ?>" href="<?= h(APP_URL . $item['path']) ?>"<?= isActive($item['path']) !== '' ? ' aria-current="page"' : '' ?>>
                        <svg class="icon" aria-hidden="true"><use href="#i-<?= h($item['key']) ?>"/></svg><span><?= h($item['label']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
        <div class="menu-account">
            <span class="menu-avatar" aria-hidden="true"><?= h(mb_substr($navName !== '' ? $navName : 'D', 0, 1)) ?></span>
            <span class="menu-who">
                <strong><?= h($navName) ?></strong>
                <?php if (!empty($user['email'])): ?><span><?= h($user['email']) ?></span><?php endif; ?>
            </span>
            <a class="sheet-link btn btn-sm" data-menu-key="settings" href="<?= APP_URL ?>/settings"<?= isActive('/settings') !== '' ? ' aria-current="page"' : '' ?>>
                <svg class="icon" aria-hidden="true"><use href="#i-settings"/></svg>ตั้งค่า
            </a>
            <a class="sheet-link sheet-logout btn btn-sm btn-ghost" href="<?= APP_URL ?>/logout">
                <svg class="icon" aria-hidden="true"><use href="#i-logout"/></svg>ออกจากระบบ
            </a>
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

    <main class="app-main" id="appMain">
        <?php if (count($subItems) > 1): ?>
        <nav class="subnav" aria-label="<?= h(AppMenus::GROUPS[$placeKey]) ?>">
            <?php foreach ($subItems as $item): ?>
            <a class="subnav-link" href="<?= h(APP_URL . $item['path']) ?>"<?= isActive($item['path']) !== '' ? ' aria-current="page"' : '' ?>><?= h($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <div class="app-content">

            <div class="toast-container" id="toastContainer" role="status" aria-live="polite" aria-atomic="true"></div>
<?php endif; ?>
