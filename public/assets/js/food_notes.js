/* =====================================================
   food_notes.js — foods and drinks to avoid

   Listed from the most serious reaction down. The filters ask the server, so
   the tally above the list stays the whole picture while the list narrows.
===================================================== */

const REACTIONS = [
    ['allergy',     'แพ้รุนแรง'],
    ['intolerance', 'แพ้แฝง / อาการไม่รุนแรง'],
    ['avoid',       'ควรหลีกเลี่ยง'],
    ['caution',     'ควรระวัง'],
];
const TYPE_LABEL = { food: 'อาหาร', drink: 'เครื่องดื่ม' };
const SEVERITY_LABEL = { mild: 'เล็กน้อย', moderate: 'ปานกลาง', severe: 'รุนแรง' };

let filters = { type: '', reaction: '' };
let allItems = [];
let allSummary = {};

document.addEventListener('DOMContentLoaded', load);

/* ── Load ── */
async function load() {
    const list = document.getElementById('fnList');
    try {
        const params = new URLSearchParams();
        if (filters.type)     params.set('type', filters.type);
        if (filters.reaction) params.set('reaction', filters.reaction);

        const data = await apiFetch(BASE_URL + '/api/food-notes?' + params.toString());
        allItems = data.items || [];
        allSummary = data.summary || {};
        list.removeAttribute('aria-busy');
        renderTally();
        renderList();
    } catch {
        list.removeAttribute('aria-busy');
        document.getElementById('fnTally').textContent = 'โหลดรายการไม่ได้';
        list.innerHTML = '<div class="alert alert-danger" role="alert">โหลดรายการไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="load">ลองอีกครั้ง</button></div>';
    }
}

/** "แพ้รุนแรง 2 · ควรระวัง 1": the whole picture, whatever the filters show. */
function renderTally() {
    const parts = REACTIONS.filter(([key]) => allSummary[key]).map(([key, label]) => label + ' ' + allSummary[key]);
    const filtered = filters.type || filters.reaction;
    document.getElementById('fnTally').textContent = parts.length
        ? parts.join(' · ')
        : (filtered ? 'ไม่พบรายการที่ตรงกับตัวกรอง' : 'ยังไม่มีรายการ');
}

/* ── List ── */
function itemRow(item) {
    const name = escHtml(item.name);
    const severity = SEVERITY_LABEL[item.severity];
    return '<li class="ruled-row fn-row">'
        + '<span class="grow"><span class="title">' + name + '<span class="tag fn-type">' + escHtml(TYPE_LABEL[item.type] || item.type) + '</span></span>'
        + (item.symptoms ? '<span class="meta">อาการ: ' + escHtml(item.symptoms) + '</span>' : '')
        + (item.notes ? '<span class="meta">' + escHtml(item.notes) + '</span>' : '') + '</span>'
        + (severity ? '<span class="side fn-sev" data-level="' + escHtml(item.severity) + '">' + severityMarks(item.severity) + severity + '</span>' : '')
        + '<button type="button" class="icon-btn sm" data-act="openEdit" data-args="[' + item.id + ']" aria-label="แก้ไข: ' + name + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button>'
        + '</li>';
}

/** Three squares, filled up to the level, so severity reads without colour. */
function severityMarks(severity) {
    const count = { mild: 1, moderate: 2, severe: 3 }[severity] || 0;
    return '<span class="fn-marks" aria-hidden="true">' + [1, 2, 3].map(i => '<i' + (i <= count ? ' class="on"' : '') + '></i>').join('') + '</span>';
}

function renderList() {
    const el = document.getElementById('fnList');

    if (!allItems.length) {
        const filtered = filters.type || filters.reaction;
        el.innerHTML = filtered
            ? '<div class="empty-state"><p class="empty-state-text">ไม่พบรายการที่ตรงกับตัวกรอง</p><button type="button" class="btn btn-sm" data-act="clearFilters">ล้างตัวกรอง</button></div>'
            : '<div class="empty-state"><p class="empty-state-title">ยังไม่มีรายการ</p>'
              + '<p class="empty-state-text">จดอาหารหรือเครื่องดื่มที่แพ้หรือควรเลี่ยง พร้อมอาการที่เกิด ไว้ดูตอนสั่งอาหารหรือตอนพบแพทย์</p>'
              + '<button type="button" class="btn btn-primary" data-act="openAdd">เพิ่มรายการแรก</button></div>';
        return;
    }

    el.innerHTML = REACTIONS.map(([key, label]) => {
        const rows = allItems.filter(item => item.reaction === key);
        if (!rows.length) return '';
        return '<section class="sec" aria-label="' + label + '">'
            + '<div class="sec-head"><h2>' + label + '<span class="count">' + rows.length + '</span></h2></div>'
            + '<ul class="ruled-list">' + rows.map(itemRow).join('') + '</ul></section>';
    }).join('');
}

/* ── Filters ── */
function setTypeFilter(value) {
    filters.type = value;
    document.querySelectorAll('#fnTypeFilter button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.type === value)));
    load();
}

function setReactionFilter(value) {
    filters.reaction = value;
    load();
}

function clearFilters() {
    filters = { type: '', reaction: '' };
    document.getElementById('fnReactionFilter').value = '';
    document.querySelectorAll('#fnTypeFilter button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.type === '')));
    load();
}

/* ── The form ── */
function fnError(message) {
    const line = document.getElementById('fnError');
    line.textContent = message;
    line.hidden = message === '';
}

function setSeverity(value) {
    const radio = document.querySelector('input[name="severity"][value="' + value + '"]');
    if (radio) radio.checked = true;
}

function openAdd() {
    document.getElementById('fnModalTitle').textContent = 'เพิ่มรายการ';
    document.getElementById('editId').value = '';
    document.getElementById('fnName').value = '';
    document.getElementById('fnType').value = 'food';
    document.getElementById('fnReaction').value = 'avoid';
    setSeverity('moderate');
    document.getElementById('fnSymptoms').value = '';
    document.getElementById('fnNotes').value = '';
    document.getElementById('fnDeleteBtn').hidden = true;
    fnError('');
    openModal('fnModal');
    document.getElementById('fnName').focus();
}

function openEdit(id) {
    const item = allItems.find(i => i.id === id);
    if (!item) return;

    document.getElementById('fnModalTitle').textContent = 'แก้ไขรายการ';
    document.getElementById('editId').value = item.id;
    document.getElementById('fnName').value = item.name;
    document.getElementById('fnType').value = item.type;
    document.getElementById('fnReaction').value = item.reaction;
    setSeverity(item.severity);
    document.getElementById('fnSymptoms').value = item.symptoms || '';
    document.getElementById('fnNotes').value = item.notes || '';
    document.getElementById('fnDeleteBtn').hidden = false;
    fnError('');
    openModal('fnModal');
}

async function saveItem() {
    const id = document.getElementById('editId').value;
    const body = {
        name:     document.getElementById('fnName').value.trim(),
        type:     document.getElementById('fnType').value,
        reaction: document.getElementById('fnReaction').value,
        severity: document.querySelector('input[name="severity"]:checked')?.value || 'moderate',
        symptoms: document.getElementById('fnSymptoms').value.trim(),
        notes:    document.getElementById('fnNotes').value.trim(),
    };

    if (!body.name) { fnError('ใส่ชื่ออาหารหรือเครื่องดื่มก่อน'); document.getElementById('fnName').focus(); return; }

    try {
        const url = id ? BASE_URL + '/api/food-notes/' + id : BASE_URL + '/api/food-notes';
        await apiFetch(url, { method: id ? 'PUT' : 'POST', body: JSON.stringify(body) });
        closeModal('fnModal');
        toast('บันทึกรายการแล้ว');
        load();
    } catch (err) {
        fnError(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function deleteItem() {
    const id = document.getElementById('editId').value;
    if (!id || !await confirmAction('ลบรายการนี้แล้วกู้คืนไม่ได้', 'ลบรายการ', 'ลบรายการนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/food-notes/' + id, { method: 'DELETE' });
        closeModal('fnModal');
        toast('ลบรายการแล้ว');
        load();
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
