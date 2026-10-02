/* =====================================================
   habits.js — the attendance sheet for one week at a time

   /api/habits?from=&to= returns the habits (with streaks) and which days each
   was done in that window. Weeks run Sunday to Saturday, like the planner.
===================================================== */

const habitsState = { items: [], logs: {}, weekStart: null };
const HABIT_DAYS_SHORT = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
const HABIT_DAYS_FULL = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
const HABIT_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

/** YYYY-MM-DD for a Date, in local time. */
function habitIso(date) {
    return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
}

/** The Sunday on or before a date. */
function weekStartOf(date) {
    const start = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    start.setDate(start.getDate() - start.getDay());
    return start;
}

function weekDays() {
    return Array.from({ length: 7 }, (_, i) => {
        const day = new Date(habitsState.weekStart);
        day.setDate(day.getDate() + i);
        return day;
    });
}

async function loadHabits() {
    const grid = document.getElementById('habitsGrid');
    const days = weekDays();
    const from = habitIso(days[0]);
    const to = habitIso(days[6]);

    try {
        const data = await apiFetch(BASE_URL + '/api/habits?from=' + from + '&to=' + to);
        habitsState.items = data.habits || [];
        habitsState.logs = data.logs || {};
        grid.removeAttribute('aria-busy');
        renderHabits();
    } catch (e) {
        grid.removeAttribute('aria-busy');
        grid.innerHTML = '<div class="alert alert-danger" role="alert">โหลดนิสัยไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="loadHabits">ลองอีกครั้ง</button></div>';
        document.getElementById('habitsSummary').textContent = 'โหลดนิสัยไม่ได้';
    }
}

function renderWeekLabel(days) {
    const first = days[0];
    const last = days[6];
    const a = first.getDate() + (first.getMonth() === last.getMonth() ? '' : ' ' + HABIT_MONTHS[first.getMonth()]);
    document.getElementById('weekLabel').textContent =
        a + ' – ' + last.getDate() + ' ' + HABIT_MONTHS[last.getMonth()] + ' ' + (last.getFullYear() + 543);

    const current = habitIso(weekStartOf(new Date())) === habitIso(first);
    document.getElementById('weekToday').hidden = current;
}

function renderHabits() {
    const grid = document.getElementById('habitsGrid');
    const items = habitsState.items;
    const days = weekDays();
    const today = habitIso(new Date());
    renderWeekLabel(days);

    const doneToday = items.filter(h => Number(h.completed_today) === 1).length;
    document.getElementById('habitsSummary').textContent = items.length
        ? 'วันนี้ทำแล้ว ' + doneToday + ' จาก ' + items.length + ' นิสัย'
        : 'ยังไม่ได้ตั้งนิสัย';

    if (!items.length) {
        grid.innerHTML = '<div class="empty-state"><p class="empty-state-title">ยังไม่มีนิสัย</p>'
            + '<p class="empty-state-text">เริ่มจากสิ่งเล็ก ๆ ที่ทำได้ทุกวัน เช่น ดื่มน้ำ 8 แก้ว หรืออ่านหนังสือ 20 นาที</p>'
            + '<button type="button" class="btn btn-primary" data-act="openHabit">เพิ่มนิสัยแรก</button></div>';
        return;
    }

    const head = days.map(day => {
        const iso = habitIso(day);
        return '<th scope="col"' + (iso === today ? ' class="is-today"' : '') + '>'
            + HABIT_DAYS_SHORT[day.getDay()] + '<b>' + day.getDate() + '</b></th>';
    }).join('');

    const rows = items.map(h => {
        const done = new Set(habitsState.logs[h.id] || []);
        const weekDone = days.filter(day => done.has(habitIso(day))).length;
        const name = escHtml(h.name);

        const meta = [];
        meta.push(weekDone >= h.target_days
            ? '<span class="met">ครบเป้า ' + weekDone + '/' + h.target_days + ' วัน</span>'
            : weekDone + '/' + h.target_days + ' วัน');
        if (h.streak > 0) meta.push('ติดกัน ' + h.streak + ' วัน');

        const cells = days.map(day => {
            const iso = habitIso(day);
            const future = iso > today;
            const label = name + ' วัน' + HABIT_DAYS_FULL[day.getDay()] + 'ที่ ' + day.getDate() + ' ' + HABIT_MONTHS[day.getMonth()];
            return '<td class="habit-cell' + (iso === today ? ' is-today' : '') + (future ? ' future' : '') + '">'
                + '<label><input type="checkbox" data-habit="' + h.id + '" data-date="' + iso + '"'
                + (done.has(iso) ? ' checked' : '') + (future ? ' disabled' : '') + ' aria-label="' + label + '"></label></td>';
        }).join('');

        return '<tr><th scope="row" style="--habit:' + cssColor(h.color, '#10b981') + '">'
            + '<button type="button" class="habit-name" data-edit-habit="' + h.id + '" title="แก้ไขนิสัย">' + name + '</button>'
            + '<span class="habit-meta">' + meta.join(' · ') + '</span></th>' + cells + '</tr>';
    }).join('');

    grid.innerHTML = '<table class="habit-table"><caption class="sr-only">นิสัยและวันที่ทำ สัปดาห์ที่แสดงอยู่</caption>'
        + '<thead><tr><th scope="col">นิสัย</th>' + head + '</tr></thead><tbody>' + rows + '</tbody></table>';
}

/* --- Ticking a day --- */
async function tickHabit(box) {
    const done = box.checked;
    box.disabled = true;
    try {
        await apiFetch(BASE_URL + '/api/habits/' + box.dataset.habit + '/toggle', {
            method: 'POST',
            body: JSON.stringify({ date: box.dataset.date }),
        });
        await loadHabits();
    } catch (err) {
        box.checked = !done;
        box.disabled = false;
        toast(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/* --- The dialog --- */
function openHabit(item) {
    // A click handler passes its element; only a real habit counts as "edit".
    const editing = item && item.id ? item : null;
    document.getElementById('habitModal').hidden = false;
    document.getElementById('habitModalTitle').textContent = editing ? 'แก้ไขนิสัย' : 'เพิ่มนิสัย';
    document.getElementById('habitId').value = editing?.id || '';
    document.getElementById('habitName').value = editing?.name || '';
    document.getElementById('habitTarget').value = editing?.target_days || 7;
    document.getElementById('habitColor').value = editing?.color || '#6366f1';
    document.getElementById('habitDelete').hidden = !editing;
    habitError('');
    document.getElementById('habitName').focus();
}

function closeHabit() {
    document.getElementById('habitModal').hidden = true;
}

function habitError(message) {
    const line = document.getElementById('habitError');
    line.textContent = message;
    line.hidden = message === '';
}

document.getElementById('habitAddBtn')?.addEventListener('click', () => openHabit());
document.querySelectorAll('[data-close-habit]').forEach(el => el.addEventListener('click', closeHabit));
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeHabit(); });

document.getElementById('habitForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('habitId').value;
    const body = {
        name: document.getElementById('habitName').value.trim(),
        target_days: Number(document.getElementById('habitTarget').value),
        color: document.getElementById('habitColor').value,
    };
    if (body.name === '') { habitError('ใส่ชื่อนิสัยก่อน'); return; }

    try {
        await apiFetch(BASE_URL + (id ? '/api/habits/' + id : '/api/habits'), { method: id ? 'PUT' : 'POST', body: JSON.stringify(body) });
        closeHabit();
        await loadHabits();
        toast(id ? 'บันทึกนิสัยแล้ว' : 'เพิ่มนิสัยแล้ว');
    } catch (err) {
        habitError(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
});

document.getElementById('habitDelete')?.addEventListener('click', async () => {
    const id = document.getElementById('habitId').value;
    if (!id) return;
    if (!await confirmAction('ลบนิสัยนี้แล้ว บันทึกที่ติ๊กไว้จะไม่แสดงอีก', 'ลบนิสัย', 'ลบนิสัยนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/habits/' + id, { method: 'DELETE' });
        closeHabit();
        await loadHabits();
        toast('ลบนิสัยแล้ว');
    } catch (err) {
        habitError(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง');
    }
});

document.getElementById('habitsGrid')?.addEventListener('click', e => {
    const edit = e.target.closest('[data-edit-habit]');
    if (edit) openHabit(habitsState.items.find(h => String(h.id) === edit.dataset.editHabit));
});

document.getElementById('habitsGrid')?.addEventListener('change', e => {
    if (e.target.matches('input[data-habit]')) tickHabit(e.target);
});

/* --- Week navigation --- */
function moveWeek(days) {
    habitsState.weekStart.setDate(habitsState.weekStart.getDate() + days);
    loadHabits();
}

document.getElementById('weekPrev')?.addEventListener('click', () => moveWeek(-7));
document.getElementById('weekNext')?.addEventListener('click', () => moveWeek(7));
document.getElementById('weekToday')?.addEventListener('click', () => {
    habitsState.weekStart = weekStartOf(new Date());
    loadHabits();
});

habitsState.weekStart = weekStartOf(new Date());
loadHabits();
