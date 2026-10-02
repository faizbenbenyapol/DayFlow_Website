/* =====================================================
   planner.js — the month grid, the day sheet, and the day's to-dos
===================================================== */

const THAI_MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
                     'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
const THAI_DAYS_SHORT = ['อา','จ','อ','พ','พฤ','ศ','ส'];
const THAI_DAYS_FULL = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
// The keys tokens.css uses for the weekday colour.
const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const DEFAULT_EVENT_COLOR = '#3b82f6';

let currentYear  = new Date().getFullYear();
let currentMonth = new Date().getMonth() + 1; // 1-based
let selectedDate = localToday();
let monthEvents  = [];

/** Today as YYYY-MM-DD in the visitor's own time zone (toISOString would give UTC's date). */
function localToday() {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

document.addEventListener('DOMContentLoaded', async function () {
    initColorSelector();
    await loadMonth(currentYear, currentMonth);
    loadDayPanel(selectedDate, false);
});

/* --- Colour choice in the dialog --- */
function setEventColor(hex) {
    document.getElementById('eventColor').value = hex;
    document.querySelectorAll('.color-dot').forEach(dot => {
        const on = dot.dataset.color === hex;
        dot.classList.toggle('active', on);
        dot.setAttribute('aria-checked', on ? 'true' : 'false');
    });
}

function initColorSelector() {
    document.querySelectorAll('.color-dot').forEach(dot => {
        dot.addEventListener('click', () => setEventColor(dot.dataset.color));
    });
}

/* --- The month --- */
async function loadMonth(year, month) {
    const error = document.getElementById('monthError');
    try {
        const data = await apiFetch(BASE_URL + '/api/planner/events?year=' + year + '&month=' + month);
        monthEvents = data.events || [];
        error.hidden = true;
    } catch {
        monthEvents = [];
        error.hidden = false;
        error.className = 'alert alert-danger';
        error.innerHTML = 'โหลดกิจกรรมของเดือนนี้ไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="reloadMonth">ลองอีกครั้ง</button>';
    }
    renderCalendar(year, month);
}

function reloadMonth() {
    return loadMonth(currentYear, currentMonth).then(() => loadDayPanel(selectedDate, false));
}

function eventsOn(date) {
    return monthEvents
        .filter(e => e.start_datetime && e.start_datetime.startsWith(date))
        .sort((a, b) => (b.is_all_day - a.is_all_day) || a.start_datetime.localeCompare(b.start_datetime));
}

function renderCalendar(year, month) {
    document.getElementById('calMonthLabel').textContent = THAI_MONTHS[month - 1] + ' ' + (year + 543);

    const total = monthEvents.length;
    document.getElementById('plannerTally').textContent =
        total === 0 ? 'เดือนนี้ยังไม่มีกิจกรรม' : 'เดือนนี้มี ' + total + ' กิจกรรม';

    const grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';

    THAI_DAYS_SHORT.forEach(d => {
        const cell = document.createElement('div');
        cell.className = 'calendar-day-name';
        cell.textContent = d;
        grid.appendChild(cell);
    });

    const firstDay = new Date(year, month - 1, 1).getDay(); // 0=Sun
    const daysInMonth = new Date(year, month, 0).getDate();
    const prevDays = new Date(year, month - 1, 0).getDate();
    const today = localToday();

    let dayCount = 1;
    let nextCount = 1;

    for (let i = 0; i < 42; i++) {
        let date, isOther = false;

        if (i < firstDay) {
            date = isoDate(year, month - 1, prevDays - firstDay + i + 1);
            isOther = true;
        } else if (dayCount <= daysInMonth) {
            date = isoDate(year, month, dayCount);
            dayCount++;
        } else {
            date = isoDate(year, month + 1, nextCount);
            nextCount++;
            isOther = true;
        }

        const evs = eventsOn(date);
        const when = new Date(date + 'T12:00:00');

        // A button, so the keyboard reaches every day and a screen reader hears the date.
        const cell = document.createElement('button');
        cell.type = 'button';
        cell.className = 'calendar-cell';
        cell.setAttribute('aria-label', when.getDate() + ' ' + THAI_MONTHS[when.getMonth()] + ' ' + (when.getFullYear() + 543)
            + (evs.length ? ' มี ' + evs.length + ' กิจกรรม' : ''));
        cell.setAttribute('aria-pressed', date === selectedDate ? 'true' : 'false');
        if (isOther) cell.classList.add('other-month');
        if (date === today) cell.classList.add('today');
        if (date === selectedDate) cell.classList.add('selected');

        const dayNum = document.createElement('span');
        dayNum.className = 'calendar-day-number';
        dayNum.textContent = when.getDate();
        cell.appendChild(dayNum);

        evs.slice(0, 2).forEach(ev => {
            const dot = document.createElement('span');
            dot.className = 'calendar-event-dot';
            dot.textContent = ev.title;
            dot.style.setProperty('--ev', cssColor(ev.color, DEFAULT_EVENT_COLOR));
            cell.appendChild(dot);
        });
        if (evs.length > 2) {
            const more = document.createElement('span');
            more.className = 'calendar-more';
            more.textContent = '+' + (evs.length - 2) + ' เพิ่มเติม';
            cell.appendChild(more);
        }

        cell.addEventListener('click', function () {
            grid.querySelectorAll('.calendar-cell.selected').forEach(c => {
                c.classList.remove('selected');
                c.setAttribute('aria-pressed', 'false');
            });
            cell.classList.add('selected');
            cell.setAttribute('aria-pressed', 'true');
            loadDayPanel(date, true);
        });

        grid.appendChild(cell);

        // Stop at the end of the last row once every day is placed.
        if (dayCount > daysInMonth && nextCount > 1 && (i + 1) % 7 === 0) break;
    }
}

function isoDate(year, month, day) {
    if (month < 1)  { year--; month = 12; }
    if (month > 12) { year++; month = 1; }
    return year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
}

function prevMonth() {
    currentMonth--;
    if (currentMonth < 1) { currentMonth = 12; currentYear--; }
    loadMonth(currentYear, currentMonth);
}

function nextMonth() {
    currentMonth++;
    if (currentMonth > 12) { currentMonth = 1; currentYear++; }
    loadMonth(currentYear, currentMonth);
}

/* --- The day sheet --- */
function turnSheet() {
    const sheet = document.getElementById('daySheet');
    sheet.classList.remove('turn');
    void sheet.offsetWidth; // restart the animation
    sheet.classList.add('turn');
}

async function loadDayPanel(date, animate) {
    selectedDate = date;
    const d = new Date(date + 'T12:00:00');
    const days = daysUntil(date);

    document.getElementById('daySheet').dataset.day = DAY_KEYS[d.getDay()];
    document.getElementById('dayNum').textContent = d.getDate();
    document.getElementById('dayMon').textContent = THAI_MONTHS[d.getMonth()].slice(0, 3) + '.';
    document.getElementById('dayPanelDate').textContent = 'วัน' + THAI_DAYS_FULL[d.getDay()];
    document.getElementById('dayPanelSub').textContent =
        d.getDate() + ' ' + THAI_MONTHS[d.getMonth()] + ' ' + (d.getFullYear() + 543)
        + ' · ' + (days === 0 ? 'วันนี้' : days > 0 ? 'อีก ' + days + ' วัน' : Math.abs(days) + ' วันที่แล้ว');
    if (animate) turnSheet();

    const evEl = document.getElementById('dayEvents');
    const evs = eventsOn(date);
    if (evs.length === 0) {
        evEl.innerHTML = '<p class="day-empty">ไม่มีกิจกรรมในวันนี้ · <button type="button" class="btn-link" data-act="openAddEvent">เพิ่มกิจกรรม</button></p>';
    } else {
        evEl.innerHTML = evs.map(ev => {
            const timeStr = ev.is_all_day ? 'ทั้งวัน' : ev.start_datetime.slice(11, 16);
            const title = escHtml(ev.title);
            return '<div class="day-event-item" style="--ev: ' + cssColor(ev.color, DEFAULT_EVENT_COLOR) + '">'
                + '<span class="day-event-time">' + escHtml(timeStr) + '</span>'
                + '<span class="day-event-title">' + title + '</span>'
                + '<button type="button" class="icon-btn sm" data-act="openEditEvent" data-args="[' + ev.id + ']" aria-label="แก้ไข: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button>'
                + '</div>';
        }).join('');
    }

    loadTodos(date);
}

/* --- Todos --- */
let todosCache = [];

async function loadTodos(date) {
    const el = document.getElementById('dayTodos');
    try {
        const data = await apiFetch(BASE_URL + '/api/planner/todos?date=' + date);
        todosCache = data.todos || [];
        renderTodos();
    } catch {
        el.innerHTML = '<p class="day-empty text-danger">โหลดสิ่งที่ต้องทำไม่สำเร็จ ลองเลือกวันอีกครั้ง</p>';
    }
}

function renderTodos() {
    const el = document.getElementById('dayTodos');
    if (!el) return;

    if (!todosCache.length) {
        el.innerHTML = '<p class="day-empty">ยังไม่มีสิ่งที่ต้องทำในวันนี้ พิมพ์ในช่องด้านล่างแล้วกด Enter</p>';
        return;
    }

    el.innerHTML = todosCache.map(t => {
        const title = escHtml(t.title);
        return '<div class="day-todo-item' + (t.is_done ? ' done' : '') + '" data-id="' + t.id + '">'
            + '<input type="checkbox" ' + (t.is_done ? 'checked' : '') + ' aria-label="เสร็จแล้ว: ' + title + '" data-act="toggleTodo" data-args="[' + t.id + ', &quot;$checked&quot;]" data-on="change">'
            + '<span class="day-todo-text">' + title + '</span>'
            + '<button type="button" class="icon-btn sm danger" data-act="deleteTodo" data-args="[' + t.id + ']" aria-label="ลบ: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
            + '</div>';
    }).join('');
}

async function addTodo() {
    const inp = document.getElementById('todoInput');
    const title = inp.value.trim();
    if (!title) return;

    try {
        await apiFetch(BASE_URL + '/api/planner/todos', {
            method: 'POST',
            body: JSON.stringify({ title, date: selectedDate })
        });
        inp.value = '';
        loadTodos(selectedDate);
    } catch (err) {
        toast(err.message || 'เพิ่มไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

async function toggleTodo(id, isDone) {
    try {
        await apiFetch(BASE_URL + '/api/planner/todos/' + id, {
            method: 'PUT',
            body: JSON.stringify({ is_done: isDone ? 1 : 0 })
        });
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
    loadTodos(selectedDate);
}

async function deleteTodo(id) {
    try {
        await apiFetch(BASE_URL + '/api/planner/todos/' + id, { method: 'DELETE' });
        toast('ลบแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
    loadTodos(selectedDate);
}

/* --- Event Modal --- */
let editingEventId = null;

function openAddEvent() {
    editingEventId = null;
    document.getElementById('editEventId').value = '';
    document.getElementById('eventModalTitle').textContent = 'เพิ่มกิจกรรม';
    document.getElementById('eventTitle').value = '';
    document.getElementById('eventDesc').value = '';
    document.getElementById('eventAllDay').checked = false;
    document.getElementById('eventStart').value = selectedDate + 'T08:00';
    document.getElementById('eventEnd').value = '';
    document.getElementById('eventDate').value = selectedDate;
    document.getElementById('eventRepeat').value = 'none';
    document.getElementById('eventRepeatUntil').value = '';
    document.getElementById('eventRepeatHint').hidden = true;
    document.getElementById('deleteEventBtn').hidden = true;

    setEventColor(DEFAULT_EVENT_COLOR);
    toggleAllDay(false);
    openModal('eventModal');
}

function openEditEvent(id) {
    const ev = monthEvents.find(e => e.id === id);
    if (!ev) return;

    editingEventId = id;
    document.getElementById('editEventId').value = id;
    document.getElementById('eventModalTitle').textContent = 'แก้ไขกิจกรรม';
    document.getElementById('eventTitle').value = ev.title;
    document.getElementById('eventDesc').value = ev.description || '';
    document.getElementById('eventAllDay').checked = ev.is_all_day == 1;
    document.getElementById('deleteEventBtn').hidden = false;
    setEventColor(ev.color || DEFAULT_EVENT_COLOR);

    // A repeating event is rendered once per occurrence, all sharing one id.
    // Editing has to work from the series' own dates, or saving would drag the
    // whole series onto the occurrence the user clicked.
    const seriesStart = ev.series_start || ev.start_datetime;
    const seriesEnd   = ev.series_start ? ev.series_end : ev.end_datetime;

    document.getElementById('eventRepeat').value = ev.repeat_rule || 'none';
    document.getElementById('eventRepeatUntil').value = ev.repeat_until || '';
    document.getElementById('eventRepeatHint').hidden = !(ev.repeat_rule && ev.repeat_rule !== 'none');

    if (ev.is_all_day) {
        toggleAllDay(true);
        document.getElementById('eventDate').value = seriesStart.slice(0, 10);
    } else {
        toggleAllDay(false);
        document.getElementById('eventStart').value = seriesStart.replace(' ', 'T').slice(0, 16);
        document.getElementById('eventEnd').value = seriesEnd ? seriesEnd.replace(' ', 'T').slice(0, 16) : '';
    }

    openModal('eventModal');
}

function toggleAllDay(checked) {
    document.getElementById('dateTimeFields').hidden = checked;
    document.getElementById('dateOnlyFields').hidden = !checked;
}

async function saveEvent() {
    const title   = document.getElementById('eventTitle').value.trim();
    const isAllDay = document.getElementById('eventAllDay').checked;
    const selectedColor = document.getElementById('eventColor').value || DEFAULT_EVENT_COLOR;

    if (!title) { toast('ใส่ชื่อกิจกรรมก่อน', 'danger'); return; }

    let startDt, endDt = '';
    if (isAllDay) {
        const d = document.getElementById('eventDate').value;
        if (!d) { toast('เลือกวันที่ก่อน', 'danger'); return; }
        startDt = d + ' 00:00:00';
    } else {
        const startValue = document.getElementById('eventStart').value;
        if (!startValue) { toast('เลือกเวลาเริ่มก่อน', 'danger'); return; }
        startDt = startValue.replace('T', ' ') + ':00';
        endDt   = document.getElementById('eventEnd').value ? document.getElementById('eventEnd').value.replace('T', ' ') + ':00' : '';
    }

    const repeatRule  = document.getElementById('eventRepeat').value;
    const repeatUntil = document.getElementById('eventRepeatUntil').value;
    if (repeatUntil && repeatUntil < startDt.slice(0, 10)) {
        toast('วันสิ้นสุดการทำซ้ำต้องไม่มาก่อนวันเริ่มต้น', 'danger');
        return;
    }

    const body = {
        title,
        description:    document.getElementById('eventDesc').value,
        start_datetime: startDt,
        end_datetime:   endDt,
        is_all_day:     isAllDay ? 1 : 0,
        repeat_rule:    repeatRule,
        repeat_until:   repeatUntil,
        color:          selectedColor
    };

    try {
        if (editingEventId) {
            await apiFetch(BASE_URL + '/api/planner/events/' + editingEventId, {
                method: 'PUT', body: JSON.stringify(body)
            });
        } else {
            await apiFetch(BASE_URL + '/api/planner/events', {
                method: 'POST', body: JSON.stringify(body)
            });
        }
        closeModal('eventModal');
        await loadMonth(currentYear, currentMonth);
        loadDayPanel(selectedDate, false);
        toast('บันทึกกิจกรรมแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

async function deleteEvent() {
    if (!editingEventId) return;
    const editing = monthEvents.find(e => e.id === editingEventId);
    const repeats = editing && editing.repeat_rule && editing.repeat_rule !== 'none';
    const question = repeats
        ? 'กิจกรรมนี้ทำซ้ำอยู่ การลบจะลบทุกครั้งในชุดนี้ และกู้คืนไม่ได้'
        : 'ลบกิจกรรมนี้แล้วกู้คืนไม่ได้';
    if (!await confirmAction(question, 'ลบกิจกรรม', 'ลบกิจกรรมนี้?')) return;

    try {
        await apiFetch(BASE_URL + '/api/planner/events/' + editingEventId, { method: 'DELETE' });
        closeModal('eventModal');
        await loadMonth(currentYear, currentMonth);
        loadDayPanel(selectedDate, false);
        toast('ลบกิจกรรมแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/* --- Calendar import (.ics) ---
   Export is a plain link; import posts the file and reloads the month. */
async function importIcs(input) {
    const file = input.files && input.files[0];
    input.value = ''; // let the same file be picked again after a failure
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
        toast('ไฟล์ใหญ่เกิน 2 MB ลองส่งออกช่วงเวลาที่สั้นลง', 'danger');
        return;
    }

    const form = new FormData();
    form.append('file', file);

    try {
        const result = await apiFetch(BASE_URL + '/api/planner/events/import', {
            method: 'POST',
            body: form
        });
        await loadMonth(currentYear, currentMonth);
        loadDayPanel(selectedDate, false);

        const skipped = result.skipped ? ' (ข้ามที่มีอยู่แล้ว ' + result.skipped + ')' : '';
        toast('นำเข้า ' + result.imported + ' กิจกรรม' + skipped);
    } catch (err) {
        toast(err.message || 'นำเข้าไม่สำเร็จ ตรวจว่าเป็นไฟล์ .ics แล้วลองใหม่', 'danger');
    }
}
