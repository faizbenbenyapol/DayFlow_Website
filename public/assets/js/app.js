/* =====================================================
   app.js — Global Utilities
===================================================== */

const APP_URL = document.querySelector('meta[name="csrf-token"]')
    ? window.location.origin + (function () {
        const base = document.querySelector('base');
        return base ? new URL(base.href).pathname.replace(/\/$/, '') : '';
    })()
    : '';

// Derive app base URL from the app.js script tag's pathname (ignoring ?v= cache-bust query).
const BASE_URL = (function () {
    const scripts = document.querySelectorAll('script[src*="/assets/js/app.js"]');
    if (scripts.length) {
        const u = new URL(scripts[0].src);
        return u.origin + u.pathname.replace('/assets/js/app.js', '');
    }
    return window.location.origin;
})();

// Opened through an app share link (views/layout/header.php sets the class).
// A page draws no control that would change data; the server refuses writes
// in this mode anyway.
const IS_READ_ONLY = document.body.classList.contains('is-readonly');

/**
 * CSRF-aware fetch wrapper — sends JSON, returns JSON
 */
async function apiFetch(url, options = {}) {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrf = csrfMeta ? csrfMeta.content : '';

    const defaults = {
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf
        }
    };

    const merged = {
        ...defaults,
        ...options,
        headers: {
            ...defaults.headers,
            ...(options.headers || {})
        }
    };

    // For FormData, remove Content-Type so browser sets multipart boundary
    if (options.body instanceof FormData) {
        delete merged.headers['Content-Type'];
    }

    const res = await fetch(url, merged);

    if (res.status === 401) {
        window.location.href = BASE_URL + '/login';
        return;
    }

    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        data = { error: text };
    }

    if (!res.ok) {
        const err = new Error(data.error || 'เกิดข้อผิดพลาด');
        err.status = res.status;
        err.data = data;
        throw err;
    }

    return data;
}

/* =====================================================
   Toast Notifications
===================================================== */
const TOAST_MAX = 3;
const TOAST_MS = 3500;
const TOAST_ERROR_MS = 6000;   // ข้อผิดพลาดต้องอ่านว่าพลาดเพราะอะไร จึงอยู่นานกว่า
const TOAST_MARK = { success: '✓', danger: '!', error: '!', warning: '!', info: 'i' };

/**
 * แสดงข้อความแจ้งผลแบบแผ่นโน้ตที่มุมล่าง แล้วหายไปเอง (กดปิดก่อนได้)
 * @param {string} message สิ่งที่เพิ่งเกิด เช่น "บันทึกแล้ว"
 * @param {'success'|'danger'|'error'|'warning'|'info'} [type='success']
 * @param {number} [duration] มิลลิวินาที ไม่ใส่ = 3.5 วินาที (ผิดพลาด 6 วินาที)
 * ผลข้างเคียง: เพิ่ม element ใน #toastContainer ถ้ามีครบ 3 อันแล้ว อันเก่าสุดหายก่อน
 */
function toast(message, type = 'success', duration) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const isError = type === 'danger' || type === 'error';

    while (container.children.length >= TOAST_MAX) container.firstElementChild.remove();

    const el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.setAttribute('role', isError ? 'alert' : 'status');
    el.innerHTML = '<span class="toast-mark" aria-hidden="true"></span><span class="toast-text"></span>'
        + '<button type="button" class="toast-close" aria-label="ปิดข้อความ">×</button>';
    el.firstChild.textContent = TOAST_MARK[type] || TOAST_MARK.info;
    el.querySelector('.toast-text').textContent = message;
    container.appendChild(el);

    // ปิด: เลื่อนออกก่อนแล้วค่อยลบ ให้ทันเห็นว่ามันไปไหน
    let timer = null;
    const dismiss = () => {
        clearTimeout(timer);
        el.classList.remove('show');
        setTimeout(() => el.remove(), 250);
    };
    el.querySelector('.toast-close').addEventListener('click', dismiss);

    // rAF สองชั้น: ให้เบราว์เซอร์วาดสถานะซ่อนก่อน การเลื่อนเข้าจึงเล่นจริง
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('show')));
    timer = setTimeout(dismiss, duration || (isError ? TOAST_ERROR_MS : TOAST_MS));
}

/* =====================================================
   Modal Helpers
===================================================== */
let lastModalFocus = null;

function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    lastModalFocus = document.activeElement;
    modal.classList.add('active');
    document.body.classList.add('modal-open');
    modal.setAttribute('aria-hidden', 'false');
    // Focus first input
    const input = modal.querySelector('input:not([type="hidden"]), textarea, select');
    if (input) setTimeout(() => input.focus(), 50);
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.remove('active');
        el.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.modal-backdrop.active')) document.body.classList.remove('modal-open');
        if (lastModalFocus && typeof lastModalFocus.focus === 'function') lastModalFocus.focus();
    }
}

// Close modal on backdrop click
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal-backdrop')) {
        closeModal(e.target.id);
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.active').forEach(m => {
            closeModal(m.id);
        });
    }
});

// Wire up modal close buttons
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal-close') || 'closeModal' in e.target.dataset) {
        const modal = e.target.closest('.modal-backdrop');
        if (modal) closeModal(modal.id);
    }
});

/* =====================================================
   กล่องของ DayFlow: ยืนยัน / ถามข้อความ / แจ้งผล
   ใช้ <dialog> ของเบราว์เซอร์ เพราะ showModal() ทำให้หน้าข้างหลังกดไม่ได้
   (Tab จึงวนอยู่ในกล่อง) และ Esc ปิดได้เอง ทุกหน้าเรียกผ่าน confirmAction(),
   askText() หรือ showNotice() ห้ามใช้ alert/confirm/prompt ของเบราว์เซอร์
   หรือ library กล่องสำเร็จรูป (กฎใน CLAUDE.md)
===================================================== */
let dialogEl = null;
let dialogQueue = Promise.resolve();

/** สร้าง <dialog> ใบเดียวที่ทุกกล่องใช้ร่วมกันในครั้งแรกที่ถูกเรียก แล้วคืนใบเดิมทุกครั้งต่อไป */
function dialogNode() {
    if (dialogEl) return dialogEl;
    dialogEl = document.createElement('dialog');
    dialogEl.className = 'sheet df-dialog';
    dialogEl.setAttribute('aria-labelledby', 'dfDialogTitle');
    dialogEl.setAttribute('aria-describedby', 'dfDialogMsg');
    dialogEl.innerHTML =
        '<form class="df-dialog-card" method="dialog" novalidate>'
        + '<h2 class="df-dialog-title" id="dfDialogTitle"></h2>'
        + '<p class="df-dialog-msg" id="dfDialogMsg"></p>'
        + '<div class="df-dialog-field">'
        +   '<label class="form-label" for="dfDialogInput"></label>'
        +   '<input class="form-control" id="dfDialogInput">'
        +   '<p class="form-error" id="dfDialogError" role="alert" hidden></p>'
        + '</div>'
        + '<div class="df-dialog-actions">'
        +   '<button type="button" class="btn btn-ghost" data-dialog-cancel></button>'
        +   '<button type="submit" class="btn"></button>'
        + '</div>'
        + '</form>';
    // Esc ของกล่องนี้ต้องไม่หลุดไปปิด modal ที่เปิดอยู่ข้างใต้ด้วย
    // (ตัวปิด modal ฟัง Esc ที่ document) การ cancel ของ <dialog> ยังทำงานตามปกติ
    dialogEl.addEventListener('keydown', e => { if (e.key === 'Escape') e.stopPropagation(); });
    document.body.appendChild(dialogEl);
    return dialogEl;
}

/**
 * เปิดกล่องหนึ่งใบแล้วรอผู้ใช้ตอบ ถ้ามีกล่องเปิดอยู่ ใบใหม่จะรอจนใบเดิมปิดก่อน ไม่ซ้อนกัน
 * @param {object} opts
 * @param {string} opts.title หัวกล่อง
 * @param {string} [opts.message] คำอธิบาย ขึ้นบรรทัดใหม่ด้วย \n ได้
 * @param {'primary'|'danger'} [opts.tone='primary'] danger = ปุ่มยืนยันสีแดง และโฟกัสเริ่มที่ "ยกเลิก"
 * @param {string} [opts.okLabel='ตกลง'] ชื่อปุ่มยืนยัน ควรบอกสิ่งที่จะเกิด เช่น "ลบรายการ"
 * @param {string|null} [opts.cancelLabel='ยกเลิก'] null = กล่องแจ้งผลที่มีปุ่มเดียว
 * @param {object} [opts.input] ใส่เมื่ออยากได้ช่องข้อความ { label, value, placeholder, type, required }
 *   required คือข้อความเตือนใต้ช่องเมื่อเว้นว่าง (ไม่ใส่ = เว้นว่างได้)
 * @returns {Promise<{ok: boolean, value: string}>} value คือข้อความในช่อง
 *   (ตัดช่องว่างหัวท้ายแล้ว ยกเว้นช่องรหัสผ่าน)
 */
function showDialog(opts) {
    const next = dialogQueue.then(() => openDialog(opts));
    dialogQueue = next.catch(() => {});
    return next;
}

/** ตัวทำงานจริงของ showDialog(): เติมเนื้อหา เปิดกล่อง และตอบผลครั้งเดียวเมื่อผู้ใช้เลือก */
function openDialog(opts) {
    const dlg = dialogNode();
    const form = dlg.firstElementChild;
    const msg = dlg.querySelector('.df-dialog-msg');
    const field = dlg.querySelector('.df-dialog-field');
    const input = dlg.querySelector('#dfDialogInput');
    const error = dlg.querySelector('#dfDialogError');
    const cancelBtn = dlg.querySelector('[data-dialog-cancel]');
    const okBtn = dlg.querySelector('button[type="submit"]');
    const isDanger = opts.tone === 'danger';
    const hasCancel = opts.cancelLabel !== null;
    const ask = opts.input || null;

    // เติมเนื้อหาของกล่องใบนี้ (ใบเดียวใช้ซ้ำ จึงต้องล้างค่าของครั้งก่อนทุกช่อง)
    dlg.dataset.tone = isDanger ? 'danger' : 'primary';
    dlg.querySelector('.df-dialog-title').textContent = opts.title || 'ยืนยัน';
    msg.textContent = opts.message || '';
    msg.hidden = !opts.message;
    okBtn.textContent = opts.okLabel || 'ตกลง';
    okBtn.className = 'btn ' + (isDanger ? 'btn-danger' : 'btn-primary');
    cancelBtn.textContent = opts.cancelLabel || 'ยกเลิก';
    cancelBtn.hidden = !hasCancel;
    field.hidden = !ask;
    error.hidden = true;
    input.removeAttribute('aria-invalid');
    if (ask) {
        field.querySelector('label').textContent = ask.label || opts.title || '';
        input.type = ask.type || 'text';
        input.autocomplete = input.type === 'password' ? 'current-password' : 'off';
        input.value = ask.value || '';
        input.placeholder = ask.placeholder || '';
    }

    const returnFocus = document.activeElement;
    return new Promise(resolve => {
        let settled = false;
        const readValue = () => (input.type === 'password' ? input.value : input.value.trim());

        // ปิดกล่องและตอบผลครั้งเดียว กดยืนยันสองครั้งเร็ว ๆ ก็ทำงานครั้งเดียว (EDGE-1)
        const finish = ok => {
            if (settled) return;
            settled = true;
            form.removeEventListener('submit', onSubmit);
            cancelBtn.removeEventListener('click', onCancel);
            dlg.removeEventListener('cancel', onEsc);
            dlg.removeEventListener('click', onBackdrop);
            dlg.close();
            if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
            resolve({ ok, value: ask ? readValue() : '' });
        };
        // กดปุ่มยืนยัน หรือ Enter ในช่องข้อความ: ตรวจช่องว่างก่อนปิด
        const onSubmit = e => {
            e.preventDefault();
            if (ask && ask.required && !input.value.trim()) {
                error.textContent = ask.required;
                error.hidden = false;
                input.setAttribute('aria-invalid', 'true');
                input.focus();
                return;
            }
            finish(true);
        };
        const onCancel = () => finish(false);
        // Esc และการคลิกพื้นมืดรอบการ์ด: กล่องแจ้งผลนับเป็น "รับทราบ" กล่องอื่นนับเป็นยกเลิก
        const onEsc = e => { e.preventDefault(); finish(!hasCancel); };
        const onBackdrop = e => { if (e.target === dlg) finish(!hasCancel); };

        form.addEventListener('submit', onSubmit);
        cancelBtn.addEventListener('click', onCancel);
        dlg.addEventListener('cancel', onEsc);
        dlg.addEventListener('click', onBackdrop);
        dlg.showModal();

        // โฟกัสเริ่ม: ช่องข้อความก่อน ถ้าเป็นเรื่องอันตรายให้อยู่ที่ "ยกเลิก" กันกด Enter พลาด
        if (ask) { input.focus(); input.select(); }
        else (isDanger && hasCancel ? cancelBtn : okBtn).focus();
    });
}

/**
 * ถามยืนยันก่อนทำสิ่งที่ย้อนไม่ได้ หรือสิ่งที่ผู้ใช้ควรรู้ก่อนกด
 * @param {string} message ผลที่จะเกิดเมื่อยืนยัน
 * @param {string} [okLabel='ยืนยัน'] ชื่อปุ่มตามการกระทำ เช่น "ลบรายการ"
 * @param {string} [title='ยืนยัน']
 * @param {'danger'|'primary'} [tone='danger'] ค่าเริ่มเป็นอันตรายเพราะเกือบทุกจุดคือการลบ
 *   ส่ง 'primary' เมื่อการกระทำไม่ทำลายข้อมูล (เช่น ชำระรอบบิล)
 * @returns {Promise<boolean>} true เมื่อกดยืนยัน
 */
function confirmAction(message, okLabel, title, tone = 'danger') {
    return showDialog({ title: title || 'ยืนยัน', message, okLabel: okLabel || 'ยืนยัน', tone })
        .then(r => r.ok);
}

/**
 * ถามข้อความหนึ่งช่อง เช่น เปลี่ยนชื่อหมวด ใส่คำอธิบายรูป ยืนยันรหัสผ่าน
 * @param {object} opts { title, message, label, value, placeholder, type, okLabel, required }
 * @returns {Promise<string|null>} ข้อความที่กรอก หรือ null เมื่อกดยกเลิก
 */
function askText(opts) {
    return showDialog({
        title: opts.title,
        message: opts.message,
        okLabel: opts.okLabel || 'บันทึก',
        input: { label: opts.label, value: opts.value, placeholder: opts.placeholder, type: opts.type, required: opts.required },
    }).then(r => (r.ok ? r.value : null));
}

/** แจ้งผลที่ผู้ใช้ควรอ่านก่อนไปต่อ (ปุ่มเดียว) ผลสั้น ๆ ที่ไม่ต้องอ่านให้ใช้ toast() แทน */
function showNotice(title, message) {
    return showDialog({ title, message, okLabel: 'รับทราบ', cancelLabel: null }).then(() => {});
}

/* =====================================================
   Form Helpers
===================================================== */
function serializeForm(form) {
    const data = {};
    new FormData(form).forEach((value, key) => {
        data[key] = value;
    });
    return data;
}

function setFormErrors(form, errors) {
    // Clear existing errors
    form.querySelectorAll('.form-error').forEach(el => el.remove());
    // Set new errors
    Object.entries(errors).forEach(([field, msg]) => {
        const input = form.querySelector(`[name="${field}"]`);
        if (input) {
            const err = document.createElement('p');
            err.className = 'form-error';
            err.textContent = msg;
            input.closest('.form-group')?.appendChild(err);
        }
    });
}

/* =====================================================
   Date Utilities
===================================================== */
/** วันที่ของเซิร์ฟเวอร์ในรูปแบบ YYYY-MM-DD อ่านจาก <html data-today> */
function serverToday() {
    return document.documentElement.dataset.today || '';
}

/** คำนวณวันพรุ่งนี้จากวันของเซิร์ฟเวอร์ โดยใช้ UTC เพื่อไม่ผิดเพราะเขตเวลา */
function serverTomorrow() {
    const [y, m, d] = serverToday().split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + 1));
    return date.getUTCFullYear() + '-' + String(date.getUTCMonth() + 1).padStart(2, '0') + '-' + String(date.getUTCDate()).padStart(2, '0');
}

/** จำนวนวันจากวันนี้ของเซิร์ฟเวอร์ถึงวันที่ระบุ */
function serverDaysUntil(dateStr) {
    if (!dateStr) return null;
    const today = parseServerDate(serverToday());
    today.setHours(0, 0, 0, 0);
    const target = parseServerDate(dateStr);
    target.setHours(0, 0, 0, 0);
    return Math.round((target - today) / 86400000);
}

/**
 * A date or datetime from the server, as the visitor's own wall clock.
 *
 * new Date('2026-10-24') is midnight UTC, which west of Greenwich is the
 * evening before: every date on the page showed a day early there. With a
 * time part ('T00:00:00', or the 'T' in place of MySQL's space) the string is
 * read as local time, and Safari reads it at all.
 */
function parseServerDate(value) {
    const s = String(value);
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return new Date(s + 'T00:00:00');
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(s)) return new Date(s.replace(' ', 'T'));
    return new Date(s);
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = parseServerDate(dateStr);
    const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
        'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + (d.getFullYear() + 543);
}

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const d = parseServerDate(dateStr);
    const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
        'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    const time = d.toTimeString().slice(0, 5);
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + (d.getFullYear() + 543) + ' ' + time + ' น.';
}

/** Today as YYYY-MM-DD in the visitor's own time zone (toISOString would give UTC's date). */
function todayISO() {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

function daysUntil(dateStr) {
    if (!dateStr) return null;
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    const target = parseServerDate(dateStr);
    target.setHours(0, 0, 0, 0);
    return Math.round((target - now) / 86400000);
}

/* =====================================================
   Number Formatting
===================================================== */
function formatMoney(amount) {
    return Number(amount).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* =====================================================
   Debounce
===================================================== */
function debounce(fn, delay) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

/* =====================================================
   Chart colours
   A chart draws on a canvas, which cannot read CSS, so its colours are looked up
   from the same tokens as the page and redrawn when the theme changes. Nothing
   that draws a chart should write a colour of its own.
===================================================== */
function chartTheme() {
    const css = getComputedStyle(document.documentElement);
    const token = name => css.getPropertyValue(name).trim();
    return {
        ink: token('--ink'),
        pencil: token('--pencil'),
        rule: token('--rule'),
        paper: token('--paper'),
        income: token('--ledger-green'),
        expense: token('--stamp-red'),
        // แท่งของกราฟการเงิน แยกจาก income/expense ที่กราฟหุ้นใช้ระบายกำไร/ขาดทุน
        chartIncome: token('--chart-income'),
        chartExpense: token('--chart-expense'),
        font: token('--font'),
    };
}

/** Chart.js animation is canvas drawing, which the CSS reduced-motion rule cannot reach. */
function chartAnimation() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 400 };
}

/** A six-digit hex colour with some transparency (alpha 0 to 1). */
function withAlpha(hex, alpha) {
    return /^#[0-9a-fA-F]{6}$/.test(hex) ? hex + Math.round(alpha * 255).toString(16).padStart(2, '0') : hex;
}

/** Calls back when the theme changes: the settings page, or the system switching under "auto". */
function onThemeChange(callback) {
    new MutationObserver(callback).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
}

/* =====================================================
   Command Palette (Ctrl/Cmd + K)
   One keystroke to reach any of the app's twenty-odd modules, or anything
   inside them. Navigation targets are read from the "all menus" sheet the
   server already rendered, so hidden menus and share mode are respected
   without duplicating that logic here.
===================================================== */
(function initCommandPalette() {
    const sheet = document.getElementById('menuSheet');
    if (!sheet) return; // login, share and other chrome-less pages

    const navCommands = Array.from(sheet.querySelectorAll('a.sheet-link:not(.sheet-logout)'))
        .map(link => ({
            title: (link.textContent || '').trim(),
            url: link.href,
            type: 'ไปที่',
        }))
        .filter(cmd => cmd.title !== '');

    if (navCommands.length === 0) return;

    // Things to do rather than places to go. They open the quick-add sheet
    // (shell.js), so they are only offered when it is on the page.
    const doCommands = typeof window.openQuickAdd === 'function' || document.getElementById('quickSheet')
        ? [
            { title: 'เพิ่มงาน', url: '#', type: 'ทำ', action: () => window.openQuickAdd('task') },
            { title: 'จดไว้', url: '#', type: 'ทำ', action: () => window.openQuickAdd('task') },
            { title: 'บันทึกรายรับรายจ่าย', url: '#', type: 'ทำ', action: () => window.openQuickAdd('money') },
        ]
        : [];
    const allCommands = doCommands.concat(navCommands);

    const overlay = document.createElement('div');
    overlay.className = 'cmdk-backdrop';
    overlay.hidden = true;
    overlay.innerHTML = `
        <div class="cmdk-panel" role="dialog" aria-modal="true" aria-label="แถบคำสั่ง">
            <div class="cmdk-input-row">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" id="cmdkInput" autocomplete="off" spellcheck="false"
                       placeholder="ไปที่หน้า หรือค้นหางาน โน้ต ไฟล์..." aria-label="พิมพ์เพื่อค้นหา">
                <kbd>esc</kbd>
            </div>
            <div class="cmdk-list" id="cmdkList" role="listbox"></div>
        </div>`;
    document.body.appendChild(overlay);

    const input = overlay.querySelector('#cmdkInput');
    const list  = overlay.querySelector('#cmdkList');

    let items = [];
    let active = 0;
    let inFlight = null;

    const render = () => {
        if (items.length === 0) {
            list.innerHTML = '<div class="cmdk-empty">ไม่พบรายการที่ตรงกัน</div>';
            return;
        }
        list.innerHTML = items.map((item, index) => `
            <a class="cmdk-item${index === active ? ' is-active' : ''}" role="option"
               aria-selected="${index === active}" data-index="${index}" href="${escHtml(item.url)}">
                <span class="cmdk-item-type">${escHtml(item.type)}</span>
                <span class="cmdk-item-title">${escHtml(item.title)}</span>
                ${item.subtitle ? `<span class="cmdk-item-sub">${escHtml(item.subtitle)}</span>` : ''}
            </a>`).join('');
        list.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' });
    };

    const matchNav = query => {
        if (query === '') return allCommands;
        const needle = query.toLowerCase();
        return allCommands.filter(cmd => cmd.title.toLowerCase().includes(needle));
    };

    // A command that does something instead of going somewhere.
    const runAction = item => {
        close();
        item.action();
    };

    const update = async () => {
        const query = input.value.trim();
        items = matchNav(query);
        active = 0;
        render();

        // Nav matches are local and instant; content search needs the server.
        if (inFlight) inFlight.abort();
        if (query.length < 2) { inFlight = null; return; }

        const controller = new AbortController();
        inFlight = controller;
        try {
            const data = await apiFetch(`${BASE_URL}/api/search?q=${encodeURIComponent(query)}`, { signal: controller.signal });
            if (controller.signal.aborted) return;
            items = matchNav(query).concat(data.results || []);
            render();
        } catch (error) {
            if (error.name !== 'AbortError') console.warn('Command palette search failed:', error);
        } finally {
            if (inFlight === controller) inFlight = null;
        }
    };

    const debouncedUpdate = debounce(update, 180);

    const open = () => {
        if (!overlay.hidden) return;
        overlay.hidden = false;
        document.body.classList.add('modal-open');
        input.value = '';
        items = allCommands;
        active = 0;
        render();
        input.focus();
    };

    const close = () => {
        if (overlay.hidden) return;
        if (inFlight) { inFlight.abort(); inFlight = null; }
        overlay.hidden = true;
        document.body.classList.remove('modal-open');
    };

    const move = delta => {
        if (items.length === 0) return;
        active = (active + delta + items.length) % items.length;
        render();
    };

    input.addEventListener('input', () => {
        // Show the filtered nav list immediately, then fold in search results.
        items = matchNav(input.value.trim());
        active = 0;
        render();
        debouncedUpdate();
    });

    overlay.addEventListener('click', event => {
        if (event.target === overlay) close();
    });

    list.addEventListener('click', event => {
        const el = event.target.closest('.cmdk-item');
        const item = el ? items[Number(el.dataset.index)] : null;
        if (item && item.action) {
            event.preventDefault();
            runAction(item);
        }
    });

    list.addEventListener('mousemove', event => {
        const el = event.target.closest('.cmdk-item');
        if (el && Number(el.dataset.index) !== active) {
            active = Number(el.dataset.index);
            render();
        }
    });

    overlay.addEventListener('keydown', event => {
        if (event.key === 'Escape')    { event.preventDefault(); close(); }
        if (event.key === 'ArrowDown') { event.preventDefault(); move(1); }
        if (event.key === 'ArrowUp')   { event.preventDefault(); move(-1); }
        if (event.key === 'Enter' && items[active]) {
            event.preventDefault();
            if (items[active].action) runAction(items[active]);
            else window.location.href = items[active].url;
        }
    });

    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            overlay.hidden ? open() : close();
        }
    });

    // Let other code (a button, a shortcut hint) open it too.
    window.openCommandPalette = open;
})();

/* The declarative data-act dispatcher now lives in actions.js, so the
   standalone login page can load it without the rest of app.js. */

/* =====================================================
   Small helpers the declarative actions name
   These used to be written out inline in the markup.
===================================================== */
function hideElement(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
