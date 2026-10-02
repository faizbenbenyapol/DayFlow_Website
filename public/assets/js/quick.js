/* =====================================================
   quick.js — quick notes: type a line, tick it off, delete it
===================================================== */

const quickList = document.getElementById('quickList');
const quickInput = document.getElementById('quickInput');

function quickRow(item) {
    const text = escHtml(item.content);
    const done = Number(item.is_done) === 1;
    return '<div class="quick-item' + (done ? ' is-done' : '') + '">'
        + '<input type="checkbox" data-quick-toggle="' + item.id + '"' + (done ? ' checked' : '') + ' aria-label="เสร็จแล้ว: ' + text + '">'
        + '<span class="quick-text">' + text + '</span>'
        + '<button type="button" class="icon-btn sm danger" data-quick-delete="' + item.id + '" aria-label="ลบ: ' + text + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
        + '</div>';
}

async function loadQuickItems() {
    const tally = document.getElementById('quickTally');
    try {
        const data = await apiFetch(BASE_URL + '/api/quick-items');
        const items = data.items || [];
        quickList.removeAttribute('aria-busy');

        const open = items.filter(i => Number(i.is_done) !== 1);
        const done = items.filter(i => Number(i.is_done) === 1);
        tally.textContent = items.length === 0
            ? 'ยังไม่มีรายการ'
            : (open.length === 0 ? 'ทำครบทุกรายการแล้ว' : 'ค้าง ' + open.length) + (done.length ? ' · เสร็จแล้ว ' + done.length : '');

        if (items.length === 0) {
            quickList.innerHTML = '<div class="empty-state"><p class="empty-state-title">ยังไม่มีรายการ</p>'
                + '<p class="empty-state-text">พิมพ์สิ่งที่ไม่อยากลืมในช่องด้านบน แล้วกด Enter</p></div>';
            return;
        }

        quickList.innerHTML = (open.length ? '<div class="ruled-list">' + open.map(quickRow).join('') + '</div>' : '')
            + (done.length ? '<div class="subhead">เสร็จแล้ว · ' + done.length + '</div><div class="ruled-list">' + done.map(quickRow).join('') + '</div>' : '');
    } catch (e) {
        quickList.removeAttribute('aria-busy');
        tally.textContent = 'โหลดรายการไม่ได้';
        quickList.innerHTML = '<div class="alert alert-danger" role="alert">โหลดรายการไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="loadQuickItems">ลองอีกครั้ง</button></div>';
    }
}

document.getElementById('quickForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const content = quickInput.value.trim();
    if (!content) return;

    try {
        await apiFetch(BASE_URL + '/api/quick-items', { method: 'POST', body: JSON.stringify({ content }) });
        quickInput.value = '';
        document.getElementById('quickCount').textContent = '0 / 500';
        await loadQuickItems();
        quickInput.focus();
    } catch (err) {
        toast(err.message || 'จดไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
});

quickInput?.addEventListener('input', () => {
    document.getElementById('quickCount').textContent = quickInput.value.length + ' / 500';
});

quickList?.addEventListener('change', async e => {
    const box = e.target.closest('[data-quick-toggle]');
    if (!box) return;
    try {
        await apiFetch(BASE_URL + '/api/quick-items/' + box.dataset.quickToggle + '/toggle', { method: 'POST' });
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
    loadQuickItems();
});

quickList?.addEventListener('click', async e => {
    const button = e.target.closest('[data-quick-delete]');
    if (!button) return;
    try {
        await apiFetch(BASE_URL + '/api/quick-items/' + button.dataset.quickDelete, { method: 'DELETE' });
        toast('ลบแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
    loadQuickItems();
});

loadQuickItems();
