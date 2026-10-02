/* =====================================================
   shell.js — the parts of the frame that act: the "all menus" sheet, the
   quick-add sheet, and the soft-keyboard helper for phones.

   The frame itself (rail, tab bar, sheets) is rendered by views/layout/header.php.
   Buttons reach these functions through data-act, see actions.js.
===================================================== */

/* ---------------------------------------------------
   Sheets
   <dialog> brings focus handling, Escape to close and a backdrop for free.
   On a phone it slides up from the bottom; on a wide screen it is centred.
--------------------------------------------------- */
function openMenuSheet() {
    const sheet = document.getElementById('menuSheet');
    if (sheet && !sheet.open) sheet.showModal();
}

function closeSheet(id) {
    document.getElementById(id)?.close();
}

// The palette opens over the page, so the sheet has to get out of its way first.
function searchFromSheet() {
    closeSheet('menuSheet');
    if (typeof window.openCommandPalette === 'function') window.openCommandPalette();
}

// A click that lands on the <dialog> itself is a click on its backdrop: the
// content fills the whole box, so nothing else can be the target.
document.addEventListener('click', event => {
    const target = event.target;
    if (target instanceof HTMLDialogElement && target.classList.contains('sheet')) target.close();
});

/* ---------------------------------------------------
   Quick add: a task, a quick note or a money entry, from any page
--------------------------------------------------- */
(function initQuickAdd() {
    const sheet = document.getElementById('quickSheet');
    const form = document.getElementById('quickForm');
    if (!sheet || !form) return; // share mode and the login page have no frame

    const tabs = Array.from(sheet.querySelectorAll('[role="tab"]'));
    const errorLine = document.getElementById('quickError');
    const submit = document.getElementById('quickSubmit');
    const noteBox = document.getElementById('qaNoteContent');
    const noteCount = document.getElementById('qaNoteCount');
    const category = document.getElementById('qaCategory');
    // form.title would be the form's own title attribute, not the field of that name.
    const field = form.elements;

    let kind = 'task';
    let categories = null; // fetched the first time money is opened
    let busy = false;

    /** Today as YYYY-MM-DD in the visitor's own time zone (toISOString would give UTC's date). */
    function localToday() {
        const now = new Date();
        return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    }

    function moneyType() {
        return form.querySelector('input[name="type"]:checked')?.value === 'income' ? 'income' : 'expense';
    }

    function submitLabel() {
        if (kind === 'task') return 'เพิ่มงาน';
        if (kind === 'note') return 'จดไว้';
        return moneyType() === 'income' ? 'บันทึกรายรับ' : 'บันทึกรายจ่าย';
    }

    function showError(message) {
        errorLine.textContent = message;
        errorLine.hidden = message === '';
    }

    function select(next) {
        kind = next;
        tabs.forEach(tab => {
            const on = tab.dataset.kind === next;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            document.getElementById(tab.getAttribute('aria-controls')).hidden = !on;
        });
        submit.textContent = submitLabel();
        showError('');
        if (next === 'money') loadCategories();
    }

    async function loadCategories() {
        if (categories !== null) return fillCategories();
        try {
            const data = await apiFetch(BASE_URL + '/api/finance/categories');
            categories = data.categories || [];
        } catch {
            categories = []; // the field stays "ไม่ระบุหมวด"; adding still works
        }
        fillCategories();
    }

    function fillCategories() {
        const type = moneyType();
        const keep = category.value;
        category.innerHTML = '<option value="0">ไม่ระบุหมวด</option>';
        (categories || [])
            .filter(c => c.type === type || c.type === 'both')
            .forEach(c => {
                const option = document.createElement('option');
                option.value = c.id;
                option.textContent = c.name;
                category.appendChild(option);
            });
        category.value = [...category.options].some(o => o.value === keep) ? keep : '0';
    }

    function reset() {
        form.reset();
        form.querySelector('#qaTaskDue').value = localToday();
        form.querySelector('#qaMoneyDate').value = localToday();
        noteCount.textContent = '0 / 500';
        showError('');
    }

    function focusFirst() {
        const pane = document.getElementById('qaPane-' + kind);
        pane.querySelector('input:not([type="radio"]), textarea')?.focus();
    }

    window.openQuickAdd = function (requested) {
        // The tab bar button passes the click's element; only a real kind counts.
        const wanted = ['task', 'note', 'money'].includes(requested) ? requested : kind;
        if (!sheet.open) {
            reset();
            sheet.showModal();
        }
        select(wanted);
        focusFirst();
    };

    // Which page this is, without the app's base folder, to decide if it needs refreshing.
    function currentPath() {
        const base = new URL(BASE_URL).pathname.replace(/\/$/, '');
        return window.location.pathname.slice(base.length).replace(/\/$/, '') || '/';
    }

    const REFRESH_ON = { task: ['/', '/tasks'], note: ['/', '/quick-notes'], money: ['/', '/finance'] };

    async function send() {
        if (kind === 'task') {
            const title = field.title.value.trim();
            if (title === '') throw new Error('ใส่ชื่องานก่อน');
            await apiFetch(BASE_URL + '/api/tasks', {
                method: 'POST',
                body: JSON.stringify({
                    title,
                    quadrant: Number(field.quadrant.value),
                    due_date: field.due_date.value,
                }),
            });
            return 'เพิ่มงานแล้ว';
        }

        if (kind === 'note') {
            const content = noteBox.value.trim();
            if (content === '') throw new Error('พิมพ์ข้อความก่อน');
            await apiFetch(BASE_URL + '/api/quick-items', { method: 'POST', body: JSON.stringify({ content }) });
            return 'จดไว้แล้ว';
        }

        const amount = Number(String(field.amount.value).replace(/,/g, ''));
        if (!(amount > 0)) throw new Error('ใส่จำนวนเงินเป็นตัวเลขที่มากกว่า 0');
        const type = moneyType();
        await apiFetch(BASE_URL + '/api/finance', {
            method: 'POST',
            body: JSON.stringify({
                type,
                amount,
                category_id: Number(field.category_id.value),
                description: field.description.value.trim(),
                txn_date: field.txn_date.value || localToday(),
            }),
        });
        return type === 'income' ? 'บันทึกรายรับแล้ว' : 'บันทึกรายจ่ายแล้ว';
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        submit.disabled = true;
        showError('');

        const sentKind = kind;
        try {
            const message = await send();
            sheet.close();
            toast(message);

            // A page that shows this kind of thing can update itself by
            // cancelling the event; otherwise the page reloads to show it.
            const handled = !document.dispatchEvent(new CustomEvent('dayflow:added', { detail: { kind: sentKind }, cancelable: true }));
            if (!handled && REFRESH_ON[sentKind].includes(currentPath())) setTimeout(() => window.location.reload(), 600);
        } catch (error) {
            showError(error.message || 'เพิ่มไม่สำเร็จ ลองอีกครั้ง');
        } finally {
            busy = false;
            submit.disabled = false;
        }
    });

    // Tabs: click or arrow keys, as a tab list should behave.
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => { select(tab.dataset.kind); focusFirst(); });
        tab.addEventListener('keydown', event => {
            const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
            if (step === 0) return;
            event.preventDefault();
            const next = tabs[(index + step + tabs.length) % tabs.length];
            next.focus();
            select(next.dataset.kind);
        });
    });

    form.querySelectorAll('input[name="type"]').forEach(radio => radio.addEventListener('change', () => {
        submit.textContent = submitLabel();
        fillCategories();
    }));

    noteBox.addEventListener('input', () => { noteCount.textContent = noteBox.value.length + ' / 500'; });
})();

/* ---------------------------------------------------
   Soft keyboard: on a touch screen, scroll a field into view once the
   keyboard has had time to open, so it is not hidden behind it.
--------------------------------------------------- */
if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
    document.addEventListener('focusin', event => {
        const el = event.target;
        if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
        setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 280);
    });
}

/* The shortcut hint says what this keyboard calls the key. */
if (/Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)) {
    document.querySelectorAll('[data-shortcut]').forEach(el => { el.textContent = '⌘ K'; });
}
