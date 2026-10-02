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
function toast(message, type = 'success', duration = 3000) {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.setAttribute('role', type === 'danger' || type === 'error' ? 'alert' : 'status');
    el.setAttribute('aria-live', type === 'danger' || type === 'error' ? 'assertive' : 'polite');
    el.textContent = message;
    container.appendChild(el);

    // Trigger animation
    requestAnimationFrame(() => {
        requestAnimationFrame(() => el.classList.add('show'));
    });

    setTimeout(() => {
        el.classList.remove('show');
        setTimeout(() => el.remove(), 250);
    }, duration);
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
   SweetAlert2 — fetched on first use
   The library and its stylesheet are ~90KB, and most page views never open a
   dialog. The shim keeps every existing `Swal.fire(...)` call site working: the
   real library replaces window.Swal as soon as it lands.
===================================================== */
// Pinned to an exact version with its SRI hash, so a compromised or changed
// CDN file is refused instead of run. Bumping the version means new hashes:
//   curl -fsSL <url> | openssl dgst -sha384 -binary | openssl base64 -A
const SWAL_CSS = 'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.min.css';
const SWAL_CSS_SRI = 'sha384-dCW5imOdApH6OwpFau8cZNKjqVbJYnCA5q+8YsMYP3XwXKsV6Jfz1u6MZLnXaBsS';
const SWAL_JS  = 'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.all.min.js';
const SWAL_JS_SRI = 'sha384-nLoOnA/BDh8A/jxqtckg4DumuCGOBYUnNJLZdQz/zfYNp3wcjGSoWTAzgko06G/2';

let swalLoader = null;
function loadSwal() {
    if (swalLoader) return swalLoader;
    swalLoader = new Promise((resolve, reject) => {
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = SWAL_CSS;
        css.integrity = SWAL_CSS_SRI;
        css.crossOrigin = 'anonymous';
        document.head.appendChild(css);

        const js = document.createElement('script');
        js.src = SWAL_JS;
        js.integrity = SWAL_JS_SRI;
        js.crossOrigin = 'anonymous';
        js.onload = () => resolve(window.Swal);
        js.onerror = () => {
            swalLoader = null; // let a later dialog retry
            reject(new Error('โหลด SweetAlert2 ไม่สำเร็จ'));
        };
        document.head.appendChild(js);
    });
    return swalLoader;
}

window.Swal = {
    fire: (...args) => loadSwal().then(real => real.fire(...args)),
};

/* =====================================================
   Confirm Dialog (SweetAlert2)
===================================================== */
function confirmAction(message, okLabel, title) {
    return Swal.fire({
        title: title || 'ยืนยัน',
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: okLabel || 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#e05c4b',
        cancelButtonColor: '#6b7280',
        focusCancel: true,
    }).then(function(result) {
        return result.isConfirmed;
    });
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
function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
        'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + (d.getFullYear() + 543);
}

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
        'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    const time = d.toTimeString().slice(0, 5);
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + (d.getFullYear() + 543) + ' ' + time + ' น.';
}

function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

function daysUntil(dateStr) {
    if (!dateStr) return null;
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    const target = new Date(dateStr);
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
   Command Palette (Ctrl/Cmd + K)
   One keystroke to reach any of the app's twenty-odd modules, or anything
   inside them. Navigation targets are read from the rail that the server
   already rendered, so hidden menus and share mode are respected without
   duplicating that logic here.
===================================================== */
(function initCommandPalette() {
    const rail = document.getElementById('appRail');
    if (!rail) return; // login, share and other chrome-less pages

    const navCommands = Array.from(rail.querySelectorAll('a.nav-item:not(.rail-logout)'))
        .map(link => ({
            title: (link.querySelector('.nav-text')?.textContent || link.title || '').trim(),
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
            { title: 'จดด่วน', url: '#', type: 'ทำ', action: () => window.openQuickAdd('note') },
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

// A dashboard widget is clickable as a whole, except where something inside
// it already handles the click.
document.addEventListener('click', function (event) {
    const widget = event.target.closest('[data-widget-nav]');
    if (!widget) return;
    if (event.target.closest('a, .drag-handle, .btn-copy-code, .widget-note-item')) return;

    window.location.href = widget.dataset.widgetNav;
});
