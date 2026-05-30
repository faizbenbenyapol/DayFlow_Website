/* =====================================================
   subscriptions.js — Countdown & Subscription tracker
===================================================== */

let subs = [];
let editingSubId = null;
let countdownTimer = null;

document.addEventListener('DOMContentLoaded', async function () {
    await loadSubs();
    // Update countdowns every minute
    countdownTimer = setInterval(updateCountdowns, 60000);
});

async function loadSubs() {
    try {
        const data = await apiFetch(BASE_URL + '/api/subscriptions');
        subs = data.subscriptions || [];
        renderSubs();
    } catch {
        toast('โหลดข้อมูลไม่สำเร็จ', 'danger');
    }
}

function renderSubs() {
    const grid = document.getElementById('subGrid');
    if (!grid) return;

    if (!subs.length) {
        grid.innerHTML = '<div class="card" style="grid-column:1/-1;padding:3rem;text-align:center"><div class="empty-state-title">ยังไม่มีรายการ</div><div class="empty-state-text">กดปุ่ม "+ เพิ่ม" เพื่อเริ่มต้น</div></div>';
        return;
    }

    // Sort: most urgent first (smallest days-until), nulls last, inactive last
    const sorted = [...subs].sort((a, b) => {
        if (a.is_active !== b.is_active) return (b.is_active ? 1 : 0) - (a.is_active ? 1 : 0);
        const da = daysUntil(a.next_due_date);
        const db = daysUntil(b.next_due_date);
        if (da === null && db === null) return 0;
        if (da === null) return 1;
        if (db === null) return -1;
        return da - db;
    });

    grid.innerHTML = sorted.map(sub => {
        const days = daysUntil(sub.next_due_date);
        const cycleLabel = { monthly:'รายเดือน', yearly:'รายปี', weekly:'รายสัปดาห์', one_time:'ครั้งเดียว' };
        const cycleClass = { monthly:'sub-cycle-monthly', yearly:'sub-cycle-yearly', weekly:'sub-cycle-weekly', one_time:'sub-cycle-once' };

        let urgencyCard = 'urgency-ok';
        if (days !== null && days < 0)              urgencyCard = 'urgency-danger';
        else if (days !== null && days === 0)        urgencyCard = 'urgency-danger';
        else if (days !== null && days <= sub.alert_days) urgencyCard = 'urgency-warn';

        return `
        <div class="card sub-card ${urgencyCard} ${!sub.is_active ? 'opacity-50' : ''}" data-sub-id="${sub.id}">
            <div class="sub-card-header">
                <div>
                    <div class="sub-card-title">${escHtml(sub.name)}</div>
                    <div class="sub-card-meta"><span class="sub-cycle-badge ${cycleClass[sub.billing_cycle] || ''}">${cycleLabel[sub.billing_cycle] || sub.billing_cycle}</span>${sub.amount > 0 ? ' · ' + formatMoney(sub.amount) + ' บาท' : ''}</div>
                </div>
                <button class="btn btn-ghost btn-sm" onclick="openEditSub(${sub.id})">แก้ไข</button>
            </div>
            <div class="sub-due-row" data-date="${sub.next_due_date}" data-alert="${sub.alert_days}">
                <div>
                    <div class="sub-due-label">ครบกำหนด</div>
                    <div class="sub-due-date">${formatDate(sub.next_due_date)}</div>
                </div>
                ${buildPill(days, sub.alert_days)}
            </div>
            ${sub.notes ? `<div class="sub-card-notes">${escHtml(sub.notes)}</div>` : ''}
            ${sub.billing_cycle !== 'one_time' && sub.is_active ? `<button class="btn btn-ghost btn-sm" onclick="renewSub(${sub.id})">ต่ออายุ / ชำระแล้ว</button>` : ''}
        </div>`;
    }).join('');
}

function buildPill(days, alertDays) {
    if (days === null) {
        return `<span class="sub-pill tier-none"><span class="sub-pill-text">—</span></span>`;
    }
    if (days < 0) {
        return `<span class="sub-pill tier-overdue pulse">
            <span class="sub-pill-top">เกิน</span>
            <span class="sub-pill-num">${Math.abs(days)}</span>
            <span class="sub-pill-bot">วัน</span>
        </span>`;
    }
    if (days === 0) {
        return `<span class="sub-pill tier-today pulse"><span class="sub-pill-text">วันนี้!</span></span>`;
    }

    // Color tier by proximity
    let tier;
    if (days === 1)                   tier = 'tier-critical'; // red — พรุ่งนี้
    else if (days <= 3)               tier = 'tier-urgent';   // orange — 2-3 วัน
    else if (days <= (alertDays || 7)) tier = 'tier-warn';    // yellow — ภายใน alert window
    else if (days <= 14)              tier = 'tier-soon';     // blue — 1-2 สัปดาห์
    else                              tier = 'tier-safe';     // green — ไกล

    return `<span class="sub-pill ${tier}">
        <span class="sub-pill-top">อีก</span>
        <span class="sub-pill-num">${days}</span>
        <span class="sub-pill-bot">วัน</span>
    </span>`;
}

function updateCountdowns() {
    document.querySelectorAll('.sub-due-row').forEach(el => {
        const date = el.dataset.date;
        const alertDays = parseInt(el.dataset.alert) || 3;
        const days = daysUntil(date);
        if (days === null) return;

        const pill = el.querySelector('.sub-pill');
        if (pill) {
            const tmp = document.createElement('div');
            tmp.innerHTML = buildPill(days, alertDays);
            pill.replaceWith(tmp.firstElementChild);
        }

        // Update card border
        const card = el.closest('.sub-card');
        if (card) {
            card.classList.remove('urgency-ok', 'urgency-warn', 'urgency-danger');
            if (days < 0 || days === 0)         card.classList.add('urgency-danger');
            else if (days <= alertDays)          card.classList.add('urgency-warn');
            else                                 card.classList.add('urgency-ok');
        }
    });
}

/* --- Renew --- */
async function renewSub(id) {
    if (!await confirmAction('ต่ออายุ / ชำระแล้ว?\nวันครบกำหนดจะถูกเลื่อนไปรอบถัดไป', 'ต่ออายุ / ชำระแล้ว')) return;
    try {
        await apiFetch(BASE_URL + '/api/subscriptions/' + id + '/renew', { method: 'POST' });
        await loadSubs();
        toast('ต่ออายุแล้ว');
    } catch (err) {
        toast(err.message || 'เกิดข้อผิดพลาด', 'danger');
    }
}

/* --- Modal --- */
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
    document.getElementById('deleteSubBtn').style.display = 'none';
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
    document.getElementById('deleteSubBtn').style.display = 'block';
    openModal('subModal');
}

async function saveSub() {
    const body = {
        name:          document.getElementById('subName').value.trim(),
        amount:        document.getElementById('subAmount').value,
        billing_cycle: document.getElementById('subCycle').value,
        next_due_date: document.getElementById('subDue').value,
        alert_days:    document.getElementById('subAlert').value,
        is_active:     document.getElementById('subActive').checked ? 1 : 0,
        notes:         document.getElementById('subNotes').value,
    };

    if (!body.name) { toast('กรุณากรอกชื่อรายการ', 'danger'); return; }
    if (!body.next_due_date) { toast('กรุณาเลือกวันครบกำหนด', 'danger'); return; }

    try {
        const url    = editingSubId ? BASE_URL + '/api/subscriptions/' + editingSubId : BASE_URL + '/api/subscriptions';
        const method = editingSubId ? 'PUT' : 'POST';
        await apiFetch(url, { method, body: JSON.stringify(body) });
        closeModal('subModal');
        await loadSubs();
        toast('บันทึกแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

async function deleteSub() {
    if (!editingSubId || !await confirmAction('ต้องการลบรายการนี้?', 'ลบ')) return;
    await apiFetch(BASE_URL + '/api/subscriptions/' + editingSubId, { method: 'DELETE' });
    closeModal('subModal');
    await loadSubs();
    toast('ลบแล้ว');
}

function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
