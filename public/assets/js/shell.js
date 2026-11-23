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
   Quick add: a task or a money entry, from any page.
   The old "note" tab is merged into the task tab: it lands in the inbox.
   --------------------------------------------------- */
(function initQuickAdd() {
    const sheet = document.getElementById('quickSheet');
    const form = document.getElementById('quickForm');
    if (!sheet || !form) return; // share mode and the login page have no frame

    const tabs = Array.from(sheet.querySelectorAll('[role="tab"]'));
    const statusLine = document.getElementById('quickStatus');
    const submit = document.getElementById('quickSubmit');
    const category = document.getElementById('qaCategory');
    // form.title would be the form's own title attribute, not the field of that name.
    const field = form.elements;

    let kind = 'task';
    let categories = null; // fetched the first time money is opened
    let busy = false;

    function moneyType() {
        return form.querySelector('input[name="type"]:checked')?.value === 'income' ? 'income' : 'expense';
    }

    function submitLabel() {
        if (kind === 'task') return 'เพิ่มงาน';
        return moneyType() === 'income' ? 'บันทึกรายรับ' : 'บันทึกรายจ่าย';
    }

    function showStatus(message, type) {
        statusLine.textContent = message;
        statusLine.hidden = message === '';
        statusLine.className = 'form-status' + (type ? ' is-' + type : '');
        statusLine.setAttribute('role', type === 'error' ? 'alert' : 'status');
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
        showStatus('');
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
        form.querySelector('#qaMoneyDate').value = serverToday();
        showStatus('');
    }

    function focusFirst() {
        const pane = document.getElementById('qaPane-' + kind);
        pane.querySelector('input:not([type="radio"]), textarea, select')?.focus();
    }

    window.openQuickAdd = function (requested) {
        // 'note' แบบเก่าให้เปิดแท็บงาน 'money' เปิดแท็บเงิน ค่าเริ่มต้นเป็นงาน
        const wanted = requested === 'money' ? 'money'
            : (['task', 'note'].includes(requested) ? 'task' : kind);
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

    const REFRESH_ON = { task: ['/', '/tasks'], money: ['/', '/finance'] };

    /** อ่านค่าวันที่ที่จะบันทึก: วันที่ในรายละเอียดชนะชิปวัน ชิปส่ง today/tomorrow ให้เซิร์ฟเวอร์ตัดสิน */
    function taskDueDate() {
        const detailDate = String(field.due_date.value || '').trim();
        if (detailDate) return detailDate;
        const when = form.querySelector('input[name="qa_when"]:checked')?.value;
        if (when === 'today') return 'today';
        if (when === 'tomorrow') return 'tomorrow';
        return '';
    }

    /** ชื่อที่แสดงของวันที่ที่เลือก สำหรับบรรทัดสถานะ */
    function taskDateLabel(dueDate) {
        if (dueDate === 'today' || dueDate === serverToday()) return 'วันนี้';
        if (dueDate === 'tomorrow' || dueDate === serverTomorrow()) return 'พรุ่งนี้';
        if (!dueDate) return 'ยังไม่จัด';
        return dueDate;
    }

    async function send() {
        if (kind === 'task') {
            const title = field.title.value.trim();
            if (title === '') throw new Error('พิมพ์งานก่อน');
            const dueDate = taskDueDate();
            await apiFetch(BASE_URL + '/api/tasks', {
                method: 'POST',
                body: JSON.stringify({
                    title,
                    quadrant: Number(field.quadrant.value),
                    due_date: dueDate,
                }),
            });
            return { title, label: taskDateLabel(dueDate), kind: 'task' };
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
                txn_date: field.txn_date.value || serverToday(),
            }),
        });
        return { title: type === 'income' ? 'รายรับ' : 'รายจ่าย', label: '', kind: 'money', moneyType: type };
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        submit.disabled = true;
        showStatus('');

        const sentKind = kind;
        try {
            const result = await send();
            if (sentKind === 'task') {
                field.title.value = '';
                showStatus('✓ บันทึก "' + result.title + '" ลง "' + result.label + '" แล้ว', 'success');
                document.dispatchEvent(new CustomEvent('dayflow:added', { detail: { kind: sentKind }, cancelable: true }));
                focusFirst();
            } else {
                sheet.close();
                toast(result.moneyType === 'income' ? 'บันทึกรายรับแล้ว' : 'บันทึกรายจ่ายแล้ว');
                // A page that shows this kind of thing can update itself by
                // cancelling the event; otherwise the page reloads to show it.
                const handled = !document.dispatchEvent(new CustomEvent('dayflow:added', { detail: { kind: sentKind }, cancelable: true }));
                if (!handled && REFRESH_ON[sentKind].includes(currentPath())) setTimeout(() => window.location.reload(), 600);
            }
        } catch (error) {
            if (sentKind === 'task' && error.message === 'พิมพ์งานก่อน') {
                showStatus(error.message, 'error');
            } else {
                showStatus('บันทึกไม่ได้ ตรวจการเชื่อมต่อแล้วกด Enter อีกครั้ง', 'error');
            }
            focusFirst();
        } finally {
            busy = false;
            submit.disabled = false;
        }
    });

    // กด Enter ในช่องชื่องานบันทึกทันที โดยไม่ต้องรอปุ่ม submit
    const titleInput = document.getElementById('qaTaskTitle');
    if (titleInput) {
        titleInput.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !busy) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
    }

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
})();

/* ---------------------------------------------------
   Keyboard shortcut: N opens the task sheet anywhere
   --------------------------------------------------- */
(function initQuickAddShortcut() {
    // ปุ่มที่พิมพ์ n ได้ หรือปุ่มตำแหน่ง N เมื่อแป้นไม่ใช่อักษรละติน (เช่นแป้นไทยที่ได้ ื)
    // แป้นละตินแบบอื่นอย่าง Dvorak ปุ่มตำแหน่ง N พิมพ์ b จึงไม่นับ
    const isN = event => event.key === 'n' || event.key === 'N'
        || (event.code === 'KeyN' && !/^[a-z]$/i.test(event.key));

    const allowed = event => {
        if (!isN(event)) return false;
        if (event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) return false;
        const active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT' || active.isContentEditable)) return false;
        if (document.querySelector('dialog[open]')) return false;
        // ไม่เปิดช่องจดทับ modal ของแอปที่กำลังแสดง
        return !document.querySelector('.modal-backdrop.active');
    };

    document.addEventListener('keydown', event => {
        if (!allowed(event)) return;
        event.preventDefault();
        if (typeof window.openQuickAdd === 'function') window.openQuickAdd('task');
    });
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
