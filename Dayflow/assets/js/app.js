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

    const search = debounce(async () => {
        const q = input.value.trim();
        if (q.length < 2) { results.hidden = true; return; }
        try {
            const data = await apiFetch(`${BASE_URL}/api/search?q=${encodeURIComponent(q)}`);
            render(data.results || []);
        } catch (error) {
            results.innerHTML = '<div class="global-search-empty">ค้นหาไม่สำเร็จ ลองใหม่อีกครั้ง</div>';
            results.hidden = false;
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
