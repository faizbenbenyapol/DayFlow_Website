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
const SWAL_CSS = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css';
const SWAL_JS  = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';

let swalLoader = null;
function loadSwal() {
    if (swalLoader) return swalLoader;
    swalLoader = new Promise((resolve, reject) => {
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = SWAL_CSS;
        document.head.appendChild(css);

        const js = document.createElement('script');
        js.src = SWAL_JS;
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
   Global Search
===================================================== */
(function initGlobalSearch() {
    const input = document.getElementById('globalSearchInput');
    const results = document.getElementById('globalSearchResults');
    if (!input || !results) return;

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[ch]));

    const render = items => {
        if (!items.length) {
            results.innerHTML = '<div class="global-search-empty">ไม่พบข้อมูลที่ตรงกัน</div>';
        } else {
            results.innerHTML = items.map((item, index) => `
                <a class="global-search-item" role="option" data-search-index="${index}" href="${escapeHtml(item.url)}">
                    <span class="global-search-type">${escapeHtml(item.type)}</span>
                    <span class="global-search-copy"><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.subtitle)}</small></span>
                </a>`).join('');
        }
        results.hidden = false;
    };

    // Typing fast queues several requests, and they do not necessarily come
    // back in order — a slow early response would otherwise overwrite the
    // results for what the user has actually typed.
    let inFlight = null;

    const search = debounce(async () => {
        const q = input.value.trim();
        if (inFlight) inFlight.abort();
        if (q.length < 2) { results.hidden = true; inFlight = null; return; }

        const controller = new AbortController();
        inFlight = controller;
        try {
            const data = await apiFetch(`${BASE_URL}/api/search?q=${encodeURIComponent(q)}`, { signal: controller.signal });
            if (controller.signal.aborted) return;
            render(data.results || []);
        } catch (error) {
            if (error.name === 'AbortError') return;
            results.innerHTML = '<div class="global-search-empty">ค้นหาไม่สำเร็จ ลองใหม่อีกครั้ง</div>';
            results.hidden = false;
        } finally {
            if (inFlight === controller) inFlight = null;
        }
    }, 220);

    input.addEventListener('input', search);
    input.addEventListener('focus', search);
    input.addEventListener('keydown', event => {
        if (results.hidden) return;
        const items = Array.from(results.querySelectorAll('.global-search-item'));
        if (!items.length) return;
        const current = items.findIndex(item => item.classList.contains('keyboard-focus'));
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = event.key === 'ArrowDown' ? (current + 1) % items.length : (current - 1 + items.length) % items.length;
            items.forEach(item => item.classList.remove('keyboard-focus'));
            items[next].classList.add('keyboard-focus');
            items[next].scrollIntoView({ block: 'nearest' });
        }
        if (event.key === 'Enter' && current >= 0) {
            event.preventDefault();
            items[current].click();
        }
    });
    document.addEventListener('keydown', event => {
        const tag = document.activeElement?.tagName;
        if (event.key === '/' && document.activeElement !== input && !['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) {
            event.preventDefault(); input.focus();
        }
        if (event.key === 'Escape') { input.value = ''; results.hidden = true; input.blur(); }
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('#globalSearch')) results.hidden = true;
    });
})();

/* =====================================================
   Command Palette (Ctrl/Cmd + K)
   One keystroke to reach any of the app's twenty-odd modules, or anything
   inside them. Navigation targets are read from the sidebar that the server
   already rendered, so hidden menus and share mode are respected without
   duplicating that logic here.
===================================================== */
(function initCommandPalette() {
    const sidebar = document.getElementById('appSidebar');
    if (!sidebar) return; // login, share and other chrome-less pages

    const navCommands = Array.from(sidebar.querySelectorAll('a.nav-item'))
        .map(link => ({
            title: (link.querySelector('span')?.textContent || link.textContent || '').trim(),
            url: link.href,
            type: 'ไปที่',
        }))
        .filter(cmd => cmd.title !== '');

    if (navCommands.length === 0) return;

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

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[ch]));

    const render = () => {
        if (items.length === 0) {
            list.innerHTML = '<div class="cmdk-empty">ไม่พบรายการที่ตรงกัน</div>';
            return;
        }
        list.innerHTML = items.map((item, index) => `
            <a class="cmdk-item${index === active ? ' is-active' : ''}" role="option"
               aria-selected="${index === active}" data-index="${index}" href="${escapeHtml(item.url)}">
                <span class="cmdk-item-type">${escapeHtml(item.type)}</span>
                <span class="cmdk-item-title">${escapeHtml(item.title)}</span>
                ${item.subtitle ? `<span class="cmdk-item-sub">${escapeHtml(item.subtitle)}</span>` : ''}
            </a>`).join('');
        list.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' });
    };

    const matchNav = query => {
        if (query === '') return navCommands;
        const needle = query.toLowerCase();
        return navCommands.filter(cmd => cmd.title.toLowerCase().includes(needle));
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
        items = navCommands;
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
            window.location.href = items[active].url;
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

/* =====================================================
   Declarative actions
   Replaces inline on* attributes so the page needs no 'unsafe-inline' in its
   script-src, which is what stops an injected attribute from ever running.

   Markup carries what to do rather than the code to do it:

     data-act="openEditTask"  data-args="[42]"      call window.openEditTask(42)
     data-act="togglePw"      data-args='["pw","$el"]'   $el becomes the element
     data-act="handleKey"     data-args='["$event"]'     $event becomes the event
     data-args='["$value"]'   also $checked and $files, for what `this` reached
     data-nav="/tasks"                                    navigate there
     data-click="#fileInput"                              forward a click
     data-stop                                            stop the event bubbling
     data-prevent                                         swallow the default only
     data-on="change"                                     listen for that instead of click

   A form with data-act submits through it, so preventDefault is automatic.
===================================================== */
(function initActions() {
    const EVENTS = ['click', 'change', 'input', 'submit', 'keydown', 'keyup', 'blur', 'focus'];

    function resolve(name) {
        // Supports "obj.method" as well as a bare function name.
        return name.split('.').reduce((carrier, part) => (carrier ? carrier[part] : undefined), window);
    }

    function argumentsFor(element, event) {
        const raw = element.dataset.args;
        if (!raw) return [];

        let parsed;
        try {
            parsed = JSON.parse(raw);
        } catch {
            console.warn('data-args is not valid JSON:', raw, element);
            return [];
        }

        const list = Array.isArray(parsed) ? parsed : [parsed];
        return list.map(value => {
            switch (value) {
                case '$el':      return element;
                case '$event':   return event;
                // The three things a handler used to reach for through `this`.
                case '$value':   return element.value;
                case '$checked': return element.checked;
                case '$files':   return element.files;
                default:         return value;
            }
        });
    }

    function run(element, event) {
        // Navigation and click-forwarding are common enough to be their own
        // attributes rather than one-line functions.
        const nav = element.dataset.nav;
        if (nav) {
            window.location.href = nav;
            return;
        }

        const forward = element.dataset.click;
        if (forward) {
            document.querySelector(forward)?.click();
            return;
        }

        const name = element.dataset.act;
        if (!name) return;

        const fn = resolve(name);
        if (typeof fn !== 'function') {
            console.warn('data-act names something that is not a function:', name, element);
            return;
        }

        fn.apply(element, argumentsFor(element, event));
    }

    EVENTS.forEach(type => {
        document.addEventListener(type, event => {
            const element = event.target.closest('[data-act],[data-nav],[data-click],[data-stop],[data-prevent]');
            if (!element) return;

            // An element only reacts to the event it asked for. Anything
            // without data-on is a click, which is the overwhelming majority.
            const wanted = element.dataset.on || (element.tagName === 'FORM' ? 'submit' : 'click');
            if (wanted !== type) return;

            // A form never reloads the page; data-prevent says the same for
            // anything else that would otherwise act on its own.
            if (type === 'submit' || element.dataset.prevent !== undefined) event.preventDefault();
            // A row inside a clickable card marks itself so the card's own
            // handler does not also fire.
            if (element.dataset.stop !== undefined) event.stopPropagation();
            run(element, event);
        }, type === 'blur' || type === 'focus');
        // Blur and focus do not bubble, so those two listen during capture.
    });
})();

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
