/* =====================================================
   subscriptions.js — what is charged, and when

   Everything is worked out from the next due date and today, so the list is
   drawn again when a day has passed (the tab left open, or brought back).
===================================================== */

let subs = [];
let editingSubId = null;
let renderedOn = null;

const CYCLE_LABEL = { monthly: 'รายเดือน', yearly: 'รายปี', weekly: 'รายสัปดาห์', one_time: 'ครั้งเดียว' };
const SUB_MINUS = '−';

document.addEventListener('DOMContentLoaded', async function () {
    await loadSubs();
    // A day can turn over while the page is open.
    setInterval(redrawIfDayChanged, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) redrawIfDayChanged(); });
});

function redrawIfDayChanged() {
    if (renderedOn !== null && renderedOn !== todayISO()) renderSubs();
}

async function loadSubs() {
    const grid = document.getElementById('subGrid');
    try {
        const data = await apiFetch(BASE_URL + '/api/subscriptions');
        subs = data.subscriptions || [];
        grid.removeAttribute('aria-busy');
        renderSubs();
    } catch {
        grid.removeAttribute('aria-busy');
        document.getElementById('subTally').textContent = 'โหลดรายการไม่ได้';
        grid.innerHTML = '<div class="alert alert-danger" role="alert">โหลดรายการไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="loadSubs">ลองอีกครั้ง</button></div>';
    }
}

/** What an active, repeating charge comes to in a month. A one-off has no monthly cost. */
function perMonth(sub) {
    const amount = Number(sub.amount) || 0;
    switch (sub.billing_cycle) {
        case 'monthly': return amount;
        case 'yearly':  return amount / 12;
        case 'weekly':  return amount * 52 / 12;
        default:        return 0;
    }
}

function bahtText(amount) {
    return formatMoney(amount);
}

/** "อีก 3 วัน", "วันนี้", "เกิน 2 วัน" */
function dueWords(days) {
    if (days === null) return 'ไม่ระบุวัน';
    if (days === 0) return 'วันนี้';
    return days < 0 ? 'เกิน ' + Math.abs(days) + ' วัน' : 'อีก ' + days + ' วัน';
}

/** The band a charge falls in, so the list reads as a timeline. */
function bandOf(sub) {
    if (!sub.is_active) return 'off';
    const days = daysUntil(sub.next_due_date);
    if (days === null || days > 30) return 'later';
    if (days < 0) return 'late';
    return days <= 7 ? 'week' : 'month';
}

const BANDS = [
    ['late',  'เกินกำหนด'],
    ['week',  'ภายใน 7 วัน'],
    ['month', 'ภายใน 30 วัน'],
    ['later', 'หลังจากนั้น'],
    ['off',   'ยกเลิกแล้ว (ไม่นับในยอดรวม)'],
];

function subRow(sub) {
    const days = daysUntil(sub.next_due_date);
    const name = escHtml(sub.name);
    const alert = sub.is_active && days !== null && days <= (Number(sub.alert_days) || 0);

    let timing = 'side';
    if (sub.is_active && days !== null && days < 0) timing += ' late';
    else if (alert) timing += ' soon';

    const meta = [CYCLE_LABEL[sub.billing_cycle] || escHtml(sub.billing_cycle), formatDate(sub.next_due_date)];
    const renew = sub.billing_cycle !== 'one_time' && sub.is_active
        ? '<button type="button" class="btn btn-sm" data-act="renewSub" data-args="[' + sub.id + ']" aria-label="ชำระแล้ว ต่ออายุ: ' + name + '">ชำระแล้ว</button>'
        : '';

    return '<li class="ruled-row sub-row' + (sub.is_active ? '' : ' is-off') + '">'
        + '<span class="grow"><span class="title">' + name + '</span>'
        + '<span class="meta">' + meta.join(' · ') + '</span>'
        + (sub.notes ? '<span class="meta">' + escHtml(sub.notes) + '</span>' : '') + '</span>'
        + '<span class="' + timing + '">' + dueWords(days) + '</span>'
        + '<span class="num sub-amount">' + (Number(sub.amount) > 0 ? bahtText(Number(sub.amount)) : '—') + '</span>'
        + renew
        + '<button type="button" class="icon-btn sm" data-act="openEditSub" data-args="[' + sub.id + ']" aria-label="แก้ไข: ' + name + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button>'
        + '</li>';
}

function renderFacts() {
    const active = subs.filter(s => s.is_active);
    const month = active.reduce((sum, s) => sum + perMonth(s), 0);
    const nextWeek = active.filter(s => { const d = daysUntil(s.next_due_date); return d !== null && d <= 7; });
    const weekSum = nextWeek.reduce((sum, s) => sum + (Number(s.amount) || 0), 0);

    document.getElementById('subPerMonth').innerHTML = bahtText(month) + '<small>บาท</small>';
    document.getElementById('subPerYear').innerHTML = bahtText(month * 12) + '<small>บาท</small>';
    document.getElementById('subNextWeek').innerHTML = nextWeek.length
        ? bahtText(weekSum) + '<small>บาท · ' + nextWeek.length + ' รายการ</small>'
        : '—<small>ไม่มีรายการ</small>';
    document.getElementById('subFacts').hidden = active.length === 0;

    document.getElementById('subTally').textContent = active.length === 0
        ? (subs.length ? 'ทุกรายการยกเลิกแล้ว' : 'ยังไม่มีรายการ')
        : active.length + ' รายการที่ใช้งานอยู่' + (nextWeek.length ? ' · ถึงกำหนดใน 7 วัน ' + nextWeek.length : '');
}

function renderSubs() {
    const grid = document.getElementById('subGrid');
    if (!grid) return;
    renderedOn = todayISO();
    renderFacts();

    if (!subs.length) {
        grid.innerHTML = '<div class="empty-state"><p class="empty-state-title">ยังไม่มีรายจ่ายประจำ</p>'
            + '<p class="empty-state-text">ใส่ค่าไฟ ค่าอินเทอร์เน็ต หรือ Netflix แล้วจะเห็นว่าอะไรกำลังจะตัดเงิน และเดือนหนึ่งรวมเท่าไร</p>'
            + '<button type="button" class="btn btn-primary" data-act="openAddSub">เพิ่มรายการแรก</button></div>';
        return;
    }

    const grouped = {};
    subs.forEach(sub => (grouped[bandOf(sub)] = grouped[bandOf(sub)] || []).push(sub));

    grid.innerHTML = BANDS.filter(([key]) => grouped[key]).map(([key, title]) => {
        const rows = grouped[key].sort((a, b) => (a.next_due_date || '9999').localeCompare(b.next_due_date || '9999'));
        const sum = rows.reduce((total, s) => total + (Number(s.amount) || 0), 0);
        return '<section class="sec sub-band" aria-label="' + title + '">'
            + '<div class="subhead' + (key === 'late' ? ' late' : '') + '">' + title + ' · ' + rows.length + ' รายการ'
            + (key === 'off' ? '' : ' · ' + bahtText(sum) + ' บาท') + '</div>'
            + '<ul class="ruled-list">' + rows.map(subRow).join('') + '</ul></section>';
    }).join('');
}

/* --- Renew --- */
async function renewSub(id) {
    if (!await confirmAction('วันครบกำหนดจะเลื่อนไปรอบถัดไป', 'ชำระแล้ว', 'ชำระรอบนี้แล้ว?')) return;
    try {
        await apiFetch(BASE_URL + '/api/subscriptions/' + id + '/renew', { method: 'POST' });
        await loadSubs();
        toast('เลื่อนไปรอบถัดไปแล้ว');
    } catch (err) {
        toast(err.message || 'ต่ออายุไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/* --- Modal --- */
function subError(message) {
    const line = document.getElementById('subError');
    line.textContent = message;
    line.hidden = message === '';
}

function openAddSub() {
    editingSubId = null;
    document.getElementById('subModalTitle').textContent = 'เพิ่มรายการ';
    document.getElementById('editSubId').value = '';
    document.getElementById('subName').value   = '';
    document.getElementById('subAmount').value = '';
    document.getElementById('subCycle').value  = 'monthly';
    document.getElementById('subDue').value    = todayISO();
    document.getElementById('subAlert').value  = '3';
    document.getElementById('subNotes').value  = '';
    document.getElementById('subActive').checked = true;
    document.getElementById('deleteSubBtn').hidden = true;
    subError('');
    openModal('subModal');
}

function openEditSub(id) {
    const sub = subs.find(s => s.id === id);
    if (!sub) return;
    editingSubId = id;
    document.getElementById('subModalTitle').textContent = 'แก้ไขรายการ';
    document.getElementById('editSubId').value  = id;
    document.getElementById('subName').value    = sub.name;
    document.getElementById('subAmount').value  = sub.amount;
    document.getElementById('subCycle').value   = sub.billing_cycle;
    document.getElementById('subDue').value     = sub.next_due_date;
    document.getElementById('subAlert').value   = sub.alert_days;
    document.getElementById('subNotes').value   = sub.notes || '';
    document.getElementById('subActive').checked = sub.is_active == 1;
    document.getElementById('deleteSubBtn').hidden = false;
    subError('');
    openModal('subModal');
}

async function saveSub() {
    const amount = parseFloat(String(document.getElementById('subAmount').value).replace(/,/g, '').trim());
    const body = {
        name:          document.getElementById('subName').value.trim(),
        amount:        Number.isFinite(amount) ? amount : 0,
        billing_cycle: document.getElementById('subCycle').value,
        next_due_date: document.getElementById('subDue').value,
        alert_days:    document.getElementById('subAlert').value,
        is_active:     document.getElementById('subActive').checked ? 1 : 0,
        notes:         document.getElementById('subNotes').value,
    };

    if (!body.name) { subError('ใส่ชื่อรายการก่อน'); return; }
    if (!body.next_due_date) { subError('เลือกวันครบกำหนดก่อน'); return; }

    try {
        const url    = editingSubId ? BASE_URL + '/api/subscriptions/' + editingSubId : BASE_URL + '/api/subscriptions';
        const method = editingSubId ? 'PUT' : 'POST';
        await apiFetch(url, { method, body: JSON.stringify(body) });
        closeModal('subModal');
        await loadSubs();
        toast('บันทึกรายการแล้ว');
    } catch (err) {
        subError(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function deleteSub() {
    if (!editingSubId || !await confirmAction('ลบรายการนี้แล้วกู้คืนไม่ได้ ถ้าแค่ยกเลิกแล้ว ปิด "ยังใช้งานอยู่" แทนจะเก็บประวัติไว้', 'ลบรายการ', 'ลบรายการนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/subscriptions/' + editingSubId, { method: 'DELETE' });
        closeModal('subModal');
        await loadSubs();
        toast('ลบรายการแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
