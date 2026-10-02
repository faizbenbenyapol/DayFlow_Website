/* =====================================================
   auth.js — sign in, register and the two-factor step (views/auth/login.php)

   The functions are global because the markup names them in data-act and
   actions.js looks them up on window. No eval, no inline handlers: the page's
   CSP forbids both.
===================================================== */

const AUTH_BASE = window.location.origin + window.location.pathname.replace(/\/(login|register)\/?$/, '');
const AUTH_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const AUTH_GOOGLE_CLIENT = document.getElementById('auth')?.dataset.googleClient || '';

const $id = id => document.getElementById(id);

// ── Tabs ──────────────────────────────────────────────

const AUTH_TABS = ['login', 'register'];

function switchTab(tab, focusField = true) {
    for (const name of AUTH_TABS) {
        const selected = name === tab;
        const button = $id('tab' + (name === 'login' ? 'Login' : 'Register'));
        $id('pane' + (name === 'login' ? 'Login' : 'Register')).hidden = !selected;
        button.setAttribute('aria-selected', String(selected));
        button.tabIndex = selected ? 0 : -1;
    }
    clearAlerts();
    if (focusField) {
        const form = $id(tab === 'login' ? 'loginForm' : 'registerForm');
        form.querySelector('input:not([type="hidden"])')?.focus();
    }
}

// Left and right arrows move between the two tabs, as a tab list should.
$id('authTabs').addEventListener('keydown', event => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    const next = AUTH_TABS[(AUTH_TABS.indexOf(currentTab()) + 1) % AUTH_TABS.length];
    switchTab(next);
    $id('tab' + (next === 'login' ? 'Login' : 'Register')).focus();
});

function currentTab() {
    return $id('paneLogin').hidden ? 'register' : 'login';
}

if (window.location.pathname.includes('register')) switchTab('register', false);

// ── Messages and the waiting state ────────────────────

function showAlert(id, message, kind = 'danger') {
    const box = $id(id);
    box.textContent = message;
    box.className = 'alert alert-' + kind;
    box.hidden = false;
}

function clearAlerts() {
    document.querySelectorAll('.auth .alert').forEach(box => { box.hidden = true; });
}

/** A button that is waiting shows a spinner and says what it is doing. */
function setBusy(buttonId, busy, label = '') {
    const button = $id(buttonId);
    if (busy) {
        button.dataset.label = button.dataset.label || button.textContent;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = '<span class="spinner" aria-hidden="true"></span>';
        button.append(label);
    } else {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.textContent = button.dataset.label || button.textContent;
    }
}

async function postJson(path, body) {
    const res = await fetch(AUTH_BASE + path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': AUTH_CSRF },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, data };
}

// ── Fields ────────────────────────────────────────────

function togglePw(inputId, button) {
    const input = $id(inputId);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.textContent = show ? 'ซ่อน' : 'แสดง';
    button.setAttribute('aria-pressed', String(show));
}

const STRENGTH_WORDS = ['', 'อ่อน', 'พอใช้', 'ดี', 'แข็งแรง'];

function updateStrength(value) {
    let score = 0;
    if (value.length >= 8) score++;
    if (value.length >= 12) score++;
    if (/[A-Z]/.test(value)) score++;
    if (/[0-9]/.test(value)) score++;
    if (/[^a-zA-Z0-9]/.test(value)) score++;

    // Typing nothing is no level at all; one tick is "weak", five is "strong".
    const level = value === '' ? 0 : Math.min(Math.max(score, 1), 4);
    $id('strengthMeter').dataset.level = String(level);
    $id('strengthText').textContent = STRENGTH_WORDS[level];
}

function validateUsername(input) {
    const value = input.value;
    const bad = value !== '' && !/^[a-zA-Z0-9_]{3,30}$/.test(value);
    $id('usernameHint').hidden = !bad;
    $id('usernameHelp').hidden = bad;
    input.setAttribute('aria-invalid', String(bad));
}

// ── Sign in ───────────────────────────────────────────

async function doLogin() {
    clearAlerts();
    const identifier = $id('loginIdentifier').value.trim();
    const password = $id('loginPassword').value;

    if (!identifier || !password) {
        showAlert('loginAlert', 'กรอกชื่อผู้ใช้หรืออีเมล และรหัสผ่านให้ครบ');
        return;
    }

    setBusy('loginBtn', true, 'กำลังเข้าสู่ระบบ…');
    try {
        const { ok, data } = await postJson('/api/auth/login', {
            identifier, password, remember_device: $id('rememberDevice').checked,
        });

        if (ok && data.two_factor_required) {
            // The password checked out but the session is still pending: ask for the code.
            setBusy('loginBtn', false);
            showTwoFactorStep();
        } else if (ok) {
            window.location.href = data.redirect || AUTH_BASE + '/';
        } else {
            showAlert('loginAlert', data.error || 'เข้าสู่ระบบไม่สำเร็จ ลองอีกครั้ง');
            setBusy('loginBtn', false);
        }
    } catch {
        showAlert('loginAlert', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ตรวจอินเทอร์เน็ตแล้วลองอีกครั้ง');
        setBusy('loginBtn', false);
    }
}

// ── Two-factor ────────────────────────────────────────

function showTwoFactorStep() {
    $id('authTabs').hidden = true;
    $id('paneLogin').hidden = true;
    $id('paneRegister').hidden = true;
    $id('paneTwoFactor').hidden = false;
    $id('twoFactorCode').focus();
}

function cancelTwoFactor() {
    $id('twoFactorCode').value = '';
    $id('paneTwoFactor').hidden = true;
    $id('authTabs').hidden = false;
    $id('loginPassword').value = '';
    switchTab('login');
}

async function doTwoFactor() {
    clearAlerts();
    const code = $id('twoFactorCode').value.trim();
    if (!code) {
        showAlert('twoFactorAlert', 'กรอกรหัสยืนยันจากแอป Authenticator');
        return;
    }

    setBusy('twoFactorBtn', true, 'กำลังตรวจรหัส…');
    try {
        const { ok, data } = await postJson('/api/auth/two-factor', { code });
        if (ok) {
            window.location.href = data.redirect || AUTH_BASE + '/';
        } else {
            showAlert('twoFactorAlert', data.error || 'รหัสไม่ถูกต้อง ลองรหัสใหม่จากแอป');
            setBusy('twoFactorBtn', false);
            $id('twoFactorCode').select();
        }
    } catch {
        showAlert('twoFactorAlert', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ตรวจอินเทอร์เน็ตแล้วลองอีกครั้ง');
        setBusy('twoFactorBtn', false);
    }
}

// ── Register ──────────────────────────────────────────

async function doRegister() {
    clearAlerts();
    const displayName = $id('regDisplayName').value.trim();
    const username = $id('regUsername').value.trim();
    const email = $id('regEmail').value.trim();
    const password = $id('regPassword').value;
    const confirm = $id('regConfirm').value;

    if (!username || !email || !password || !confirm) {
        showAlert('registerAlert', 'กรอกชื่อผู้ใช้ อีเมล และรหัสผ่านให้ครบ');
        return;
    }
    if (password !== confirm) {
        showAlert('registerAlert', 'รหัสผ่านสองช่องไม่ตรงกัน พิมพ์ยืนยันอีกครั้ง');
        $id('regConfirm').focus();
        return;
    }

    setBusy('registerBtn', true, 'กำลังสมัคร…');
    try {
        const { ok, data } = await postJson('/api/auth/register', {
            display_name: displayName, username, email, password, confirm_password: confirm,
        });
        if (ok) {
            window.location.href = data.redirect || AUTH_BASE + '/';
        } else {
            showAlert('registerAlert', data.error || 'สมัครไม่สำเร็จ ลองอีกครั้ง');
            setBusy('registerBtn', false);
        }
    } catch {
        showAlert('registerAlert', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ตรวจอินเทอร์เน็ตแล้วลองอีกครั้ง');
        setBusy('registerBtn', false);
    }
}

// ── Google ────────────────────────────────────────────

function initGoogle() {
    if (!AUTH_GOOGLE_CLIENT) {
        document.querySelectorAll('[data-needs-google]').forEach(el => { el.hidden = true; });
        return;
    }
    if (typeof google === 'undefined' || !google.accounts) {
        setTimeout(initGoogle, 100);
        return;
    }

    google.accounts.id.initialize({ client_id: AUTH_GOOGLE_CLIENT, callback: handleCredentialResponse });

    // The button is an iframe with a pixel width, so it is sized from the column
    // it sits in (the panes may be hidden, the column never is).
    const width = Math.min(400, Math.max(200, Math.round(document.querySelector('.auth-panel').clientWidth)));
    const theme = document.documentElement.dataset.theme === 'dark' ? 'filled_black' : 'outline';
    for (const [id, text] of [['googleBtnLogin', 'signin_with'], ['googleBtnReg', 'signup_with']]) {
        google.accounts.id.renderButton($id(id), { theme, size: 'large', width, text, locale: 'th' });
    }
}

window.addEventListener('DOMContentLoaded', initGoogle);

async function handleCredentialResponse(response) {
    clearAlerts();
    const tab = currentTab();
    const buttonId = tab === 'login' ? 'loginBtn' : 'registerBtn';
    const alertId = tab === 'login' ? 'loginAlert' : 'registerAlert';

    setBusy(buttonId, true, 'กำลังตรวจสอบกับ Google…');
    try {
        const { ok, data } = await postJson('/api/auth/google', { credential: response.credential });

        if (ok && data.two_factor_required) {
            // Google vouched for the email only; an account with 2FA still needs its code.
            setBusy(buttonId, false);
            showTwoFactorStep();
        } else if (ok) {
            window.location.href = data.redirect || AUTH_BASE + '/';
        } else {
            showAlert(alertId, data.error || 'เข้าสู่ระบบด้วย Google ไม่สำเร็จ ลองอีกครั้ง');
            setBusy(buttonId, false);
        }
    } catch {
        showAlert(alertId, 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ตรวจอินเทอร์เน็ตแล้วลองอีกครั้ง');
        setBusy(buttonId, false);
    }
}
