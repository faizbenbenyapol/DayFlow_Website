<?php
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
?>
<div class="page-header">
    <h1 class="page-title">ตั้งค่า</h1>
</div>

<!-- Tabs -->
<div class="flex gap-3 mb-8 settings-tabs" id="settingsTabs" style="flex-wrap:wrap">
    <button class="btn btn-primary btn-sm settings-tab active" data-tab="profile">โปรไฟล์</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="password">รหัสผ่าน</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="appearance">ธีม &amp; เขตเวลา</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="menus">จัดการเมนู</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="dashboard-config">ปรับแต่งแดชบอร์ด</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="account">ข้อมูลบัญชี</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="categories">หมวดหมู่</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="stock-api">API หุ้น</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="app-shares">แชร์เมนู</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="shares">ไฟล์ที่แชร์</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="data">ข้อมูล</button>
    <button class="btn btn-ghost btn-sm settings-tab" data-tab="danger">เขตอันตราย</button>
</div>

<!-- PROFILE -->
<div id="tab-profile" class="settings-pane">
    <div class="card" style="max-width:540px">
        <div class="card-header"><span class="card-title">ข้อมูลโปรไฟล์</span></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">ชื่อที่แสดง</label>
                <input type="text" class="form-control" id="profileName"
                       value="<?= h($user['display_name'] ?? '') ?>" maxlength="100">
            </div>
            <div class="form-group">
                <label class="form-label">อีเมล</label>
                <input type="email" class="form-control" id="profileEmail"
                       value="<?= h($user['email'] ?? '') ?>" maxlength="150">
            </div>
            <div class="form-group">
                <label class="form-label">ชื่อผู้ใช้งาน</label>
                <input type="text" class="form-control" value="<?= h($user['username'] ?? '') ?>" disabled>
                <p class="form-hint">ไม่สามารถเปลี่ยนชื่อผู้ใช้งานได้</p>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--color-border)">
            <button class="btn btn-primary" id="btnSaveProfile">บันทึก</button>
        </div>
    </div>
</div>

<!-- PASSWORD -->
<div id="tab-password" class="settings-pane" style="display:none">
    <div class="card" style="max-width:540px">
        <div class="card-header"><span class="card-title">เปลี่ยนรหัสผ่าน</span></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">รหัสผ่านปัจจุบัน</label>
                <div class="pw-field">
                    <input type="password" class="form-control" id="pwCurrent" autocomplete="current-password">
                    <button type="button" class="pw-toggle" data-target="pwCurrent" aria-label="แสดง">แสดง</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">รหัสผ่านใหม่</label>
                <div class="pw-field">
                    <input type="password" class="form-control" id="pwNew" autocomplete="new-password" minlength="8">
                    <button type="button" class="pw-toggle" data-target="pwNew" aria-label="แสดง">แสดง</button>
                </div>
                <div class="pw-strength" id="pwStrength">
                    <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwStrengthFill"></div></div>
                    <div class="pw-strength-text text-xs text-muted" id="pwStrengthText">อย่างน้อย 8 ตัวอักษร</div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                <div class="pw-field">
                    <input type="password" class="form-control" id="pwConfirm" autocomplete="new-password">
                    <button type="button" class="pw-toggle" data-target="pwConfirm" aria-label="แสดง">แสดง</button>
                </div>
                <p class="form-hint" id="pwMatchHint"></p>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--color-border)">
            <button class="btn btn-primary" id="btnChangePassword">เปลี่ยนรหัสผ่าน</button>
        </div>
    </div>
</div>

<!-- APPEARANCE + TIMEZONE -->
<div id="tab-appearance" class="settings-pane" style="display:none">
    <div class="card mb-6" style="max-width:540px">
        <div class="card-header"><span class="card-title">ธีม</span></div>
        <div class="card-body">
            <div class="flex gap-4 theme-cards-container" style="flex-wrap:wrap">
                <label class="theme-card-option" style="cursor:pointer; min-width:140px;">
                    <input type="radio" name="theme" value="light"
                           <?= ($settings['theme'] ?? 'light') === 'light' ? 'checked' : '' ?>
                           class="sr-only">
                    <div class="theme-card-preview theme-light-preview">
                        <div class="preview-header"></div>
                        <div class="preview-body">
                            <div class="preview-line-1"></div>
                            <div class="preview-line-2"></div>
                        </div>
                    </div>
                    <span class="theme-card-label">
                        <span class="theme-card-dot"></span>
                        สว่าง (Light Mode)
                    </span>
                </label>
                <label class="theme-card-option" style="cursor:pointer; min-width:140px;">
                    <input type="radio" name="theme" value="dark"
                           <?= ($settings['theme'] ?? 'light') === 'dark' ? 'checked' : '' ?>
                           class="sr-only">
                    <div class="theme-card-preview theme-dark-preview">
                        <div class="preview-header"></div>
                        <div class="preview-body">
                            <div class="preview-line-1"></div>
                            <div class="preview-line-2"></div>
                        </div>
                    </div>
                    <span class="theme-card-label">
                        <span class="theme-card-dot"></span>
                        มืด (Dark Mode)
                    </span>
                </label>
                <label class="theme-card-option" style="cursor:pointer; min-width:140px;">
                    <input type="radio" name="theme" value="soft"
                           <?= ($settings['theme'] ?? 'light') === 'soft' ? 'checked' : '' ?>
                           class="sr-only">
                    <div class="theme-card-preview theme-soft-preview">
                        <div class="preview-header"></div>
                        <div class="preview-body">
                            <div class="preview-line-1"></div>
                            <div class="preview-line-2"></div>
                        </div>
                    </div>
                    <span class="theme-card-label">
                        <span class="theme-card-dot"></span>
                        พาสเทลครีม (Pastel Soft)
                    </span>
                </label>
                <label class="theme-card-option" style="cursor:pointer; min-width:140px;">
                    <input type="radio" name="theme" value="lavender"
                           <?= ($settings['theme'] ?? 'light') === 'lavender' ? 'checked' : '' ?>
                           class="sr-only">
                    <div class="theme-card-preview theme-lavender-preview">
                        <div class="preview-header"></div>
                        <div class="preview-body">
                            <div class="preview-line-1"></div>
                            <div class="preview-line-2"></div>
                        </div>
                    </div>
                    <span class="theme-card-label">
                        <span class="theme-card-dot"></span>
                        พาสเทลม่วง (Lavender)
                    </span>
                </label>
                <label class="theme-card-option" style="cursor:pointer; min-width:140px;">
                    <input type="radio" name="theme" value="ocean"
                           <?= ($settings['theme'] ?? 'light') === 'ocean' ? 'checked' : '' ?>
                           class="sr-only">
                    <div class="theme-card-preview theme-ocean-preview">
                        <div class="preview-header"></div>
                        <div class="preview-body">
                            <div class="preview-line-1"></div>
                            <div class="preview-line-2"></div>
                        </div>
                    </div>
                    <span class="theme-card-label">
                        <span class="theme-card-dot"></span>
                        พาสเทลฟ้าน้ำทะเล (Mint)
                    </span>
                </label>
                <label class="theme-card-option" style="cursor:pointer; min-width:140px;">
                    <input type="radio" name="theme" value="peach"
                           <?= ($settings['theme'] ?? 'light') === 'peach' ? 'checked' : '' ?>
                           class="sr-only">
                    <div class="theme-card-preview theme-peach-preview">
                        <div class="preview-header"></div>
                        <div class="preview-body">
                            <div class="preview-line-1"></div>
                            <div class="preview-line-2"></div>
                        </div>
                    </div>
                    <span class="theme-card-label">
                        <span class="theme-card-dot"></span>
                        พาสเทลชมพูพีช (Rose)
                    </span>
                </label>
            </div>
        </div>
    </div>

    <div class="card" style="max-width:540px">
        <div class="card-header"><span class="card-title">เขตเวลา</span></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">เขตเวลาที่ใช้แสดงผล</label>
                <select class="form-control" id="timezoneSelect">
                    <?php foreach ($timezones as $tz => $label): ?>
                        <option value="<?= h($tz) ?>" <?= $tz === $currentTz ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">ปัจจุบัน: <span id="tzCurrentTime">—</span></p>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--color-border)">
            <button class="btn btn-primary" id="btnSaveTimezone">บันทึกเขตเวลา</button>
        </div>
    </div>
</div>

<!-- MANAGE MENUS -->
<div id="tab-menus" class="settings-pane" style="display:none">
    <div class="card" style="max-width:540px">
        <div class="card-header"><span class="card-title">การแสดงผลเมนู</span></div>
        <div class="card-body">
            <p class="form-hint mb-4">เลือกเมนูที่คุณต้องการให้แสดงในหน้าหลักและแถบเมนูด้านข้าง เมนูที่ไม่ได้เลือกจะถูกซ่อนไว้ชั่วคราว</p>
            
            <?php
            $hiddenMenus = !empty($settings['hidden_menus']) ? json_decode($settings['hidden_menus'], true) : [];
            if (!is_array($hiddenMenus)) {
                $hiddenMenus = [];
            }
            $isMenuVisible = function(string $menu) use ($hiddenMenus) {
                return !in_array($menu, $hiddenMenus);
            };
            ?>
            <div style="display:flex; flex-direction:column; gap:12px;" id="menuVisibilityList">
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="tasks" style="width:18px; height:18px;" <?= $isMenuVisible('tasks') ? 'checked' : '' ?>>
                    <span>งาน (Tasks)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="notes" style="width:18px; height:18px;" <?= $isMenuVisible('notes') ? 'checked' : '' ?>>
                    <span>โน้ต (Notes)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="planner" style="width:18px; height:18px;" <?= $isMenuVisible('planner') ? 'checked' : '' ?>>
                    <span>แพลนเนอร์ (Planner)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="exercise" style="width:18px; height:18px;" <?= $isMenuVisible('exercise') ? 'checked' : '' ?>>
                    <span>ออกกำลังกาย (Workout)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="food-notes" style="width:18px; height:18px;" <?= $isMenuVisible('food-notes') ? 'checked' : '' ?>>
                    <span>อาหาร-เครื่องดื่ม (Food Notes)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="finance" style="width:18px; height:18px;" <?= $isMenuVisible('finance') ? 'checked' : '' ?>>
                    <span>การเงิน (Finance)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="subscriptions" style="width:18px; height:18px;" <?= $isMenuVisible('subscriptions') ? 'checked' : '' ?>>
                    <span>การแจ้งเตือน (Subscriptions)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="stocks" style="width:18px; height:18px;" <?= $isMenuVisible('stocks') ? 'checked' : '' ?>>
                    <span>ระบบหุ้น (Stocks)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="ai" style="width:18px; height:18px;" <?= $isMenuVisible('ai') ? 'checked' : '' ?>>
                    <span>ผู้ช่วยอัจฉริยะ (AI Helper)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="file-tools" style="width:18px; height:18px;" <?= $isMenuVisible('file-tools') ? 'checked' : '' ?>>
                    <span>เครื่องมือจัดการไฟล์ (File Tools)</span>
                </label>
                <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                    <input type="checkbox" name="visible_menus[]" value="files" style="width:18px; height:18px;" <?= $isMenuVisible('files') ? 'checked' : '' ?>>
                    <span>ไฟล์ (Files)</span>
                </label>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--color-border)">
            <button class="btn btn-primary" id="btnSaveMenus">บันทึกการตั้งค่าเมนู</button>
        </div>
    </div>
</div>

<!-- DASHBOARD CONFIGURATION -->
<div id="tab-dashboard-config" class="settings-pane" style="display:none">
    <div class="card" style="max-width:540px">
        <div class="card-header"><span class="card-title">ปรับแต่งหน้าแดชบอร์ด</span></div>
        <div class="card-body">
            <p class="form-hint mb-4">เลือกวิดเจ็ตที่คุณต้องการให้แสดงบนหน้าแดชบอร์ดส่วนตัว คุณสามารถจัดเรียงลำดับวิดเจ็ตได้โดยการลากย้ายบล็อกวิดเจ็ตบนหน้าแดชบอร์ดโดยตรง</p>
            
            <form id="customizeDashboardForm">
                <div style="display:flex; flex-direction:column; gap:12px;" id="dashboardWidgetsList">
                    <?php
                    $widgetLabels = [
                        'tasks' => 'งานใกล้ครบกำหนด (Tasks)',
                        'calendar' => 'กำหนดการวันนี้ (Planner)',
                        'finance' => 'การเงินเดือนนี้ (Finance)',
                        'workout' => 'ออกกำลังกายล่าสุด (Workout)',
                        'subscriptions' => 'การแจ้งเตือนที่ใกล้ถึง (Subscriptions)',
                        'projects' => 'โปรเจคล่าสุด (Projects)',
                        'notes' => 'โน้ตล่าสุด (Notes)',
                        'stocks' => 'หุ้นและพอร์ตโฟลิโอ (Stocks)',
                    ];
                    
                    foreach ($layout as $widget):
                        $key = $widget['widget_key'];
                        $label = $widgetLabels[$key] ?? $key;
                        $checked = $widget['is_visible'] ? 'checked' : '';
                    ?>
                        <label class="flex items-center gap-3" style="cursor:pointer; font-weight:500; padding:4px 0;">
                            <input type="checkbox" name="widget_<?= h($key) ?>" id="chk_<?= h($key) ?>" value="1" style="width:18px; height:18px;" <?= $checked ?>>
                            <span><?= h($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--color-border)">
            <button class="btn btn-ghost btn-sm" id="btnResetDashboardLayout">รีเซ็ตค่าเริ่มต้น</button>
            <button class="btn btn-primary" id="btnSaveDashboardCustomization">บันทึกตั้งค่า</button>
        </div>
    </div>
</div>

<script>
window.dashboardLayout = <?= json_encode($layout, JSON_UNESCAPED_UNICODE) ?>;
</script>

<!-- ACCOUNT INFO -->
<div id="tab-account" class="settings-pane" style="display:none">
    <div class="card" style="max-width:540px">
        <div class="card-header"><span class="card-title">ข้อมูลบัญชี</span></div>
        <div class="card-body">
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
                <dd><?php
                    $themeVal = $settings['theme'] ?? 'light';
                    if ($themeVal === 'dark') echo 'Dark Mode';
                    elseif ($themeVal === 'soft') echo 'Pastel Soft';
                    elseif ($themeVal === 'lavender') echo 'Pastel Lavender';
                    elseif ($themeVal === 'ocean') echo 'Pastel Mint/Ocean';
                    elseif ($themeVal === 'peach') echo 'Pastel Rose/Peach';
                    else echo 'Light Mode';
                ?></dd>

                <dt>สมัครเมื่อ</dt>
                <dd>
                    <?php if (!empty($user['created_at'])): ?>
                        <?= h(date('d/m/Y H:i', strtotime($user['created_at']))) ?>
                        <span class="text-xs text-muted">
                            (<?php
                                $days = max(0, floor((time() - strtotime($user['created_at'])) / 86400));
                                echo $days . ' วันที่แล้ว';
                            ?>)
                        </span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </dd>

                <dt>รหัสบัญชี</dt>
                <dd><code>#<?= (int)($user['id'] ?? 0) ?></code></dd>
            </dl>
        </div>
    </div>
</div>

<!-- CATEGORIES -->
<div id="tab-categories" class="settings-pane" style="display:none">

    <!-- Finance categories -->
    <div class="card mb-6" style="max-width:720px">
        <div class="card-header">
            <span class="card-title">หมวดหมู่การเงิน</span>
        </div>
        <div class="card-body">
            <p class="form-hint mb-4">จัดการหมวดหมู่รายรับ/รายจ่ายที่ใช้ในเมนู "การเงิน"</p>

            <form id="finCatForm" class="cat-add-row" onsubmit="event.preventDefault();" style="display:flex;gap:var(--space-2);margin-bottom:var(--space-4);flex-wrap:wrap;width:100%">
                <input type="text" class="form-control" id="finCatNewName" placeholder="ชื่อหมวดหมู่ใหม่..." style="flex:1;min-width:180px">
                <select class="form-control" id="finCatNewType" style="width:140px">
                    <option value="expense">รายจ่าย</option>
                    <option value="income">รายรับ</option>
                </select>
                <button class="btn btn-primary" id="btnFinCatAdd" type="submit">+ เพิ่ม</button>
            </form>

            <div class="cat-group">
                <div class="cat-group-title text-sm text-muted mb-2">รายรับ</div>
                <ul class="cat-list" id="finCatListIncome"></ul>
            </div>
            <div class="cat-group" style="margin-top:var(--space-4)">
                <div class="cat-group-title text-sm text-muted mb-2">รายจ่าย</div>
                <ul class="cat-list" id="finCatListExpense"></ul>
            </div>
        </div>
    </div>

    <!-- Exercise categories -->
    <div class="card mb-6" style="max-width:720px">
        <div class="card-header">
            <span class="card-title">หมวดหมู่การออกกำลังกาย</span>
        </div>
        <div class="card-body">
            <p class="form-hint mb-4">จัดการประเภท/หมวดหมู่การออกกำลังกายที่จะแสดงให้เลือกตอนบันทึก</p>

            <form id="exCatForm" class="cat-add-row" onsubmit="event.preventDefault();" style="display:flex;gap:var(--space-2);margin-bottom:var(--space-4);flex-wrap:wrap;width:100%">
                <input type="text" class="form-control" id="exCatNewName" placeholder="ชื่อหมวดหมู่การออกกำลังกายใหม่..." style="flex:1;min-width:180px">
                <button class="btn btn-primary" id="btnExCatAdd" type="submit">+ เพิ่ม</button>
            </form>

            <ul class="cat-list" id="exCatList"></ul>
        </div>
    </div>

    <!-- Note tags -->
    <div class="card" style="max-width:720px">
        <div class="card-header">
            <span class="card-title">แท็กของโน้ต</span>
        </div>
        <div class="card-body">
            <p class="form-hint mb-4">จัดการแท็กที่ใช้จัดกลุ่มโน้ต</p>

            <form id="noteTagForm" class="cat-add-row" onsubmit="event.preventDefault();" style="display:flex;gap:var(--space-2);margin-bottom:var(--space-4);flex-wrap:wrap;width:100%">
                <input type="text" class="form-control" id="noteTagNewName" placeholder="ชื่อแท็กใหม่..." style="flex:1;min-width:180px">
                <button class="btn btn-primary" id="btnNoteTagAdd" type="submit">+ เพิ่ม</button>
            </form>

            <ul class="cat-list" id="noteTagList"></ul>
        </div>
    </div>

</div>

<!-- STOCK API KEYS -->
<div id="tab-stock-api" class="settings-pane" style="display:none">
    <div class="card" style="max-width:720px">
        <div class="card-header"><span class="card-title">API สำหรับราคาหุ้น</span></div>
        <div class="card-body">
            <p class="form-hint">เชื่อมต่อกับผู้ให้บริการเพื่อดึงราคาปัจจุบันมาคำนวณกำไร/ขาดทุนในหน้า "หุ้น". ใช้ฟรีได้ตาม quota ของแต่ละเจ้า — ลงทะเบียนแล้วนำ API key มาใส่</p>
            <div id="stockKeysList">
                <div class="text-muted text-sm">กำลังโหลด...</div>
            </div>
            <div class="text-xs text-muted" style="margin-top:var(--space-3)">
                <strong>คำแนะนำ:</strong><br>
                • Finnhub (<a href="https://finnhub.io/register" target="_blank" rel="noopener">finnhub.io</a>) — 60 req/min รองรับ US + SET (`.BK`)<br>
                • Alpha Vantage (<a href="https://www.alphavantage.co/support/#api-key" target="_blank" rel="noopener">alphavantage.co</a>) — 25 req/day<br>
                • Twelve Data (<a href="https://twelvedata.com/register" target="_blank" rel="noopener">twelvedata.com</a>) — 800 req/day
            </div>
        </div>
    </div>

    <div class="card" style="max-width:720px;margin-top:24px">
        <div class="card-header"><span class="card-title">API สำหรับการวิเคราะห์หุ้นด้วย AI</span></div>
        <div class="card-body">
            <p class="form-hint">ตั้งค่า API Key ของผู้ให้บริการ AI เพื่อใช้งานระบบวิเคราะห์หุ้นเชิงลึก (แนะนำใช้ Gemini 2.0 Flash ซึ่งประมวลผลได้รวดเร็วและเป็นประโยชน์ที่สุด)</p>
            <div id="stockAiKeysList">
                <div class="text-muted text-sm">กำลังโหลด...</div>
            </div>
            <div class="text-xs text-muted" style="margin-top:var(--space-3)">
                <strong>คำแนะนำสมัครใช้งาน API Key:</strong><br>
                • Google Gemini (<a href="https://aistudio.google.com/" target="_blank" rel="noopener">Google AI Studio</a>) — สมัครและใช้งาน API ฟรี รองรับโมเดล Gemini 2.0 Flash<br>
                • OpenAI (<a href="https://platform.openai.com/" target="_blank" rel="noopener">platform.openai.com</a>) — มีค่าบริการตามปริมาณการใช้งานจริง<br>
                • Anthropic Claude (<a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>) — บริการระดับพรีเมียม รองรับ Claude 3.5 Sonnet<br>
                • Moonshot Kimi AI (<a href="https://platform.moonshot.cn/" target="_blank" rel="noopener">platform.moonshot.cn</a>) — บริการยอดนิยม รองรับโมเดล Kimi API (moonshot-v1)<br>
                • OpenRouter (<a href="https://openrouter.ai/" target="_blank" rel="noopener">openrouter.ai</a>) — บริการรวมโมเดล AI ค่ายดัง เช่น Gemini, Claude, GPT ใน API เดียว
            </div>
        </div>
    </div>
</div>

<!-- DATA -->
<div id="tab-data" class="settings-pane" style="display:none">
    <div class="card mb-6" style="max-width:540px">
        <div class="card-header"><span class="card-title">ส่งออกข้อมูล</span></div>
        <div class="card-body">
            <p class="form-hint">ดาวน์โหลดข้อมูลทั้งหมดของคุณ (งาน, โน้ต, แพลนเนอร์, การเงิน, การออกกำลังกาย ฯลฯ) เป็นไฟล์ JSON สามารถเก็บไว้เป็นสำรองได้</p>
            <a class="btn btn-primary" href="<?= APP_URL ?>/api/settings/export" download>ดาวน์โหลด JSON</a>
        </div>
    </div>

    <div class="card mb-6" style="max-width:540px">
        <div class="card-header"><span class="card-title">นำเข้าข้อมูล</span></div>
        <div class="card-body">
            <p class="form-hint">อัปโหลดไฟล์ข้อมูลสำรอง JSON ที่บันทึกไว้เพื่อนำกลับมาใช้ใหม่ <span class="text-xs" style="color:var(--color-danger);font-weight:600">คำเตือน: การนำเข้าจะเขียนทับและทดแทนข้อมูลชุดปัจจุบันทั้งหมดในระบบ</span></p>
            
            <div class="settings-import-zone" id="settingsImportZone" onclick="document.getElementById('importFile').click()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--color-muted)"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <div class="settings-import-text" id="importFileNameText">คลิกเพื่อเลือกไฟล์ข้อมูลสำรอง (.json)</div>
            </div>
            
            <input type="file" id="importFile" accept=".json" style="display:none">
            
            <div style="margin-top:16px; display:flex; justify-content:flex-end">
                <button class="btn btn-primary" id="btnImportData" disabled>เริ่มการนำเข้าข้อมูล</button>
            </div>
        </div>
    </div>

    <div class="card" style="max-width:540px">
        <div class="card-header"><span class="card-title">ข้อมูลในเครื่อง (Local Storage)</span></div>
        <div class="card-body">
            <p class="form-hint">ข้อมูลที่เก็บในเบราว์เซอร์นี้: ประวัติการคำนวณ, แท็บที่เปิดล่าสุด ฯลฯ การลบจะไม่กระทบข้อมูลบนเซิร์ฟเวอร์</p>
            <div id="localStorageInfo" class="text-xs text-muted mb-4">—</div>
            <button class="btn btn-ghost" id="btnClearLocal">ล้างข้อมูลในเบราว์เซอร์</button>
        </div>
    </div>
</div>

<!-- DANGER ZONE -->
<div id="tab-danger" class="settings-pane" style="display:none">
    <div class="card danger-card" style="max-width:540px">
        <div class="card-header"><span class="card-title" style="color:var(--color-danger)">ลบบัญชีถาวร</span></div>
        <div class="card-body">
            <p class="form-hint">การลบบัญชีจะลบข้อมูลทั้งหมดของคุณออกจากระบบอย่างถาวร — ไม่สามารถกู้คืนได้ ขอแนะนำให้ดาวน์โหลดข้อมูลก่อน</p>
            <div class="form-group">
                <label class="form-label">พิมพ์ <code>DELETE</code> เพื่อยืนยัน</label>
                <input type="text" class="form-control" id="delConfirm" placeholder="DELETE">
            </div>
            <div class="form-group">
                <label class="form-label">รหัสผ่านปัจจุบัน</label>
                <input type="password" class="form-control" id="delPassword" autocomplete="current-password">
            </div>
            <button class="btn btn-danger" id="btnDeleteAccount" disabled>ลบบัญชีถาวร</button>
        </div>
    </div>
</div>


<!-- APP SHARES (MENU) -->
<div id="tab-app-shares" class="settings-pane" style="display:none">
    <div class="card mb-6" style="max-width:900px">
        <div class="card-header"><span class="card-title">สร้างลิงก์แชร์เมนูใหม่</span></div>
        <div class="card-body">
            <p class="form-hint mb-4">ลิงก์นี้อนุญาตให้บุคคลภายนอกดูข้อมูลในเมนูที่คุณเลือกได้แบบเรียลไทม์ (อ่านได้อย่างเดียว)</p>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ชื่อลิงก์ (สำหรับจำ)</label>
                    <input type="text" class="form-control" id="asNewLabel" placeholder="เช่น ให้ทีมงานดูความคืบหน้า">
                </div>
                <div class="form-group">
                    <label class="form-label">วันหมดอายุ (ไม่บังคับ)</label>
                    <input type="datetime-local" class="form-control" id="asNewExpires">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">เลือกเมนูที่ต้องการแชร์</label>
                <div style="display:flex; flex-wrap:wrap; gap:16px; margin-top:8px;">
                    <label class="flex items-center gap-2"><input type="checkbox" name="as_menus[]" value="tasks"> งาน</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="as_menus[]" value="notes"> โน้ต</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="as_menus[]" value="planner"> แพลนเนอร์</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="as_menus[]" value="exercise"> ออกกำลังกาย</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="as_menus[]" value="food-notes"> อาหาร-เครื่องดื่ม</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="as_menus[]" value="finance"> การเงิน</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="as_menus[]" value="subscriptions"> แจ้งเตือน</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="as_menus[]" value="stocks"> หุ้น</label>
                </div>
            </div>
            <button class="btn btn-primary" id="btnCreateAppShare">สร้างลิงก์แชร์เมนู</button>
        </div>
    </div>

    <div class="card" style="max-width:900px">
        <div class="card-header"><span class="card-title">ลิงก์แชร์เมนูของคุณ</span></div>
        <div class="card-body">
            <div class="shares-table-wrap">
                <table class="shares-table">
                    <thead>
                        <tr>
                            <th>ชื่อลิงก์</th>
                            <th>เมนูที่แชร์</th>
                            <th>ลิงก์</th>
                            <th>หมดอายุ</th>
                            <th>การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="appSharesTableBody">
                        <tr><td colspan="5" class="text-muted text-sm" style="padding:1rem">กำลังโหลด...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- FILE SHARES -->
<div id="tab-shares" class="settings-pane" style="display:none">
    <div class="card" style="max-width:900px">
        <div class="card-header"><span class="card-title">ลิงก์แชร์ไฟล์และโฟลเดอร์ของคุณ</span></div>
        <div class="card-body">
            <p class="form-hint mb-4">ลิงก์เหล่านี้แชร์ไฟล์หรือโฟลเดอร์จากระบบจัดการไฟล์ สามารถเลือกให้ดูอย่างเดียวหรือดาวน์โหลดได้</p>
            <div class="shares-table-wrap">
                <table class="shares-table">
                    <thead>
                        <tr>
                            <th>ชื่อไฟล์/โฟลเดอร์</th>
                            <th>ลิงก์แชร์</th>
                            <th>สิทธิ์การเข้าถึง</th>
                            <th>หมดอายุ</th>
                            <th>การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="sharesTableBody">
                        <tr><td colspan="5" class="text-muted text-sm" style="padding:1rem">กำลังโหลด...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Share Modal (edit only, no file picker) -->
<div class="share-modal-overlay" id="shareModalOverlay" style="display:none">
    <div class="share-modal-box" id="shareModal" data-selected-file-id="">
        <div class="share-modal-header">
            <span class="share-modal-title">แก้ไขลิงก์แชร์</span>
            <button class="share-modal-close" id="btnCloseShareModal" aria-label="ปิด">&times;</button>
        </div>
        <div class="share-modal-body">
            <div class="form-group">
                <label class="form-label">ชื่อลิงก์ (สำหรับจำ)</label>
                <input type="text" class="form-control" id="smLabel" placeholder="เช่น ส่งให้เพื่อน, งานนำเสนอ">
            </div>
            <div class="form-group">
                <label class="form-label">สิทธิ์การเข้าถึง</label>
                <select class="form-control" id="smPermission">
                    <option value="view">ดูอย่างเดียว</option>
                    <option value="download">ดาวน์โหลดได้</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">วันหมดอายุ <span class="text-muted text-xs">(ไม่กรอก = ไม่มีกำหนด)</span></label>
                <input type="datetime-local" class="form-control" id="smExpires">
            </div>
        </div>
        <div class="share-modal-footer">
            <button class="btn btn-ghost" onclick="document.getElementById('shareModalOverlay').style.display='none'">ยกเลิก</button>
            <button class="btn btn-primary" id="btnSaveShare">บันทึก</button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    const $ = s => document.querySelector(s);
    const $$ = s => Array.from(document.querySelectorAll(s));

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtDate(d) {
        if (!d) return null;
        return new Date(d).toLocaleString('th-TH');
    }
    function isExpired(d) {
        if (!d) return false;
        return new Date(d) < new Date();
    }

    async function loadAppShares() {
        const tbody = $('#appSharesTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-sm" style="padding:1rem">กำลังโหลด...</td></tr>';
        try {
            const res = await apiFetch(BASE_URL + '/api/app-shares');
            const shares = res.shares || [];
            if (!shares.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-sm" style="padding:1rem;text-align:center">ยังไม่มีลิงก์แชร์เมนู</td></tr>';
                return;
            }
            tbody.innerHTML = shares.map(s => {
                const link = BASE_URL + '/shared/' + s.token;
                const expired = isExpired(s.expires_at);
                const exp = s.expires_at
                    ? `<span class="${expired ? 'share-expired' : 'share-no-expiry'}">${expired ? 'หมดอายุแล้ว' : fmtDate(s.expires_at)}</span>`
                    : '<span class="share-no-expiry">ไม่มีกำหนด</span>';
                
                const menuNames = {
                    'tasks': 'งาน', 'notes': 'โน้ต', 'planner': 'แพลนเนอร์',
                    'exercise': 'ออกกำลังกาย', 'food-notes': 'อาหาร',
                    'finance': 'การเงิน', 'subscriptions': 'แจ้งเตือน', 'stocks': 'หุ้น'
                };
                const mLabels = (s.menus || []).map(m => menuNames[m] || m).join(', ');

                return `<tr>
                    <td><div style="font-weight:500">${esc(s.label)}</div></td>
                    <td><span style="font-size:0.85rem;color:var(--color-muted)">${esc(mLabels)}</span></td>
                    <td><a class="share-link-url" href="${esc(link)}" target="_blank" rel="noopener">${esc(link)}</a></td>
                    <td>${exp}</td>
                    <td>
                        <div class="share-actions">
                            <button class="btn btn-ghost btn-sm btn-copy-app" data-link="${esc(link)}" title="คัดลอกลิงก์">คัดลอก</button>
                            <button class="btn btn-ghost btn-sm btn-del-app" style="color:var(--color-danger)" data-id="${s.id}">ลบ</button>
                        </div>
                    </td>
                </tr>`;
            }).join('');
            
            $$('.btn-copy-app').forEach(btn => btn.addEventListener('click', () => {
                navigator.clipboard?.writeText(btn.dataset.link).then(() => toast('คัดลอกแล้ว')).catch(() => toast('คัดลอกไม่สำเร็จ', 'danger'));
            }));
            $$('.btn-del-app').forEach(btn => btn.addEventListener('click', async () => {
                if (!await confirmAction('ลบลิงก์แชร์นี้?', 'ลบ')) return;
                try {
                    await apiFetch(BASE_URL + '/api/app-shares/' + btn.dataset.id, { method: 'DELETE' });
                    toast('ลบแล้ว');
                    loadAppShares();
                } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
            }));
        } catch { toast('โหลดรายการแชร์ไม่สำเร็จ', 'danger'); }
    }

    async function createAppShare() {
        const label = $('#asNewLabel').value.trim();
        const expires_at = $('#asNewExpires').value;
        const menus = $$('input[name="as_menus[]"]:checked').map(cb => cb.value);

        if (!label) { toast('กรุณาระบุชื่อลิงก์', 'danger'); return; }
        if (!menus.length) { toast('กรุณาเลือกอย่างน้อย 1 เมนู', 'danger'); return; }

        try {
            await apiFetch(BASE_URL + '/api/app-shares', {
                method: 'POST',
                body: JSON.stringify({ label, expires_at: expires_at || null, menus })
            });
            $('#asNewLabel').value = '';
            $('#asNewExpires').value = '';
            $$('input[name="as_menus[]"]').forEach(cb => cb.checked = false);
            toast('สร้างลิงก์แล้ว');
            loadAppShares();
        } catch (err) { toast(err.message || 'สร้างไม่สำเร็จ', 'danger'); }
    }

    document.addEventListener('DOMContentLoaded', () => {
        $('#btnCreateAppShare')?.addEventListener('click', createAppShare);
        
        let loaded = false;
        $$('.settings-tab').forEach(t => t.addEventListener('click', () => {
            if (t.dataset.tab === 'app-shares' && !loaded) {
                loaded = true;
                loadAppShares();
            }
        }));
        if ($('#tab-app-shares')?.style.display !== 'none') {
            loaded = true;
            loadAppShares();
        }
    });
})();
</script>
