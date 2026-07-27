/* =====================================================
   planner.js — Calendar + Daily todos
   ===================================================== */

const THAI_MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
                     'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
const THAI_DAYS_SHORT = ['อา','จ','อ','พ','พฤ','ศ','ส'];

let currentYear  = new Date().getFullYear();
let currentMonth = new Date().getMonth() + 1; // 1-based
let selectedDate = todayISO();
let monthEvents  = [];

document.addEventListener('DOMContentLoaded', function () {
    loadMonth(currentYear, currentMonth);
    loadDayPanel(selectedDate);
    initColorSelector();
});

function initColorSelector() {
    const dots = document.querySelectorAll('.color-dot');
    const colorInput = document.getElementById('eventColor');
    
    dots.forEach(dot => {
        dot.addEventListener('click', function () {
            dots.forEach(d => d.classList.remove('active'));
            this.classList.add('active');
            if (colorInput) {
                colorInput.value = this.dataset.color;
            }
        });
    });
}

async function loadMonth(year, month) {
    try {
        const data = await apiFetch(BASE_URL + '/api/planner/events?year=' + year + '&month=' + month);
        monthEvents = data.events || [];
        renderCalendar(year, month);
    } catch {
        monthEvents = [];
        renderCalendar(year, month);
    }
}

function renderCalendar(year, month) {
    document.getElementById('calMonthLabel').textContent =
        THAI_MONTHS[month - 1] + ' ' + (year + 543);

    const grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';
    grid.className = 'calendar-grid';

    // Day name headers
    THAI_DAYS_SHORT.forEach(d => {
        const cell = document.createElement('div');
        cell.className = 'calendar-day-name';
        cell.textContent = d;
        grid.appendChild(cell);
    });

    const firstDay = new Date(year, month - 1, 1).getDay(); // 0=Sun
    const daysInMonth = new Date(year, month, 0).getDate();
    const prevDays = new Date(year, month - 1, 0).getDate();
    const today = todayISO();

    let dayCount = 1;
    let nextCount = 1;

    for (let i = 0; i < 42; i++) {
        const cell = document.createElement('div');
        cell.className = 'calendar-cell';

        let date, isOther = false;

        if (i < firstDay) {
            const d = prevDays - firstDay + i + 1;
            date = isoDate(year, month - 1 || 12, d);
            isOther = true;
        } else if (dayCount <= daysInMonth) {
            date = isoDate(year, month, dayCount);
            dayCount++;
        } else {
            date = isoDate(year, month + 1 > 12 ? 1 : month + 1, nextCount);
            nextCount++;
            isOther = true;
        }

        if (isOther) cell.classList.add('other-month');
        if (date === today) cell.classList.add('today');
        if (date === selectedDate) cell.classList.add('selected');

        const dayNum = document.createElement('div');
        dayNum.className = 'calendar-day-number';
        dayNum.textContent = new Date(date + 'T12:00:00').getDate();

        cell.appendChild(dayNum);

        // Events for this day
        const evs = monthEvents.filter(e => e.start_datetime && e.start_datetime.startsWith(date));
        evs.slice(0, 2).forEach(ev => {
            const dot = document.createElement('span');
            dot.className = 'calendar-event-dot';
            dot.textContent = ev.title;
            dot.style.background = ev.color || '#3b82f6';
            cell.appendChild(dot);
        });
        if (evs.length > 2) {
            const more = document.createElement('span');
            more.className = 'calendar-more';
            more.textContent = '+' + (evs.length - 2) + ' เพิ่มเติม';
            cell.appendChild(more);
        }

        cell.addEventListener('click', function () {
            document.querySelectorAll('.calendar-cell.selected').forEach(c => c.classList.remove('selected'));
            cell.classList.add('selected');
            selectedDate = date;
            loadDayPanel(date);
        });

        grid.appendChild(cell);

        // Stop at end of last row if all days filled
        if (dayCount > daysInMonth && nextCount > 1 && (i + 1) % 7 === 0) break;
    }
}

function isoDate(year, month, day) {
    if (month < 1)  { year--; month = 12; }
    if (month > 12) { year++; month = 1; }
    return year + '-' + String(month).padStart(2,'0') + '-' + String(day).padStart(2,'0');
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

/* --- Day Panel --- */
async function loadDayPanel(date) {
    selectedDate = date;
    const d = new Date(date + 'T12:00:00');
    const label = d.getDate() + ' ' + THAI_MONTHS[d.getMonth()] + ' ' + (d.getFullYear() + 543);
    document.getElementById('dayPanelDate').textContent = label;

    const evEl = document.getElementById('dayEvents');
    const evs = monthEvents.filter(e => e.start_datetime && e.start_datetime.startsWith(date));
    if (evs.length === 0) {
        evEl.innerHTML = '<div class="text-sm text-muted" style="padding: 12px 0;">ไม่มีกิจกรรม</div>';
    } else {
        evEl.innerHTML = evs.map(ev => {
            const timeStr = ev.is_all_day ? 'ทั้งวัน' : ev.start_datetime.slice(11, 16);
            return `<div class="day-event-item" style="--event-color: ${ev.color || '#3b82f6'}">
                <span class="day-event-time">${escHtml(timeStr)}</span>
                <span class="day-event-title">${escHtml(ev.title)}</span>
                <button class="btn-link" onclick="openEditEvent(${ev.id})" style="padding: 2px 6px;">แก้ไข</button>
            </div>`;
        }).join('');
    }

    loadTodos(date);
}

/* --- Todos --- */
let todosCache = [];

async function loadTodos(date) {
    try {
        const data = await apiFetch(BASE_URL + '/api/planner/todos?date=' + date);
        todosCache = data.todos || [];
        renderTodos();
    } catch {}
}

function renderTodos() {
    const el = document.getElementById('dayTodos');
    if (!el) return;

    if (!todosCache.length) {
        el.innerHTML = '<div class="text-sm text-muted" style="padding:12px 0">ไม่มีรายการ</div>';
        return;
    }

    el.innerHTML = todosCache.map(t =>
        `<div class="day-todo-item ${t.is_done ? 'done' : ''}" data-id="${t.id}">
            <input type="checkbox" ${t.is_done ? 'checked' : ''} style="accent-color:var(--color-text);cursor:pointer; width:16px; height:16px;"
                   onchange="toggleTodo(${t.id}, this.checked)">
            <span class="day-todo-text">${escHtml(t.title)}</span>
            <button class="btn-link" onclick="deleteTodo(${t.id})" style="margin-left:auto; color:var(--color-danger); padding: 2px 6px;">ลบ</button>
        </div>`
    ).join('');
}

async function addTodo() {
    const inp = document.getElementById('todoInput');
    const title = inp.value.trim();
    if (!title) return;

    await apiFetch(BASE_URL + '/api/planner/todos', {
        method: 'POST',
        body: JSON.stringify({ title, date: selectedDate })
    });
    inp.value = '';
    loadTodos(selectedDate);
}

async function toggleTodo(id, isDone) {
    await apiFetch(BASE_URL + '/api/planner/todos/' + id, {
        method: 'PUT',
        body: JSON.stringify({ is_done: isDone ? 1 : 0 })
    });
    loadTodos(selectedDate);
}

async function deleteTodo(id) {
    await apiFetch(BASE_URL + '/api/planner/todos/' + id, { method: 'DELETE' });
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
    document.getElementById('deleteEventBtn').style.display = 'none';
    
    // Set default color blue
    document.getElementById('eventColor').value = '#3b82f6';
    const dots = document.querySelectorAll('.color-dot');
    dots.forEach(d => {
        if (d.dataset.color === '#3b82f6') d.classList.add('active');
        else d.classList.remove('active');
    });

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
    document.getElementById('deleteEventBtn').style.display = 'block';

    // Set color from event
    const eventCol = ev.color || '#3b82f6';
    document.getElementById('eventColor').value = eventCol;
    const dots = document.querySelectorAll('.color-dot');
    dots.forEach(d => {
        if (d.dataset.color === eventCol) d.classList.add('active');
        else d.classList.remove('active');
    });

    if (ev.is_all_day) {
        toggleAllDay(true);
        document.getElementById('eventDate').value = ev.start_datetime.slice(0, 10);
    } else {
        toggleAllDay(false);
        document.getElementById('eventStart').value = ev.start_datetime.replace(' ', 'T').slice(0, 16);
        document.getElementById('eventEnd').value = ev.end_datetime ? ev.end_datetime.replace(' ', 'T').slice(0, 16) : '';
    }

    openModal('eventModal');
}

function toggleAllDay(checked) {
    document.getElementById('dateTimeFields').style.display = checked ? 'none' : 'grid';
    document.getElementById('dateOnlyFields').style.display = checked ? 'block' : 'none';
}

async function saveEvent() {
    const title   = document.getElementById('eventTitle').value.trim();
    const isAllDay = document.getElementById('eventAllDay').checked;
    const selectedColor = document.getElementById('eventColor').value || '#3b82f6';

    if (!title) { toast('กรุณากรอกชื่อกิจกรรม', 'danger'); return; }

    let startDt, endDt = '';
    if (isAllDay) {
        const d = document.getElementById('eventDate').value;
        if (!d) { toast('กรุณาเลือกวันที่', 'danger'); return; }
        startDt = d + ' 00:00:00';
    } else {
        startDt = document.getElementById('eventStart').value.replace('T', ' ') + ':00';
        endDt   = document.getElementById('eventEnd').value ? document.getElementById('eventEnd').value.replace('T', ' ') + ':00' : '';
    }

    const body = {
        title,
        description:    document.getElementById('eventDesc').value,
        start_datetime: startDt,
        end_datetime:   endDt,
        is_all_day:     isAllDay ? 1 : 0,
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
        loadDayPanel(selectedDate);
        toast('บันทึกแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

async function deleteEvent() {
    if (!editingEventId) return;
    if (!await confirmAction('ต้องการลบกิจกรรมนี้?', 'ลบ')) return;
    await apiFetch(BASE_URL + '/api/planner/events/' + editingEventId, { method: 'DELETE' });
    closeModal('eventModal');
    await loadMonth(currentYear, currentMonth);
    loadDayPanel(selectedDate);
    toast('ลบแล้ว');
}

function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
