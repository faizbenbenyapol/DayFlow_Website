/* =====================================================
   food_notes.js
===================================================== */

const REACTION_LABEL = {
    allergy:     'แพ้รุนแรง',
    intolerance: 'แพ้เล็กน้อย',
    avoid:       'ทานไม่ได้',
    caution:     'ควรระวัง',
};

const TYPE_LABEL = {
    food:  'อาหาร',
    drink: 'เครื่องดื่ม',
};

let filters = { type: '', reaction: '' };
let allItems = [];

// ── Load ──────────────────────────────────────────────
async function load() {
    try {
        const params = new URLSearchParams();
        if (filters.type)     params.set('type', filters.type);
        if (filters.reaction) params.set('reaction', filters.reaction);

        const data = await apiFetch(BASE_URL + '/api/food-notes?' + params.toString());
        allItems = data.items || [];
        renderList(allItems);
        renderSummary(data.summary || {});
    } catch (err) {
        toast(err.message || 'โหลดข้อมูลไม่สำเร็จ', 'danger');
    }
}

// ── Summary ───────────────────────────────────────────
function renderSummary(s) {
    document.getElementById('cntAllergy').textContent     = s.allergy     || 0;
    document.getElementById('cntIntolerance').textContent = s.intolerance || 0;
    document.getElementById('cntAvoid').textContent       = s.avoid       || 0;
    document.getElementById('cntCaution').textContent     = s.caution     || 0;
}

// ── List ──────────────────────────────────────────────
function renderList(items) {
    const el = document.getElementById('fnList');
    if (!items.length) {
        el.innerHTML = '<div class="fn-empty">ยังไม่มีรายการ<br>กด "+ เพิ่ม" เพื่อบันทึกรายการแรก</div>';
        return;
    }

    el.innerHTML = items.map(item => `
        <div class="fn-card" onclick="openEdit(${item.id})">
            <div class="fn-card-top">
                <div class="fn-card-name">${escHtml(item.name)}</div>
                <div class="fn-card-type">${TYPE_LABEL[item.type] || item.type}</div>
            </div>
            <div class="fn-card-reaction ${item.reaction}">${REACTION_LABEL[item.reaction] || item.reaction}</div>
            ${severityDots(item.severity)}
            ${item.symptoms ? `<div class="fn-card-symptoms">${escHtml(item.symptoms)}</div>` : ''}
            ${item.notes    ? `<div class="fn-card-notes">${escHtml(item.notes)}</div>` : ''}
        </div>
    `).join('');
}

function severityDots(severity) {
    const levels = { mild: 1, moderate: 2, severe: 3 };
    const count  = levels[severity] || 0;
    const dots   = [1,2,3].map(i =>
        `<div class="fn-dot ${i <= count ? 'on ' + severity : ''}"></div>`
    ).join('');
    return `<div class="fn-severity-dots">${dots}</div>`;
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

// ── Filters ───────────────────────────────────────────
function setFilter(key, value, btn) {
    filters[key] = value;

    // Update active button in group
    btn.closest('.fn-filter-group').querySelectorAll('.fn-filter-btn').forEach(b => {
        b.classList.toggle('active', b === btn);
    });

    load();
}

// ── Modal helpers ─────────────────────────────────────
function openAdd() {
    document.getElementById('fnModalTitle').textContent = 'เพิ่มรายการ';
    document.getElementById('editId').value = '';
    document.getElementById('fnName').value = '';
    document.getElementById('fnType').value = 'food';
    document.getElementById('fnReaction').value = 'avoid';
    document.querySelector('input[name="severity"][value="moderate"]').checked = true;
    document.getElementById('fnSymptoms').value = '';
    document.getElementById('fnNotes').value = '';
    document.getElementById('fnDeleteBtn').style.display = 'none';
    openModal('fnModal');
}

function openEdit(id) {
    const item = allItems.find(i => i.id === id);
    if (!item) return;

    document.getElementById('fnModalTitle').textContent = 'แก้ไขรายการ';
    document.getElementById('editId').value             = item.id;
    document.getElementById('fnName').value             = item.name;
    document.getElementById('fnType').value             = item.type;
    document.getElementById('fnReaction').value         = item.reaction;
    const sev = document.querySelector(`input[name="severity"][value="${item.severity}"]`);
    if (sev) sev.checked = true;
    document.getElementById('fnSymptoms').value = item.symptoms || '';
    document.getElementById('fnNotes').value    = item.notes    || '';
    document.getElementById('fnDeleteBtn').style.display = '';
    openModal('fnModal');
}

// ── Save ──────────────────────────────────────────────
async function saveItem() {
    const id = document.getElementById('editId').value;
    const sevEl = document.querySelector('input[name="severity"]:checked');

    const body = {
        name:     document.getElementById('fnName').value.trim(),
        type:     document.getElementById('fnType').value,
        reaction: document.getElementById('fnReaction').value,
        severity: sevEl ? sevEl.value : 'moderate',
        symptoms: document.getElementById('fnSymptoms').value.trim(),
        notes:    document.getElementById('fnNotes').value.trim(),
    };

    if (!body.name) { toast('กรุณากรอกชื่อ', 'danger'); return; }

    try {
        if (id) {
            await apiFetch(BASE_URL + '/api/food-notes/' + id, { method: 'PUT', body: JSON.stringify(body) });
            toast('แก้ไขแล้ว');
        } else {
            await apiFetch(BASE_URL + '/api/food-notes', { method: 'POST', body: JSON.stringify(body) });
            toast('เพิ่มแล้ว');
        }
        closeModal('fnModal');
        load();
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

// ── Delete ────────────────────────────────────────────
async function deleteItem() {
    const id = document.getElementById('editId').value;
    if (!id) return;
    if (!await confirmAction('ต้องการลบรายการนี้?', 'ลบ')) return;
    try {
        await apiFetch(BASE_URL + '/api/food-notes/' + id, { method: 'DELETE' });
        closeModal('fnModal');
        toast('ลบแล้ว');
        load();
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ', 'danger');
    }
}

// ── Init ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', load);
