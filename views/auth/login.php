<?php
// =====================================================
// views/auth/login.php
// Sign in, register and the two-factor step, on one page. The right-hand side
// is today's date block, large: it stands where an illustration would.
// =====================================================

$outsideStyles = ['auth'];
$thaiDays = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
$thaiMonths = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$now = new DateTime('now');

require ROOT . '/views/partials/outside-head.php';
?>
    <meta name="csrf-token" content="<?= h(Csrf::token()) ?>">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="auth-page">

<div class="auth" id="auth"
     data-google-client="<?= h(GOOGLE_CLIENT_ID) ?>">

    <main class="auth-form-side">
        <header class="auth-brand">
            <span class="auth-wordmark">DayFlow</span>
            <span class="auth-tagline">พื้นที่จัดการงาน โน้ต การเงิน และกิจวัตรส่วนตัว</span>
        </header>

        <div class="auth-panel">

            <div class="tabs auth-tabs" id="authTabs" role="tablist" aria-label="ประเภทการเข้าใช้งาน">
                <button type="button" class="tab" id="tabLogin" role="tab" aria-selected="true" aria-controls="paneLogin"
                        data-act="switchTab" data-args="[&quot;login&quot;]">เข้าสู่ระบบ</button>
                <button type="button" class="tab" id="tabRegister" role="tab" aria-selected="false" aria-controls="paneRegister" tabindex="-1"
                        data-act="switchTab" data-args="[&quot;register&quot;]">สมัครสมาชิก</button>
            </div>

            <!-- ─── Sign in ─── -->
            <section id="paneLogin" role="tabpanel" aria-labelledby="tabLogin">
                <h1 class="sr-only">เข้าสู่ระบบ</h1>
                <form id="loginForm" novalidate data-act="doLogin">
                    <div class="alert alert-danger" id="loginAlert" role="alert" hidden></div>

                    <div class="form-group">
                        <label class="form-label" for="loginIdentifier">ชื่อผู้ใช้ หรืออีเมล</label>
                        <input class="form-control" type="text" id="loginIdentifier" name="identifier"
                               autocomplete="username" autocapitalize="none" spellcheck="false" autofocus>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="loginPassword">รหัสผ่าน</label>
                        <div class="pw-wrap">
                            <input class="form-control" type="password" id="loginPassword" name="password"
                                   autocomplete="current-password">
                            <button type="button" class="btn-link pw-toggle" aria-controls="loginPassword" aria-pressed="false"
                                    data-act="togglePw" data-args="[&quot;loginPassword&quot;, &quot;$el&quot;]">แสดง</button>
                        </div>
                    </div>

                    <div class="check-item">
                        <input type="checkbox" id="rememberDevice" name="remember_device">
                        <label for="rememberDevice">จดจำอุปกรณ์นี้ 30 วัน</label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg btn-block auth-submit" id="loginBtn">เข้าสู่ระบบ</button>
                </form>

                <div class="auth-or" id="loginDivider"><span>หรือ</span></div>
                <div class="auth-google" id="googleBtnLogin" data-needs-google></div>
                <a class="btn btn-lg btn-block" href="<?= h(APP_URL) ?>/demo">ลองใช้ด้วยบัญชีตัวอย่าง</a>
            </section>

            <!-- ─── Register ─── -->
            <section id="paneRegister" role="tabpanel" aria-labelledby="tabRegister" hidden>
                <h1 class="sr-only">สมัครสมาชิก</h1>
                <form id="registerForm" novalidate data-act="doRegister">
                    <div class="alert alert-danger" id="registerAlert" role="alert" hidden></div>

                    <div class="form-group">
                        <label class="form-label" for="regDisplayName">ชื่อที่แสดง</label>
                        <input class="form-control" type="text" id="regDisplayName" name="display_name"
                               maxlength="100" autocomplete="name">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="regUsername">ชื่อผู้ใช้</label>
                        <input class="form-control" type="text" id="regUsername" name="username"
                               maxlength="30" autocomplete="username" autocapitalize="none" spellcheck="false"
                               aria-describedby="usernameHelp usernameHint"
                               data-act="validateUsername" data-args="[&quot;$el&quot;]" data-on="input">
                        <div class="form-hint" id="usernameHelp">ภาษาอังกฤษ ตัวเลข หรือ _ ยาว 3–30 ตัว เช่น myname123</div>
                        <div class="form-error" id="usernameHint" hidden>ใช้ได้เฉพาะ a-z, A-Z, 0-9 และ _ และต้องยาว 3–30 ตัว</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="regEmail">อีเมล</label>
                        <input class="form-control" type="email" id="regEmail" name="email"
                               autocomplete="email" autocapitalize="none" spellcheck="false">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="regPassword">รหัสผ่าน</label>
                        <div class="pw-wrap">
                            <input class="form-control" type="password" id="regPassword" name="password"
                                   autocomplete="new-password" aria-describedby="passwordHelp"
                                   data-act="updateStrength" data-args="[&quot;$value&quot;]" data-on="input">
                            <button type="button" class="btn-link pw-toggle" aria-controls="regPassword" aria-pressed="false"
                                    data-act="togglePw" data-args="[&quot;regPassword&quot;, &quot;$el&quot;]">แสดง</button>
                        </div>
                        <div class="pw-meter" id="strengthMeter" data-level="0">
                            <span class="pw-meter-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                            <span class="pw-meter-text" id="strengthText" aria-live="polite"></span>
                        </div>
                        <div class="form-hint" id="passwordHelp">อย่างน้อย 8 ตัวอักษร ยิ่งผสมตัวพิมพ์ใหญ่ ตัวเลข และสัญลักษณ์ ยิ่งแข็งแรง</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="regConfirm">ยืนยันรหัสผ่าน</label>
                        <div class="pw-wrap">
                            <input class="form-control" type="password" id="regConfirm" name="confirm_password"
                                   autocomplete="new-password">
                            <button type="button" class="btn-link pw-toggle" aria-controls="regConfirm" aria-pressed="false"
                                    data-act="togglePw" data-args="[&quot;regConfirm&quot;, &quot;$el&quot;]">แสดง</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg btn-block auth-submit" id="registerBtn">สมัครสมาชิก</button>
                </form>

                <div class="auth-or" data-needs-google><span>หรือ</span></div>
                <div class="auth-google" id="googleBtnReg" data-needs-google></div>
            </section>

            <!-- ─── Two-factor: shown once the password (or Google) checked out ─── -->
            <section id="paneTwoFactor" hidden>
                <h1 class="auth-title">ยืนยันตัวตนสองขั้นตอน</h1>
                <p class="auth-lead">เปิดแอป Authenticator เพื่อดูรหัส 6 หลัก หรือกรอกรหัสสำรองที่เก็บไว้</p>
                <form id="twoFactorForm" novalidate data-act="doTwoFactor">
                    <div class="alert alert-danger" id="twoFactorAlert" role="alert" hidden></div>

                    <div class="form-group">
                        <label class="form-label" for="twoFactorCode">รหัสยืนยัน</label>
                        <input class="form-control auth-code" type="text" id="twoFactorCode" name="code"
                               inputmode="numeric" autocomplete="one-time-code" maxlength="11" spellcheck="false">
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg btn-block auth-submit" id="twoFactorBtn">ยืนยันรหัส</button>
                </form>
                <button type="button" class="btn-link auth-back" data-act="cancelTwoFactor">ใช้บัญชีอื่น</button>
            </section>

        </div>
    </main>

    <!-- Today, large. Decoration only: the page says nothing a screen reader needs from it. -->
    <aside class="auth-day" aria-hidden="true">
        <span class="auth-day-name">วัน<?= h($thaiDays[(int)$now->format('w')]) ?></span>
        <span class="auth-day-number"><?= (int)$now->format('j') ?></span>
        <span class="auth-day-month"><?= h($thaiMonths[(int)$now->format('n')]) ?> <?= (int)$now->format('Y') + 543 ?></span>
    </aside>

</div>

<script src="<?= APP_URL ?>/assets/js/auth.js?v=<?= @filemtime(PUBLIC_ROOT . '/assets/js/auth.js') ?>"></script>
<!-- The data-act dispatcher: without it none of the buttons above do anything. -->
<script src="<?= APP_URL ?>/assets/js/actions.js?v=<?= @filemtime(PUBLIC_ROOT . '/assets/js/actions.js') ?>"></script>
</body>
</html>
