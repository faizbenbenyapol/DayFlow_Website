/* =====================================================
   exercise.js — the workout log

   The log is read a month at a time. The type suggestions come from the
   person's own categories, and anything else typed is accepted as a new type.
===================================================== */

let workouts = [];
let editingId = null;
let exMonth = new Date().getFullYear() + '-' + String(new Date().getMonth() + 1).padStart(2, '0');

const MONTHS_FULL = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

const DEFAULT_TYPES = ['วิ่ง', 'ยกน้ำหนัก', 'ว่ายน้ำ', 'ปั่นจักรยาน', 'โยคะ', 'HIIT', 'เดิน', 'กระโดดเชือก'];

document.addEventListener('DOMContentLoaded', function () {
    loadWorkouts();
    loadStats();
    loadTypeSuggestions();
});

function showListError(id, retry) {
    const el = document.getElementById(id);
    el.removeAttribute('aria-busy');
    el.innerHTML = '<div class="alert alert-danger" role="alert">โหลดข้อมูลไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
        + '<button type="button" class="btn btn-sm" data-act="' + retry + '">ลองอีกครั้ง</button></div>';
}

async function loadWorkouts() {
    const [year, month] = exMonth.split('-').map(Number);
    document.getElementById('monthLabel').textContent = MONTHS_FULL[month - 1] + ' ' + (year + 543);
    try {
        const data = await apiFetch(BASE_URL + '/api/exercise?limit=100&month=' + exMonth);
        workouts = data.workouts || [];
        document.getElementById('workoutList').removeAttribute('aria-busy');
        renderWorkouts();
    } catch {
        document.getElementById('exTally').textContent = 'โหลดบันทึกไม่ได้';
        showListError('workoutList', 'loadWorkouts');
    }
}

async function loadStats() {
    try {
        const data = await apiFetch(BASE_URL + '/api/exercise/stats');
        const sessions = data.month_sessions || 0;
        document.getElementById('statSessions').innerHTML = sessions + '<small>ครั้ง</small>';
        document.getElementById('statMinutes').innerHTML = (data.month_minutes || 0) + '<small>นาที</small>';
        document.getElementById('statTopType').textContent = data.by_type && data.by_type[0] ? data.by_type[0].type : '—';
        renderTypeStats(data.by_type || []);
    } catch {
        showListError('typeStats', 'loadStats');
    }
}

/** Offers the person's own categories when typing a type, else a starter list. */
async function loadTypeSuggestions() {
    let types = DEFAULT_TYPES;
    try {
        const data = await apiFetch(BASE_URL + '/api/exercise/categories');
        if (data.categories && data.categories.length) types = data.categories.map(c => c.name);
    } catch { /* the starter list does */ }
    document.getElementById('workoutTypes').innerHTML = types.map(t => '<option value="' + escHtml(t) + '"></option>').join('');
}

/* --- The log --- */
function workoutDetails(w) {
    const parts = [];
    if (w.duration_min) parts.push(w.duration_min + ' นาที');
    if (w.sets || w.reps) parts.push((w.sets || '—') + ' เซต × ' + (w.reps || '—') + ' ครั้ง');
    if (w.weight_kg) parts.push(w.weight_kg + ' กก.');
    return parts.join(' · ');
}

function workoutRow(w) {
    const label = escHtml(w.type) + ' ' + escHtml(formatDate(w.workout_date));
    const details = workoutDetails(w);
    return '<li class="ruled-row ex-row">'
        + '<span class="ex-date">' + escHtml(formatDate(w.workout_date)) + '</span>'
        + '<span class="grow"><span class="title">' + escHtml(w.type) + '</span>'
        + (details ? '<span class="meta">' + details + '</span>' : '')
        + (w.notes ? '<span class="meta">' + escHtml(w.notes) + '</span>' : '') + '</span>'
        + '<button type="button" class="icon-btn sm" data-act="openEditWorkout" data-args="[' + w.id + ']" aria-label="แก้ไข: ' + label + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button>'
        + '<button type="button" class="icon-btn sm" data-act="deleteWorkout" data-args="[' + w.id + ']" aria-label="ลบ: ' + label + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
        + '</li>';
}

function renderWorkouts() {
    const el = document.getElementById('workoutList');
    const count = document.getElementById('exCount');
    const now = new Date();
    const thisMonth = exMonth === now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');

    document.getElementById('exTally').textContent = workouts.length
        ? workouts.length + ' ครั้งในเดือนที่เลือก'
        : 'ยังไม่มีบันทึกในเดือนที่เลือก';

    if (!workouts.length) {
        count.textContent = '';
        el.innerHTML = '<div class="empty-state"><p class="empty-state-title">ไม่มีบันทึกในเดือนนี้</p>'
            + '<p class="empty-state-text">' + (thisMonth ? 'บันทึกการออกกำลังกายครั้งแรกของเดือน แล้วสถิติจะขึ้นด้านบน' : 'เลือกเดือนอื่น หรือเพิ่มบันทึกย้อนหลัง') + '</p>'
            + '<button type="button" class="btn btn-primary" data-act="openAddWorkout">บันทึกการออกกำลังกาย</button></div>';
        return;
    }

    count.textContent = workouts.length;
    el.innerHTML = '<ul class="ruled-list">' + workouts.map(workoutRow).join('') + '</ul>';
}

function renderTypeStats(byType) {
    const el = document.getElementById('typeStats');
    el.removeAttribute('aria-busy');
    if (!byType.length) {
        el.innerHTML = '<p class="ex-empty">ยังไม่มีสถิติ บันทึกการออกกำลังกายสักครั้งก่อน</p>';
        return;
    }

    const max = Math.max(...byType.map(t => t.sessions));
    el.innerHTML = '<ul class="ex-types">' + byType.map(t => {
        const minutes = Number(t.total_min) > 0 ? ' · ' + t.total_min + ' นาที' : '';
        return '<li><div class="ex-type-line"><span>' + escHtml(t.type) + '</span><span class="ex-type-count">' + t.sessions + ' ครั้ง' + minutes + '</span></div>'
            + '<div class="progress ex-bar" aria-hidden="true"><div class="progress-bar" style="--v:' + Math.round(t.sessions / max * 100) + '%"></div></div></li>';
    }).join('') + '</ul>';
}

function moveMonth(delta) {
    const [year, month] = exMonth.split('-').map(Number);
    const moved = new Date(year, month - 1 + delta, 1);
    exMonth = moved.getFullYear() + '-' + String(moved.getMonth() + 1).padStart(2, '0');
    loadWorkouts();
}

/* --- The form --- */
function workoutError(message) {
    const line = document.getElementById('workoutError');
    line.textContent = message;
    line.hidden = message === '';
}

function openAddWorkout() {
    editingId = null;
    document.getElementById('workoutModalTitle').textContent = 'บันทึกการออกกำลังกาย';
    document.getElementById('editWorkoutId').value = '';
    document.getElementById('workoutDate').value     = todayISO();
    document.getElementById('workoutType').value     = '';
    document.getElementById('workoutDuration').value = '';
    document.getElementById('workoutSets').value     = '';
    document.getElementById('workoutReps').value     = '';
    document.getElementById('workoutWeight').value   = '';
    document.getElementById('workoutNotes').value    = '';
    workoutError('');
    openModal('workoutModal');
    document.getElementById('workoutType').focus();
}

function openEditWorkout(id) {
    const w = workouts.find(x => x.id === id);
    if (!w) return;
    editingId = id;
    document.getElementById('workoutModalTitle').textContent = 'แก้ไขการออกกำลังกาย';
    document.getElementById('editWorkoutId').value    = id;
    document.getElementById('workoutDate').value      = w.workout_date;
    document.getElementById('workoutType').value      = w.type;
    document.getElementById('workoutDuration').value  = w.duration_min || '';
    document.getElementById('workoutSets').value      = w.sets || '';
    document.getElementById('workoutReps').value      = w.reps || '';
    document.getElementById('workoutWeight').value    = w.weight_kg || '';
    document.getElementById('workoutNotes').value     = w.notes || '';
    workoutError('');
    openModal('workoutModal');
}

async function saveWorkout() {
    const body = {
        workout_date: document.getElementById('workoutDate').value,
        type:         document.getElementById('workoutType').value.trim(),
        duration_min: document.getElementById('workoutDuration').value,
        sets:         document.getElementById('workoutSets').value,
        reps:         document.getElementById('workoutReps').value,
        weight_kg:    document.getElementById('workoutWeight').value,
        notes:        document.getElementById('workoutNotes').value
    };

    if (!body.type) { workoutError('ใส่ประเภทการออกกำลังกายก่อน'); document.getElementById('workoutType').focus(); return; }
    if (!body.workout_date) { workoutError('เลือกวันที่ก่อน'); return; }

    try {
        const url    = editingId ? BASE_URL + '/api/exercise/' + editingId : BASE_URL + '/api/exercise';
        await apiFetch(url, { method: editingId ? 'PUT' : 'POST', body: JSON.stringify(body) });
        closeModal('workoutModal');
        await Promise.all([loadWorkouts(), loadStats()]);
        toast('บันทึกรายการแล้ว');
    } catch (err) {
        workoutError(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function deleteWorkout(id) {
    if (!await confirmAction('ลบรายการนี้แล้วกู้คืนไม่ได้', 'ลบรายการ', 'ลบรายการนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/exercise/' + id, { method: 'DELETE' });
        await Promise.all([loadWorkouts(), loadStats()]);
        toast('ลบรายการแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
