<!DOCTYPE html>
<html lang="th" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <meta name="csrf-token" content="<?= h(Csrf::token()) ?>">
    <title><?= h($pageTitle ?? 'เข้าสู่ระบบ') ?> — <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #eef2f7;
            --surface:  #eef2f7;
            --border:   transparent;
            --border-2: transparent;
            --text:     #1e293b;
            --muted:    #64748b;
            --muted-2:  #94a3b8;
            --accent:   #0f172a;
            --danger:   #ef4444;
            --success:  #22c55e;
            --font:     'Sarabun', 'Noto Sans Thai', -apple-system, sans-serif;
            --radius:   12px;
            --shadow:   6px 6px 12px rgba(163, 177, 198, 0.6), -6px -6px 12px rgba(255, 255, 255, 0.8);
            --shadow-recessed: inset 3px 3px 6px rgba(163, 177, 198, 0.6), inset -3px -3px 6px rgba(255, 255, 255, 0.8);
            --shadow-sm: 2px 2px 5px rgba(163, 177, 198, 0.4), -2px -2px 5px rgba(255, 255, 255, 0.7);
        }

        html { font-size: 15px; -webkit-text-size-adjust: 100%; }

        body {
            font-family: var(--font);
            background: var(--bg);
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            color: var(--text);
            line-height: 1.6;
        }

        /* ── Card ── */
        .auth-card {
            background: var(--surface);
            border: none;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
            padding: 1rem 0;
        }

        /* ── Brand header ── */
        .auth-brand {
            padding: 2.2rem 2.2rem 0;
            text-align: center;
        }

        .auth-brand-name {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--text);
        }

        .auth-brand-sub {
            font-size: 0.8rem;
            color: var(--muted-2);
            margin-top: 0.2rem;
        }

        /* ── Tabs ── */
        .auth-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            margin: 1.8rem 2.2rem 0;
            border: none;
            border-radius: 12px;
            overflow: hidden;
            background: var(--bg);
            padding: 4px;
            gap: 4px;
            box-shadow: var(--shadow-recessed);
        }

        .auth-tab {
            padding: 0.6rem;
            font-size: 0.875rem;
            font-weight: 600;
            font-family: var(--font);
            color: var(--muted);
            background: transparent;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all .2s cubic-bezier(0.34, 1.56, 0.64, 1);
            text-align: center;
        }

        .auth-tab.active {
            background: var(--surface);
            color: var(--text);
            box-shadow: var(--shadow-sm);
        }

        /* ── Forms ── */
        .auth-body {
            padding: 1.8rem 2.2rem 2.2rem;
        }

        .auth-pane { display: none; }
        .auth-pane.active { display: block; }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.4rem;
        }

        .form-control {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            background: var(--bg);
            border: none;
            border-radius: 10px;
            color: var(--text);
            font-size: 0.9rem;
            font-family: var(--font);
            line-height: 1.5;
            transition: box-shadow .2s;
            outline: none;
            box-shadow: var(--shadow-recessed);
        }

        @media (max-width: 768px) {
            .form-control {
                font-size: 16px;
                padding: 0.85rem 1.1rem;
            }
        }

        .form-control:focus {
            box-shadow: var(--shadow-recessed), 0 0 0 3px color-mix(in srgb, var(--accent) 20%, transparent);
        }

        .form-control::placeholder { color: var(--muted-2); }

        .form-hint {
            font-size: 0.75rem;
            color: var(--muted-2);
            margin-top: 0.3rem;
        }

        /* Password wrapper */
        .pw-wrap {
            position: relative;
        }

        .pw-wrap .form-control {
            padding-right: 2.8rem;
        }

        .pw-toggle {
            position: absolute;
            right: 0.7rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted-2);
            padding: 0.2rem;
            font-size: 0.85rem;
            line-height: 1;
            display: flex;
            align-items: center;
        }

        .pw-toggle:hover { color: var(--text); }

        /* Submit btn */
        .btn-submit {
            display: block;
            width: 100%;
            padding: 0.8rem;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            font-family: var(--font);
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: all .2s cubic-bezier(0.34, 1.56, 0.64, 1);
            margin-top: 1.8rem;
        }

        .btn-submit:hover {
            opacity: 0.95;
            box-shadow: 4px 4px 10px rgba(0, 0, 0, 0.15), -4px -4px 10px rgba(255, 255, 255, 0.1);
            transform: translateY(-1.5px);
        }

        .btn-submit:active {
            box-shadow: inset 3px 3px 6px rgba(0, 0, 0, 0.3);
            transform: translateY(0.5px);
        }

        .btn-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

        /* Error/success alerts */
        .auth-alert {
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 1.2rem;
            display: none;
            border: none;
            box-shadow: var(--shadow-recessed);
        }

        .auth-alert.error {
            background: #fee2e2;
            color: var(--danger);
            display: block;
        }

        .auth-alert.success {
            background: #dcfce7;
            color: var(--success);
            display: block;
        }

        /* Divider */
        .auth-divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.4rem 0;
            color: var(--muted-2);
            font-size: 0.75rem;
        }

        .auth-divider::before,
        .auth-divider::after {
            content: '';
            flex: 1;
            height: 3px;
            background: transparent;
            box-shadow: inset 1px 1px 2px rgba(163, 177, 198, 0.4), inset -1px -1px 2px rgba(255, 255, 255, 0.7);
            border-radius: 99px;
        }

        /* Footer note */
        .auth-footer-note {
            text-align: center;
            font-size: 0.8rem;
            color: var(--muted-2);
            padding: 0 2.2rem 1.6rem;
        }

        /* Strength bar */
        .pw-strength {
            height: 4px;
            background: var(--border);
            box-shadow: var(--shadow-recessed);
            border-radius: 99px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .pw-strength-bar {
            height: 100%;
            border-radius: 99px;
            transition: width .25s, background .25s;
            width: 0%;
        }

        /* Spinner */
        .btn-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            vertical-align: middle;
            margin-right: 0.4rem;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Mobile */
        @media (max-width: 480px) {
            .auth-brand   { padding: 1.8rem 1.6rem 0; }
            .auth-tabs    { margin: 1.4rem 1.6rem 0; }
            .auth-body    { padding: 1.5rem 1.6rem 1.8rem; }
            #googleBtnLogin iframe, #googleBtnReg iframe {
                max-width: 100% !important;
                margin: 0 auto;
            }
            .auth-footer-note { padding: 0 1.6rem 1.4rem; }
        }
    </style>
</head>
<body>

<div class="auth-card">

    <!-- Brand -->
    <div class="auth-brand">
        <div class="auth-brand-name">DayFlow</div>
    </div>

    <!-- Tabs -->
    <div class="auth-tabs">
        <button class="auth-tab active" id="tabLogin"    onclick="switchTab('login')">เข้าสู่ระบบ</button>
        <button class="auth-tab"        id="tabRegister" onclick="switchTab('register')">สมัครสมาชิก</button>
    </div>

    <div class="auth-body">

        <!-- ─── Login Pane ─── -->
        <div class="auth-pane active" id="paneLogin">
            <div class="auth-alert" id="loginAlert"></div>

            <div class="form-group">
                <label class="form-label">ชื่อผู้ใช้ หรือ อีเมล</label>
                <input class="form-control" type="text" id="loginIdentifier"
                       placeholder="username หรือ email@example.com"
                       autocomplete="username" autofocus>
            </div>

            <div class="form-group">
                <label class="form-label">รหัสผ่าน</label>
                <div class="pw-wrap">
                    <input class="form-control" type="password" id="loginPassword"
                           placeholder="••••••••" autocomplete="current-password">
                    <button class="pw-toggle" type="button" onclick="togglePw('loginPassword',this)" title="แสดง/ซ่อน">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button class="btn-submit" id="loginBtn" onclick="doLogin()">เข้าสู่ระบบ</button>
            <div class="auth-divider">หรือ</div>
            <div style="display: flex; justify-content: center; width: 100%;">
                <div id="googleBtnLogin" style="width: 100%;"></div>
            </div>
        </div>

        <!-- ─── Register Pane ─── -->
        <div class="auth-pane" id="paneRegister">
            <div class="auth-alert" id="registerAlert"></div>

            <div class="form-group">
                <label class="form-label">ชื่อที่แสดง</label>
                <input class="form-control" type="text" id="regDisplayName"
                       placeholder="ชื่อที่ต้องการแสดง" maxlength="100" autocomplete="name">
            </div>

            <div class="form-group">
                <label class="form-label">ชื่อผู้ใช้ <span style="color:var(--muted-2)">(ภาษาอังกฤษ)</span></label>
                <input class="form-control" type="text" id="regUsername"
                       placeholder="เช่น myname123" maxlength="30"
                       autocomplete="username" oninput="validateUsername(this)">
                <div class="form-hint" id="usernameHint"></div>
            </div>

            <div class="form-group">
                <label class="form-label">อีเมล</label>
                <input class="form-control" type="email" id="regEmail"
                       placeholder="email@example.com" autocomplete="email">
            </div>

            <div class="form-group">
                <label class="form-label">รหัสผ่าน</label>
                <div class="pw-wrap">
                    <input class="form-control" type="password" id="regPassword"
                           placeholder="อย่างน้อย 8 ตัวอักษร" autocomplete="new-password"
                           oninput="updateStrength(this.value)">
                    <button class="pw-toggle" type="button" onclick="togglePw('regPassword',this)" title="แสดง/ซ่อน">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="pw-strength"><div class="pw-strength-bar" id="strengthBar"></div></div>
            </div>

            <div class="form-group">
                <label class="form-label">ยืนยันรหัสผ่าน</label>
                <div class="pw-wrap">
                    <input class="form-control" type="password" id="regConfirm"
                           placeholder="••••••••" autocomplete="new-password">
                    <button class="pw-toggle" type="button" onclick="togglePw('regConfirm',this)" title="แสดง/ซ่อน">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button class="btn-submit" id="registerBtn" onclick="doRegister()">สมัครสมาชิก</button>
            <div class="auth-divider">หรือ</div>
            <div style="display: flex; justify-content: center; width: 100%;">
                <div id="googleBtnReg" style="width: 100%;"></div>
            </div>
        </div>

    </div><!-- /.auth-body -->

    <div class="auth-footer-note">
        v1.1.1
    </div>

</div><!-- /.auth-card -->

<script>
const BASE_URL = (function() {
    const s = document.querySelector('script');
    return window.location.origin + window.location.pathname.replace(/\/(login|register)\/?$/, '');
})();

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Tab switching ──────────────────────────────────────
function switchTab(tab) {
    document.getElementById('paneLogin').classList.toggle('active', tab === 'login');
    document.getElementById('paneRegister').classList.toggle('active', tab === 'register');
    document.getElementById('tabLogin').classList.toggle('active', tab === 'login');
    document.getElementById('tabRegister').classList.toggle('active', tab === 'register');
    clearAlerts();
    // Auto-focus first field
    setTimeout(() => {
        const first = document.querySelector('#pane' + (tab === 'login' ? 'Login' : 'Register') + ' input:not([type=hidden])');
        if (first) first.focus();
    }, 50);
}

// Auto-open register tab if URL is /register
if (window.location.pathname.includes('register')) switchTab('register');

// ── Alert helpers ──────────────────────────────────────
function showAlert(id, msg, type) {
    const el = document.getElementById(id);
    el.textContent = msg;
    el.className = 'auth-alert ' + type;
}

function clearAlerts() {
    document.querySelectorAll('.auth-alert').forEach(el => el.className = 'auth-alert');
}

// ── Password visibility toggle ─────────────────────────
function togglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    const show  = input.type === 'password';
    input.type  = show ? 'text' : 'password';
    btn.style.color = show ? '#18181b' : '';
}

// ── Password strength ──────────────────────────────────
function updateStrength(val) {
    const bar = document.getElementById('strengthBar');
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^a-zA-Z0-9]/.test(val)) score++;

    const pct   = [0, 20, 40, 65, 85, 100][Math.min(score, 5)];
    const color = score <= 1 ? '#dc2626' : score <= 2 ? '#f97316' : score <= 3 ? '#eab308' : '#16a34a';
    bar.style.width      = pct + '%';
    bar.style.background = color;
}

// ── Username validation hint ───────────────────────────
function validateUsername(input) {
    const hint = document.getElementById('usernameHint');
    const val  = input.value;
    if (!val) { hint.textContent = ''; return; }
    if (/^[a-zA-Z0-9_]{3,30}$/.test(val)) {
        hint.textContent = '';
        input.style.borderColor = '';
    } else {
        hint.textContent = 'ใช้ได้เฉพาะ a-z, A-Z, 0-9, _ และต้องมี 3-30 ตัว';
        hint.style.color = '#dc2626';
    }
}

// ── Loading state ──────────────────────────────────────
function setLoading(btnId, loading) {
    const btn = document.getElementById(btnId);
    if (loading) {
        btn.disabled = true;
        btn.innerHTML = '<span class="btn-spinner"></span>กำลังดำเนินการ...';
    } else {
        btn.disabled = false;
    }
}

// ── Enter key submit ───────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    const loginPane = document.getElementById('paneLogin');
    const regPane   = document.getElementById('paneRegister');
    if (loginPane.classList.contains('active') && document.activeElement?.closest('#paneLogin')) {
        doLogin();
    } else if (regPane.classList.contains('active') && document.activeElement?.closest('#paneRegister')) {
        doRegister();
    }
});

// ── Login ──────────────────────────────────────────────
async function doLogin() {
    clearAlerts();
    const identifier = document.getElementById('loginIdentifier').value.trim();
    const password   = document.getElementById('loginPassword').value;

    if (!identifier || !password) {
        showAlert('loginAlert', 'กรุณากรอกข้อมูลให้ครบ', 'error');
        return;
    }

    setLoading('loginBtn', true);
    try {
        const res = await fetch(BASE_URL + '/api/auth/login', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ identifier, password })
        });
        const data = await res.json();

        if (res.ok) {
            document.getElementById('loginBtn').innerHTML = '<span class="btn-spinner"></span>กำลังเข้าสู่ระบบ...';
            window.location.href = data.redirect || BASE_URL + '/';
        } else {
            showAlert('loginAlert', data.error || 'เกิดข้อผิดพลาด', 'error');
            setLoading('loginBtn', false);
            document.getElementById('loginBtn').textContent = 'เข้าสู่ระบบ';
        }
    } catch {
        showAlert('loginAlert', 'ไม่สามารถเชื่อมต่อได้ กรุณาลองใหม่', 'error');
        setLoading('loginBtn', false);
        document.getElementById('loginBtn').textContent = 'เข้าสู่ระบบ';
    }
}

// ── Register ───────────────────────────────────────────
async function doRegister() {
    clearAlerts();
    const displayName = document.getElementById('regDisplayName').value.trim();
    const username    = document.getElementById('regUsername').value.trim();
    const email       = document.getElementById('regEmail').value.trim();
    const password    = document.getElementById('regPassword').value;
    const confirm     = document.getElementById('regConfirm').value;

    if (!username || !email || !password || !confirm) {
        showAlert('registerAlert', 'กรุณากรอกข้อมูลให้ครบ', 'error');
        return;
    }
    if (password !== confirm) {
        showAlert('registerAlert', 'รหัสผ่านไม่ตรงกัน', 'error');
        return;
    }

    setLoading('registerBtn', true);
    try {
        const res = await fetch(BASE_URL + '/api/auth/register', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ display_name: displayName, username, email, password, confirm_password: confirm })
        });
        const data = await res.json();

        if (res.ok) {
            document.getElementById('registerBtn').innerHTML = '<span class="btn-spinner"></span>กำลังเข้าสู่ระบบ...';
            window.location.href = data.redirect || BASE_URL + '/';
        } else {
            showAlert('registerAlert', data.error || 'เกิดข้อผิดพลาด', 'error');
            setLoading('registerBtn', false);
            document.getElementById('registerBtn').textContent = 'สมัครสมาชิก';
        }
    } catch {
        showAlert('registerAlert', 'ไม่สามารถเชื่อมต่อได้ กรุณาลองใหม่', 'error');
        setLoading('registerBtn', false);
        document.getElementById('registerBtn').textContent = 'สมัครสมาชิก';
    }
}

// ── Google Sign-In ─────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
    const initGoogle = () => {
        if (typeof google !== 'undefined' && google.accounts) {
            google.accounts.id.initialize({
                client_id: '<?= GOOGLE_CLIENT_ID ?>',
                callback: handleCredentialResponse
            });
            
            // Dynamically calculate width for Google Buttons based on container width
            const bodyWidth = document.querySelector('.auth-body')?.clientWidth || 350;
            const fallbackWidth = bodyWidth - (window.innerWidth <= 480 ? 50 : 70);
            const containerWidth = document.getElementById('googleBtnLogin')?.offsetWidth || 0;
            const btnWidth = Math.min(350, Math.max(200, containerWidth || fallbackWidth));
            
            const loginBtn = document.getElementById('googleBtnLogin');
            if (loginBtn) {
                google.accounts.id.renderButton(loginBtn, {
                    theme: 'outline',
                    size: 'large',
                    width: btnWidth,
                    text: 'signin_with',
                    locale: 'th'
                });
            }

            const regBtn = document.getElementById('googleBtnReg');
            if (regBtn) {
                google.accounts.id.renderButton(regBtn, {
                    theme: 'outline',
                    size: 'large',
                    width: btnWidth,
                    text: 'signup_with',
                    locale: 'th'
                });
            }
        } else {
            setTimeout(initGoogle, 100);
        }
    };
    initGoogle();
});

async function handleCredentialResponse(response) {
    const credential = response.credential;
    clearAlerts();
    
    const activeTab = document.getElementById('paneLogin').classList.contains('active') ? 'login' : 'register';
    const btnId = activeTab === 'login' ? 'loginBtn' : 'registerBtn';
    const alertId = activeTab === 'login' ? 'loginAlert' : 'registerAlert';
    
    setLoading(btnId, true);
    
    try {
        const res = await fetch(BASE_URL + '/api/auth/google', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ credential })
        });
        const data = await res.json();
        
        if (res.ok) {
            window.location.href = data.redirect || BASE_URL + '/';
        } else {
            showAlert(alertId, data.error || 'เกิดข้อผิดพลาดในการลงชื่อเข้าใช้ด้วย Google', 'error');
            setLoading(btnId, false);
        }
    } catch (e) {
        showAlert(alertId, 'ไม่สามารถเชื่อมต่อได้ กรุณาลองใหม่', 'error');
        setLoading(btnId, false);
    }
}
</script>
</body>
</html>
