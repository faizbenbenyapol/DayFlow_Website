<?php
// =====================================================
// views/settings/index.php — settings
//
// A list of topics on the left, the open topic on the right (a row of tabs on
// a phone). Every topic is its own pane; settings.js shows one at a time and
// the other settings-*.js files fill their panes. $user, $settings and $layout
// come from SettingsController.
// =====================================================

// Common timezones
$timezones = [
    'Asia/Bangkok'     => 'เวลาไทย (ICT, UTC+7)',
    'Asia/Tokyo'       => 'โตเกียว (UTC+9)',
    'Asia/Singapore'   => 'สิงคโปร์ (UTC+8)',
    'Asia/Hong_Kong'   => 'ฮ่องกง (UTC+8)',
    'Asia/Dubai'       => 'ดูไบ (UTC+4)',
    'Asia/Kolkata'     => 'อินเดีย (UTC+5:30)',
    'Europe/London'    => 'ลอนดอน (UTC+0/+1)',
    'Europe/Paris'     => 'ปารีส (UTC+1/+2)',
    'Europe/Berlin'    => 'เบอร์ลิน (UTC+1/+2)',
    'America/New_York' => 'นิวยอร์ก (UTC-5/-4)',
    'America/Los_Angeles' => 'ลอสแอนเจลิส (UTC-8/-7)',
    'Australia/Sydney' => 'ซิดนีย์ (UTC+10/+11)',
    'UTC'              => 'UTC',
];
$currentTz = $settings['timezone'] ?? 'Asia/Bangkok';

// The topics, grouped. tab key => label.
$topics = [
    'บัญชี' => [
        'profile'  => 'โปรไฟล์',
        'password' => 'รหัสผ่านและความปลอดภัย',
        'devices'  => 'อุปกรณ์ที่จำไว้',
        'account'  => 'ข้อมูลบัญชี',
    ],
    'การใช้งาน' => [
        'appearance'       => 'หน้าตาและเขตเวลา',
        'menus'            => 'เมนู',
        'dashboard-config' => 'หน้าวันนี้',
        'categories'       => 'หมวดหมู่และแท็ก',
    ],
    'การเชื่อมต่อ' => [
        'stock-api' => 'API หุ้นและ AI',
        'telegram'  => 'แจ้งเตือน',
    ],
    'การแชร์' => [
        'app-shares' => 'แชร์เมนู',
        'shares'     => 'ไฟล์ที่แชร์',
    ],
    'ข้อมูล' => [
        'data'   => 'สำรองและนำเข้า',
        'danger' => 'ลบบัญชี',
    ],
];

$hiddenMenus = !empty($settings['hidden_menus']) ? json_decode($settings['hidden_menus'], true) : [];
if (!is_array($hiddenMenus)) $hiddenMenus = [];
$menuOrder = AppMenus::ordered(json_decode($settings['menu_order'] ?? 'null', true));
[$tabA, $tabB] = AppMenus::mobileTabs(json_decode($settings['mobile_tabs'] ?? 'null', true), $hiddenMenus);
?>
<div class="page-head">
    <div>
        <h1>ตั้งค่า</h1>
        <p class="sub">บัญชี หน้าตา เมนู การเชื่อมต่อ และข้อมูลของคุณ</p>
    </div>
</div>

<div class="settings-layout">
<nav class="settings-nav" id="settingsTabs" role="tablist" aria-label="หัวข้อการตั้งค่า" aria-orientation="vertical">
    <?php $first = true; foreach ($topics as $group => $items): ?>
    <div class="settings-nav-group" role="presentation">
        <p class="settings-nav-title" role="presentation"><?= h($group) ?></p>
        <?php foreach ($items as $key => $label): ?>
        <button type="button" class="settings-tab" role="tab" id="tabbtn-<?= h($key) ?>" data-tab="<?= h($key) ?>"
                aria-controls="tab-<?= h($key) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>"><?= h($label) ?></button>
        <?php $first = false; endforeach; ?>
    </div>
    <?php endforeach; ?>
</nav>

<div class="settings-panes">

<!-- PROFILE -->
<section id="tab-profile" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-profile">
    <h2>โปรไฟล์</h2>
    <div class="form-group">
        <label class="form-label" for="profileName">ชื่อที่แสดง</label>
        <input type="text" class="form-control" id="profileName" value="<?= h($user['display_name'] ?? '') ?>" maxlength="100">
    </div>
    <div class="form-group">
        <label class="form-label" for="profileEmail">อีเมล</label>
        <input type="email" class="form-control" id="profileEmail" value="<?= h($user['email'] ?? '') ?>" maxlength="150">
    </div>
    <div class="form-group">
        <label class="form-label" for="profileUsername">ชื่อผู้ใช้งาน</label>
        <input type="text" class="form-control" id="profileUsername" value="<?= h($user['username'] ?? '') ?>" disabled>
        <p class="form-hint">เปลี่ยนชื่อผู้ใช้งานไม่ได้</p>
    </div>
    <div class="settings-actions">
        <button class="btn btn-primary" type="button" id="btnSaveProfile">บันทึกโปรไฟล์</button>
    </div>
</section>

<!-- PASSWORD + TWO-FACTOR -->
<section id="tab-password" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-password" hidden>
    <h2>เปลี่ยนรหัสผ่าน</h2>
    <div class="form-group">
        <label class="form-label" for="pwCurrent">รหัสผ่านปัจจุบัน</label>
        <div class="pw-field">
            <input type="password" class="form-control" id="pwCurrent" autocomplete="current-password">
            <button type="button" class="pw-toggle" data-target="pwCurrent" aria-label="แสดงรหัสผ่านปัจจุบัน">แสดง</button>
        </div>
    </div>
    <div class="form-group">
        <label class="form-label" for="pwNew">รหัสผ่านใหม่</label>
        <div class="pw-field">
            <input type="password" class="form-control" id="pwNew" autocomplete="new-password" minlength="8">
            <button type="button" class="pw-toggle" data-target="pwNew" aria-label="แสดงรหัสผ่านใหม่">แสดง</button>
        </div>
        <div class="pw-strength" id="pwStrength">
            <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwStrengthFill"></div></div>
            <p class="pw-strength-text" id="pwStrengthText">อย่างน้อย 8 ตัวอักษร</p>
        </div>
    </div>
    <div class="form-group">
        <label class="form-label" for="pwConfirm">ยืนยันรหัสผ่านใหม่</label>
        <div class="pw-field">
            <input type="password" class="form-control" id="pwConfirm" autocomplete="new-password">
            <button type="button" class="pw-toggle" data-target="pwConfirm" aria-label="แสดงรหัสผ่านที่ยืนยัน">แสดง</button>
        </div>
        <p class="form-hint" id="pwMatchHint" aria-live="polite"></p>
    </div>
    <div class="settings-actions">
        <button class="btn btn-primary" type="button" id="btnChangePassword">เปลี่ยนรหัสผ่าน</button>
    </div>

    <div class="settings-block">
        <h2>การยืนยันตัวตนสองชั้น (2FA) <span class="badge" id="tfaBadge">กำลังตรวจสอบ</span></h2>
        <p class="form-hint">เพิ่มขั้นยืนยันด้วยแอป Authenticator (Google Authenticator, Authy, 1Password) หลังกรอกรหัสผ่าน ต่อให้รหัสผ่านหลุดก็ยังเข้าบัญชีไม่ได้</p>

        <div id="tfaOff">
            <div class="form-group">
                <label class="form-label" for="tfaBeginPassword">ยืนยันรหัสผ่านของคุณ</label>
                <input type="password" class="form-control" id="tfaBeginPassword" autocomplete="current-password">
            </div>
            <button class="btn btn-primary" type="button" id="btnTfaBegin">เริ่มตั้งค่า</button>
        </div>

        <div id="tfaEnrol" hidden>
            <p class="settings-step">1. สแกน QR นี้ด้วยแอป Authenticator</p>
            <div id="tfaQr" class="qr-box"></div>
            <p class="form-hint">หรือกรอกรหัสนี้เอง: <code id="tfaSecret" class="selectable"></code></p>
            <div class="form-group">
                <label class="form-label" for="tfaConfirmCode">2. กรอกรหัส 6 หลักที่แอปแสดง</label>
                <input type="text" class="form-control" id="tfaConfirmCode" inputmode="numeric" maxlength="6" placeholder="123456" autocomplete="one-time-code">
            </div>
            <div class="settings-actions">
                <button class="btn btn-primary" type="button" id="btnTfaConfirm">เปิดใช้งาน</button>
                <button class="btn btn-ghost" type="button" id="btnTfaCancel">ยกเลิก</button>
            </div>
        </div>

        <div id="tfaOn" hidden>
            <p>เปิดใช้งานอยู่ · เหลือรหัสสำรอง <strong id="tfaCodesLeft">-</strong> ชุด</p>
            <div class="form-group">
                <label class="form-label" for="tfaManagePassword">ยืนยันรหัสผ่านเพื่อดำเนินการ</label>
                <input type="password" class="form-control" id="tfaManagePassword" autocomplete="current-password">
            </div>
            <div class="settings-actions">
                <button class="btn btn-ghost" type="button" id="btnTfaRegenerate">สร้างรหัสสำรองใหม่</button>
                <button class="btn btn-danger" type="button" id="btnTfaDisable">ปิดการใช้งาน</button>
            </div>
        </div>

        <div id="tfaRecovery" hidden>
            <p class="settings-step">รหัสสำรอง เก็บไว้ในที่ปลอดภัย จะแสดงครั้งเดียวเท่านั้น</p>
            <p class="form-hint">ใช้แทนรหัสจากแอปเมื่ออุปกรณ์หาย แต่ละชุดใช้ได้ครั้งเดียว</p>
            <pre id="tfaRecoveryList" class="recovery-list"></pre>
            <button class="btn btn-ghost btn-sm" type="button" id="btnTfaCopyCodes">คัดลอกทั้งหมด</button>
        </div>
    </div>
</section>

<!-- REMEMBERED DEVICES -->
<section id="tab-devices" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-devices" hidden>
    <div class="settings-head">
        <div>
            <h2>อุปกรณ์ที่จำไว้</h2>
            <p class="form-hint">อุปกรณ์ที่เลือก "จดจำอุปกรณ์นี้" ไว้ จะเข้าสู่ระบบให้อัตโนมัติภายใน 30 วัน</p>
        </div>
        <button class="btn btn-ghost btn-sm" id="btnRevokeOtherDevices" type="button">ออกจากอุปกรณ์อื่นทั้งหมด</button>
    </div>
    <div id="deviceList" class="device-list" aria-live="polite">
        <div class="skel-row"><span class="skel skel-w-60"></span></div>
        <div class="skel-row"><span class="skel skel-w-45"></span></div>
    </div>
</section>

<!-- ACCOUNT INFO -->
<section id="tab-account" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-account" hidden>
    <h2>ข้อมูลบัญชี</h2>
    <dl class="account-info">
        <dt>ชื่อผู้ใช้งาน</dt>
        <dd><?= h($user['username'] ?? '—') ?></dd>

        <dt>อีเมล</dt>
        <dd><?= h($user['email'] ?? '—') ?></dd>

        <dt>ชื่อที่แสดง</dt>
        <dd><?= h($user['display_name'] ?? '—') ?></dd>

        <dt>เขตเวลา</dt>
        <dd><?= h($currentTz) ?></dd>

        <dt>ธีม</dt>
        <dd><?= h(['dark' => 'มืด', 'auto' => 'ตามระบบ'][$settings['theme'] ?? 'light'] ?? 'สว่าง') ?></dd>

        <dt>สมัครเมื่อ</dt>
        <dd>
            <?php if (!empty($user['created_at'])): ?>
                <?= h(date('d/m/Y H:i', strtotime($user['created_at']))) ?>
                <span class="meta">(<?= max(0, (int)floor((time() - strtotime($user['created_at'])) / 86400)) ?> วันที่แล้ว)</span>
            <?php else: ?>
                —
            <?php endif; ?>
        </dd>

        <dt>รหัสบัญชี</dt>
        <dd><code>#<?= (int)($user['id'] ?? 0) ?></code></dd>
    </dl>
</section>

<!-- APPEARANCE + TIMEZONE -->
<section id="tab-appearance" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-appearance" hidden>
    <h2>ธีม</h2>
    <div class="theme-cards-container" role="radiogroup" aria-label="ธีม">
        <?php foreach (['auto' => 'ตามระบบ', 'light' => 'สว่าง', 'dark' => 'มืด'] as $value => $label): ?>
        <label class="theme-card-option">
            <input type="radio" name="theme" value="<?= $value ?>" <?= ($settings['theme'] ?? 'light') === $value ? 'checked' : '' ?> class="sr-only">
            <span class="theme-card-preview theme-<?= $value ?>-preview" aria-hidden="true">
                <span class="preview-header"></span>
                <span class="preview-body"><span class="preview-line-1"></span><span class="preview-line-2"></span></span>
            </span>
            <span class="theme-card-label"><span class="theme-card-dot"></span><?= h($label) ?></span>
        </label>
        <?php endforeach; ?>
    </div>

    <div class="settings-block">
        <h2>สีประจำวัน</h2>
        <div class="day-color-row">
            <div class="day-color-preview" aria-hidden="true">
                <span class="day-color-num"><?= (int)date('j') ?></span>
                <span class="day-color-mon"><?= h([1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][(int)date('n')]) ?></span>
            </div>
            <div class="day-color-text">
                <label class="day-color-title" for="dayColorSwitch">ใช้สีตามวันในสัปดาห์</label>
                <p class="form-hint">แถบวันที่ในหน้าวันนี้และขีดข้างเมนูที่เปิดอยู่จะเปลี่ยนสีตามวัน เช่น วันศุกร์เป็นสีฟ้า วันอาทิตย์เป็นสีแดง ปิดแล้วจะใช้สีหมึกตลอด</p>
            </div>
            <label class="switch">
                <input type="checkbox" id="dayColorSwitch" <?= ($settings['day_color'] ?? 1) ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
    </div>

    <div class="settings-block">
        <h2>เขตเวลา</h2>
        <div class="form-group">
            <label class="form-label" for="timezoneSelect">เขตเวลาที่ใช้แสดงผล</label>
            <select class="form-control" id="timezoneSelect">
                <?php foreach ($timezones as $tz => $label): ?>
                    <option value="<?= h($tz) ?>" <?= $tz === $currentTz ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">เวลาตอนนี้ในเขตนี้: <span id="tzCurrentTime">—</span></p>
        </div>
        <div class="settings-actions">
            <button class="btn btn-primary" type="button" id="btnSaveTimezone">บันทึกเขตเวลา</button>
        </div>
    </div>
</section>

<!-- MENUS -->
<section id="tab-menus" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-menus" hidden>
    <h2>เมนู</h2>
    <p class="form-hint">ติ๊กเมนูที่ต้องการให้แสดง ลากที่จับ ⋮⋮ หรือกดลูกศรเพื่อเรียงลำดับ ลำดับมีผลภายในกลุ่มเดียวกัน เมนูที่ไม่ติ๊กจะถูกซ่อนจากแถบเมนูและหน้าอื่น</p>

    <div id="menuVisibilityList" data-menu-order="<?= h(json_encode($menuOrder, JSON_UNESCAPED_UNICODE)) ?>">
        <?php foreach (AppMenus::GROUPS as $groupKey => $heading):
            if ($groupKey === 'today') continue;
            $keys = array_values(array_filter($menuOrder, fn(string $k): bool => AppMenus::MENUS[$k]['group'] === $groupKey));
            if (!$keys) continue; ?>
        <fieldset class="menu-group">
            <legend><?= h($heading) ?></legend>
            <div class="menu-order-list" data-group="<?= h($groupKey) ?>">
                <?php foreach ($keys as $menu): ?>
                <label class="menu-order-item settings-check" data-menu-key="<?= h($menu) ?>">
                    <input type="checkbox" name="visible_menus[]" value="<?= h($menu) ?>" <?= in_array($menu, $hiddenMenus, true) ? '' : 'checked' ?>>
                    <span><?= h(AppMenus::label($menu)) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php endforeach; ?>
    </div>

    <div class="settings-block">
        <h2>แถบเมนูล่างบนมือถือ</h2>
        <p class="form-hint">แถบล่างมี "วันนี้" ปุ่ม + และ "ทั้งหมด" อยู่แล้ว เลือกเมนูที่เปิดบ่อยอีกสองที่ให้อยู่ในแถบ</p>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="mobileTab1">ช่องที่ 1</label>
                <select class="form-control mobile-tab-select" id="mobileTab1" data-current="<?= h($tabA) ?>"></select>
            </div>
            <div class="form-group">
                <label class="form-label" for="mobileTab2">ช่องที่ 2</label>
                <select class="form-control mobile-tab-select" id="mobileTab2" data-current="<?= h($tabB) ?>"></select>
            </div>
        </div>
        <p class="form-error" id="menuError" role="alert" hidden></p>
    </div>

    <div class="settings-actions">
        <button class="btn btn-primary" type="button" id="btnSaveMenus">บันทึกเมนู</button>
    </div>
</section>

<!-- TODAY PAGE -->
<section id="tab-dashboard-config" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-dashboard-config" hidden>
    <h2>หน้าวันนี้</h2>
    <p class="form-hint">เลือกส่วนที่จะแสดงในหน้าวันนี้ แล้วลากที่จับ ⋮⋮ เพื่อเรียงลำดับ ลำดับมีผลภายในพื้นที่เดียวกัน คือฝั่งซ้าย ฝั่งขวา และแถบด้านล่าง</p>

    <form id="customizeDashboardForm" data-prevent>
        <div class="widget-list" id="dashboardWidgetsList">
            <?php
            $widgetLabels = [
                'tasks'         => 'ต้องทำ',
                'calendar'      => 'กำหนดการ',
                'habits'        => 'นิสัยวันนี้',
                'finance'       => 'เงินเดือนนี้',
                'subscriptions' => 'ตัดเงินใน 7 วัน',
                'workout'       => 'ร่างกายและโฟกัส',
                'projects'      => 'โปรเจคล่าสุด',
                'notes'         => 'โน้ตล่าสุด',
                'stocks'        => 'หุ้น',
                'transfer'      => 'ส่งไฟล์ล่าสุด',
            ];
            $widgetArea = [];
            foreach (DashboardLayout::AREAS as $areaKey => $keys) {
                foreach ($keys as $k) $widgetArea[$k] = ['main' => 'ฝั่งซ้าย', 'side' => 'ฝั่งขวา', 'more' => 'แถบด้านล่าง'][$areaKey];
            }

            foreach ($layout as $widget):
                $key = $widget['widget_key'];
                $label = $widgetLabels[$key] ?? $key;
            ?>
                <label class="settings-check" data-widget-key="<?= h($key) ?>">
                    <span class="drag-handle" aria-hidden="true">⋮⋮</span>
                    <input type="checkbox" name="widget_<?= h($key) ?>" id="chk_<?= h($key) ?>" value="1" <?= $widget['is_visible'] ? 'checked' : '' ?>>
                    <span><?= h($label) ?> <span class="meta">· <?= h($widgetArea[$key] ?? '') ?></span></span>
                </label>
            <?php endforeach; ?>
        </div>
    </form>
    <div class="settings-actions">
        <button class="btn btn-ghost" type="button" id="btnResetDashboardLayout">คืนค่าเริ่มต้น</button>
        <button class="btn btn-primary" type="button" id="btnSaveDashboardCustomization">บันทึกหน้าวันนี้</button>
    </div>
</section>

<script nonce="<?= h(Security::nonce()) ?>">
window.dashboardLayout = <?= jsonForScript($layout) ?>;
window.dashboardDefaults = <?= jsonForScript(DashboardLayout::defaults()) ?>;
window.menuLabels = <?= jsonForScript(array_map(fn(array $m): string => $m['label'], AppMenus::MENUS)) ?>;
</script>

<!-- CATEGORIES AND TAGS -->
<section id="tab-categories" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-categories" hidden>
    <h2>หมวดรายรับรายจ่าย</h2>
    <p class="form-hint">หมวดที่เลือกได้ในหน้า "รายรับรายจ่าย"</p>
    <form id="finCatForm" class="cat-add-row" data-prevent>
        <label class="sr-only" for="finCatNewName">ชื่อหมวดใหม่</label>
        <input type="text" class="form-control" id="finCatNewName" placeholder="ชื่อหมวดใหม่" maxlength="100" autocomplete="off">
        <label class="sr-only" for="finCatNewType">ประเภท</label>
        <select class="form-control cat-type" id="finCatNewType">
            <option value="expense">รายจ่าย</option>
            <option value="income">รายรับ</option>
        </select>
        <button class="btn btn-primary" id="btnFinCatAdd" type="submit">เพิ่ม</button>
    </form>
    <h3 class="subhead">รายรับ</h3>
    <ul class="cat-list" id="finCatListIncome"></ul>
    <h3 class="subhead">รายจ่าย</h3>
    <ul class="cat-list" id="finCatListExpense"></ul>

    <div class="settings-block">
        <h2>ประเภทการออกกำลังกาย</h2>
        <p class="form-hint">ประเภทที่ให้เลือกตอนบันทึกการออกกำลังกาย</p>
        <form id="exCatForm" class="cat-add-row" data-prevent>
            <label class="sr-only" for="exCatNewName">ชื่อประเภทใหม่</label>
            <input type="text" class="form-control" id="exCatNewName" placeholder="ชื่อประเภทใหม่" maxlength="80" autocomplete="off">
            <button class="btn btn-primary" id="btnExCatAdd" type="submit">เพิ่ม</button>
        </form>
        <ul class="cat-list" id="exCatList"></ul>
    </div>

    <div class="settings-block">
        <h2>แท็กของโน้ต</h2>
        <p class="form-hint">แท็กที่ใช้จัดกลุ่มโน้ต</p>
        <form id="noteTagForm" class="cat-add-row" data-prevent>
            <label class="sr-only" for="noteTagNewName">ชื่อแท็กใหม่</label>
            <input type="text" class="form-control" id="noteTagNewName" placeholder="ชื่อแท็กใหม่" maxlength="50" autocomplete="off">
            <button class="btn btn-primary" id="btnNoteTagAdd" type="submit">เพิ่ม</button>
        </form>
        <ul class="cat-list" id="noteTagList"></ul>
    </div>
</section>

<!-- STOCK AND AI KEYS -->
<section id="tab-stock-api" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-stock-api" hidden>
    <h2>API สำหรับราคาหุ้น</h2>
    <p class="form-hint">เชื่อมต่อผู้ให้บริการเพื่อดึงราคาปัจจุบันมาคำนวณกำไรขาดทุนในหน้า "หุ้น" ใช้ฟรีได้ตามโควตาของแต่ละเจ้า ลงทะเบียนแล้วนำ API key มาใส่</p>
    <div id="stockKeysList"><div class="skel-row"><span class="skel skel-w-52"></span></div></div>
    <ul class="help-list">
        <li>Finnhub (<a href="https://finnhub.io/register" target="_blank" rel="noopener">finnhub.io</a>) 60 ครั้งต่อนาที รองรับ US และ SET (<code>.BK</code>)</li>
        <li>Alpha Vantage (<a href="https://www.alphavantage.co/support/#api-key" target="_blank" rel="noopener">alphavantage.co</a>) 25 ครั้งต่อวัน</li>
        <li>Twelve Data (<a href="https://twelvedata.com/register" target="_blank" rel="noopener">twelvedata.com</a>) 800 ครั้งต่อวัน</li>
    </ul>

    <div class="settings-block">
        <h2>API สำหรับวิเคราะห์หุ้นด้วย AI</h2>
        <p class="form-hint">ใส่ API Key ของผู้ให้บริการ AI เพื่อใช้ "วิเคราะห์ด้วย AI" ในหน้าหุ้น ผลที่ได้เป็นความเห็นของโมเดล ไม่ใช่คำแนะนำการลงทุน</p>
        <div id="stockAiKeysList"><div class="skel-row"><span class="skel skel-w-52"></span></div></div>
        <ul class="help-list">
            <li>Google Gemini (<a href="https://aistudio.google.com/" target="_blank" rel="noopener">Google AI Studio</a>) ใช้ฟรีได้</li>
            <li>OpenAI (<a href="https://platform.openai.com/" target="_blank" rel="noopener">platform.openai.com</a>) คิดตามการใช้งานจริง</li>
            <li>Anthropic Claude (<a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>)</li>
            <li>Moonshot Kimi (<a href="https://platform.moonshot.cn/" target="_blank" rel="noopener">platform.moonshot.cn</a>)</li>
            <li>OpenRouter (<a href="https://openrouter.ai/" target="_blank" rel="noopener">openrouter.ai</a>) รวมโมเดลหลายค่ายใน API เดียว</li>
        </ul>
    </div>
</section>

<!-- NOTIFICATIONS -->
<section id="tab-telegram" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-telegram" hidden>
    <h2>แจ้งเตือนผ่านเบราว์เซอร์ <span class="badge" id="pushBadge">กำลังตรวจสอบ</span></h2>
    <p class="form-hint">แจ้งเตือนงานที่ครบกำหนด กิจกรรม และรายการที่ใกล้ถึงรอบชำระ ตรงไปที่เบราว์เซอร์หรือแอปที่ติดตั้งไว้ โดยไม่ต้องตั้งค่า Bot เนื้อหาถูกดึงจากเซิร์ฟเวอร์ของคุณเอง ไม่ได้ส่งผ่านบริการ Push ของผู้ให้บริการ</p>

    <div id="pushUnavailable" hidden>
        <p class="form-hint" id="pushUnavailableReason"></p>
    </div>

    <div id="pushControls" hidden>
        <p>อุปกรณ์ที่เปิดแจ้งเตือนไว้: <strong id="pushDeviceCount">-</strong></p>
        <div class="settings-actions">
            <button class="btn btn-primary" type="button" id="btnPushEnable">เปิดแจ้งเตือนบนอุปกรณ์นี้</button>
            <button class="btn btn-ghost" type="button" id="btnPushDisable" hidden>ปิดบนอุปกรณ์นี้</button>
            <button class="btn btn-ghost" type="button" id="btnPushTest" hidden>ทดสอบส่ง</button>
        </div>
    </div>

    <div class="settings-block">
        <h2>Telegram Bot</h2>
        <p class="form-hint">รับการแจ้งเตือนจากระบบผ่าน Telegram</p>
        <div class="form-group">
            <label class="form-label" for="telegramBotToken">Bot Token</label>
            <input type="text" class="form-control" id="telegramBotToken" value="" autocomplete="off" placeholder="เว้นว่างเพื่อคง Token เดิม หรือกรอก Token ใหม่">
        </div>
        <div class="form-group">
            <label class="form-label" for="telegramChatId">Chat ID</label>
            <input type="text" class="form-control" id="telegramChatId" value="<?= h($settings['telegram_chat_id'] ?? '') ?>" autocomplete="off" placeholder="123456789 หรือ -123456789 สำหรับกลุ่ม">
        </div>
        <?php
        $tgEvents = !empty($settings['telegram_notify_events']) ? json_decode($settings['telegram_notify_events'], true) : [];
        $isTgEnabled = fn(string $event): bool => !isset($tgEvents[$event]) || $tgEvents[$event] !== false;
        $tgLabels = [
            'project'      => 'โปรเจค (สร้างใหม่ / ทีม)',
            'task'         => 'งาน (เมื่อทำเสร็จ)',
            'note'         => 'โน้ต (สร้างใหม่)',
            'planner'      => 'แพลนเนอร์ (กิจกรรมใหม่)',
            'focus'        => 'โฟกัส (เมื่อสิ้นสุดเวลา)',
            'subscription' => 'รายจ่ายประจำ (เมื่อสร้างใหม่)',
        ];
        ?>
        <fieldset class="form-group">
            <legend class="form-label">แจ้งเตือนเมื่อมีเหตุการณ์จาก</legend>
            <div class="check-list" id="telegramEventsList">
                <?php foreach ($tgLabels as $event => $label): ?>
                <label class="settings-check"><input type="checkbox" name="tg_events[]" value="<?= h($event) ?>" <?= $isTgEnabled($event) ? 'checked' : '' ?>> <?= h($label) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <h3 class="subhead">ตั้งเวลาให้ระบบส่งแจ้งเตือนเอง (Cron)</h3>
        <p class="form-hint">ถ้าต้องการให้ระบบส่งแจ้งเตือนงานและแพลนเนอร์ทุกวันโดยอัตโนมัติ ตั้ง Cron บนเซิร์ฟเวอร์ของคุณ</p>
        <div class="cron-command-card">
            <strong>วิธีที่ 1: Docker worker (แนะนำสำหรับ VPS)</strong>
            <code>docker compose --profile prod-worker up -d cron</code>
        </div>
        <div class="cron-command-card">
            <strong>วิธีที่ 2: เรียกผ่าน CLI</strong>
            <code>php <?= h(ROOT) ?>/cron.php</code>
        </div>

        <div class="settings-actions">
            <button class="btn btn-ghost" type="button" id="btnTestTelegram">ทดสอบส่งข้อความ</button>
            <button class="btn btn-primary" type="button" id="btnSaveTelegram">บันทึก Telegram</button>
        </div>
    </div>
</section>

<!-- MENU SHARES -->
<section id="tab-app-shares" class="settings-pane settings-pane-wide" role="tabpanel" aria-labelledby="tabbtn-app-shares" hidden>
    <h2>สร้างลิงก์แชร์เมนู</h2>
    <p class="form-hint">ลิงก์นี้ให้คนอื่นดูข้อมูลในเมนูที่เลือกได้แบบสด อ่านได้อย่างเดียว</p>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label" for="asNewLabel">ชื่อลิงก์ (ไว้จำ)</label>
            <input type="text" class="form-control" id="asNewLabel" placeholder="เช่น ให้ทีมงานดูความคืบหน้า" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="asNewExpires">หมดอายุเมื่อ (ไม่บังคับ)</label>
            <input type="datetime-local" class="form-control" id="asNewExpires">
        </div>
    </div>
    <fieldset class="form-group">
        <legend class="form-label">เมนูที่จะแชร์</legend>
        <div class="check-list check-list-row">
            <?php foreach (['tasks' => 'งาน', 'notes' => 'โน้ต', 'planner' => 'แพลนเนอร์', 'exercise' => 'ออกกำลังกาย', 'food-notes' => 'อาหาร', 'finance' => 'รายรับรายจ่าย', 'subscriptions' => 'รายจ่ายประจำ', 'stocks' => 'หุ้น'] as $value => $label): ?>
            <label class="settings-check"><input type="checkbox" name="as_menus[]" value="<?= h($value) ?>"> <?= h($label) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <div class="settings-actions">
        <button class="btn btn-primary" type="button" id="btnCreateAppShare">สร้างลิงก์แชร์เมนู</button>
    </div>
    <p class="form-error" id="appShareError" role="alert" hidden></p>

    <div class="settings-block">
        <h2>ลิงก์แชร์เมนูของคุณ</h2>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">ชื่อลิงก์</th>
                        <th scope="col">เมนูที่แชร์</th>
                        <th scope="col">ลิงก์</th>
                        <th scope="col">หมดอายุ</th>
                        <th scope="col"><span class="sr-only">จัดการ</span></th>
                    </tr>
                </thead>
                <tbody id="appSharesTableBody">
                    <tr><td colspan="5"><span class="skel skel-w-52"></span></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- FILE SHARES -->
<section id="tab-shares" class="settings-pane settings-pane-wide" role="tabpanel" aria-labelledby="tabbtn-shares" hidden>
    <h2>ลิงก์แชร์ไฟล์และโฟลเดอร์</h2>
    <p class="form-hint">ลิงก์ที่แชร์ไฟล์หรือโฟลเดอร์จากหน้า "ไฟล์" เลือกให้ดูอย่างเดียวหรือดาวน์โหลดได้</p>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">ชื่อไฟล์หรือโฟลเดอร์</th>
                    <th scope="col">ลิงก์แชร์</th>
                    <th scope="col">สิทธิ์</th>
                    <th scope="col">หมดอายุ</th>
                    <th scope="col"><span class="sr-only">จัดการ</span></th>
                </tr>
            </thead>
            <tbody id="sharesTableBody">
                <tr><td colspan="5"><span class="skel skel-w-52"></span></td></tr>
            </tbody>
        </table>
    </div>
</section>

<!-- BACKUP AND IMPORT -->
<section id="tab-data" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-data" hidden>
    <h2>ส่งออกข้อมูล</h2>
    <p class="form-hint">ดาวน์โหลดข้อมูลส่วนตัวเป็นไฟล์ JSON เพื่อเก็บสำรอง ได้แก่ งาน โน้ต แพลนเนอร์ การออกกำลังกาย อาหาร การเงิน รายจ่ายประจำ หุ้น ทักษะ โฟกัส นิสัย จดด่วน ลิงก์ ประวัติ AI และการตั้งค่าการแสดงผล</p>
    <p class="form-hint">ไม่รวม: โปรเจกต์ (แชร์กับสมาชิกคนอื่น), ไฟล์และภาพหน้าจอหุ้น, API key, การยืนยันตัวตนสองชั้น, Telegram และลิงก์แชร์</p>
    <a class="btn btn-primary" href="<?= APP_URL ?>/api/settings/export" download>ดาวน์โหลด JSON</a>

    <div class="settings-block">
        <h2>นำเข้าข้อมูล</h2>
        <p class="form-hint">อัปโหลดไฟล์สำรอง JSON เพื่อนำข้อมูลกลับมา</p>
        <p class="alert alert-warning">ข้อมูลแต่ละส่วนที่มีอยู่ในไฟล์จะแทนที่ข้อมูลส่วนนั้นในระบบทั้งหมด ส่วนที่ไม่มีในไฟล์จะไม่ถูกแตะต้อง</p>
        <button type="button" class="settings-import-zone" id="settingsImportZone" data-click="#importFile">
            <svg class="icon" aria-hidden="true"><use href="#i-transfer"/></svg>
            <span class="settings-import-text" id="importFileNameText">กดเพื่อเลือกไฟล์ข้อมูลสำรอง (.json)</span>
        </button>
        <input type="file" id="importFile" accept=".json" hidden aria-label="ไฟล์ข้อมูลสำรอง">
        <div class="settings-actions">
            <button class="btn btn-primary" type="button" id="btnImportData" disabled>เริ่มนำเข้าข้อมูล</button>
        </div>
    </div>

    <div class="settings-block">
        <h2>ข้อมูลในเบราว์เซอร์นี้</h2>
        <p class="form-hint">ที่เก็บในเบราว์เซอร์นี้เท่านั้น เช่น ประวัติการคำนวณ แท็บที่เปิดล่าสุด การลบไม่กระทบข้อมูลบนเซิร์ฟเวอร์</p>
        <p class="form-hint" id="localStorageInfo">—</p>
        <button class="btn btn-ghost" type="button" id="btnClearLocal">ล้างข้อมูลในเบราว์เซอร์</button>
    </div>
</section>

<!-- DELETE ACCOUNT -->
<section id="tab-danger" class="settings-pane" role="tabpanel" aria-labelledby="tabbtn-danger" hidden>
    <h2 class="danger-title">ลบบัญชีถาวร</h2>
    <p class="alert alert-danger">การลบบัญชีจะลบข้อมูลทั้งหมดของคุณออกจากระบบอย่างถาวร กู้คืนไม่ได้ ควรดาวน์โหลดข้อมูลสำรองก่อน</p>
    <div class="form-group">
        <label class="form-label" for="delConfirm">พิมพ์ <code>DELETE</code> เพื่อยืนยัน</label>
        <input type="text" class="form-control" id="delConfirm" placeholder="DELETE" autocomplete="off">
    </div>
    <div class="form-group">
        <label class="form-label" for="delPassword">รหัสผ่านปัจจุบัน</label>
        <input type="password" class="form-control" id="delPassword" autocomplete="current-password">
    </div>
    <button class="btn btn-danger" type="button" id="btnDeleteAccount" disabled>ลบบัญชีถาวร</button>
</section>

</div><!-- /.settings-panes -->
</div><!-- /.settings-layout -->

<!-- Edit a file share -->
<div class="modal-backdrop" id="shareModalOverlay" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="shareModalTitle" id="shareModal">
        <div class="modal-header">
            <h2 class="modal-title" id="shareModalTitle">แก้ไขลิงก์แชร์</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal id="btnCloseShareModal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label" for="smLabel">ชื่อลิงก์ (ไว้จำ)</label>
                <input type="text" class="form-control" id="smLabel" placeholder="เช่น ส่งให้เพื่อน, งานนำเสนอ" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label" for="smPermission">สิทธิ์</label>
                <select class="form-control" id="smPermission">
                    <option value="view">ดูอย่างเดียว</option>
                    <option value="download">ดาวน์โหลดได้</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="smExpires">หมดอายุเมื่อ (เว้นว่าง = ไม่มีกำหนด)</label>
                <input type="datetime-local" class="form-control" id="smExpires">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" id="btnSaveShare">บันทึก</button>
        </div>
    </div>
</div>
