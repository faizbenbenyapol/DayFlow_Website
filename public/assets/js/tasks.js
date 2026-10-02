/* =====================================================
   tasks.js — the Eisenhower matrix

   /api/tasks answers with the tasks grouped by quadrant. Everything is drawn
   from that one object (allTasks), and each change reloads it, so the page is
   never a guess about what the server holds.
===================================================== */

let allTasks = {};

const REPEAT_LABELS = { daily: 'ทุกวัน', weekly: 'ทุกสัปดาห์', monthly: 'ทุกเดือน', yearly: 'ทุกปี' };
const QUADRANTS = [1, 2, 3, 4];

document.addEventListener('DOMContentLoaded', async function () {
    await loadTasks();
    initSortable();
    initQuickAdd();
});

async function loadTasks() {
    try {
        const data = await apiFetch(BASE_URL + '/api/tasks');
        allTasks = data.quadrants || {};
        renderAllQuadrants();
    } catch (err) {
        showLoadError();
    }
}

/** The request failed: say so where the lists should be, with a way to try again. */
function showLoadError() {
    QUADRANTS.forEach(q => {
        const list = document.getElementById('q' + q + '-list');
        list.removeAttribute('aria-busy');
        list.innerHTML = q === 1
            ? '<div class="alert alert-danger" role="alert">โหลดงานไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
              + '<button type="button" class="btn btn-sm" data-act="loadTasks">ลองอีกครั้ง</button></div>'
            : '';
    });
    document.getElementById('tasksTally').textContent = 'โหลดงานไม่ได้';
}

function renderAllQuadrants() {
    QUADRANTS.forEach(q => renderQuadrant(q));
    renderTally();
}

/** Open, overdue and finished, counted across all four boxes. */
function renderTally() {
    let open = 0, overdue = 0, done = 0;
    QUADRANTS.forEach(q => (allTasks[q] || []).forEach(t => {
        if (t.status === 'done') { done++; return; }
        open++;
        if (t.due_date && daysUntil(t.due_date) < 0) overdue++;
    }));

    const parts = [open === 0 ? 'ไม่มีงานค้าง' : 'ค้าง <b>' + open + '</b>'];
    if (overdue > 0) parts.push('<span class="text-danger">เกินกำหนด <b>' + overdue + '</b></span>');
    if (done > 0) parts.push('เสร็จแล้ว <b>' + done + '</b>');
    document.getElementById('tasksTally').innerHTML = parts.join(' · ');
}

function renderQuadrant(q) {
    const list  = document.getElementById('q' + q + '-list');
    const count = document.getElementById('q' + q + '-count');
    if (!list) return;

    const tasks = allTasks[q] || [];
    const open = tasks.filter(t => t.status !== 'done').length;
    count.textContent = tasks.length === 0 ? '' : open + ' ค้าง' + (tasks.length > open ? ' · ' + (tasks.length - open) + ' เสร็จ' : '');
    list.removeAttribute('aria-busy');

    if (tasks.length === 0) {
        // Still a child of the list, so a task can be dragged into an empty box.
        list.innerHTML = '<p class="quadrant-empty">ยังไม่มีงานในช่องนี้</p>';
        return;
    }

    list.innerHTML = tasks.map(t => renderTaskItem(t)).join('');
}

function renderTaskItem(task) {
    const isDone = task.status === 'done';
    const days   = daysUntil(task.due_date);

    let meta = '';
    if (task.due_date) {
        let cls = 'task-due';
        let label = formatDate(task.due_date);
        if (days !== null) {
            if (days < 0) { cls += ' overdue'; label += ' (เกินกำหนด)'; }
            else if (days === 0) { cls += ' today'; label += ' (วันนี้)'; }
        }
        meta += '<span class="' + cls + '">' + escHtml(label) + '</span>';
    }

    // A repeating task is easy to mistake for a duplicate, so it says so.
    const repeatLabel = REPEAT_LABELS[task.repeat_rule];
    if (repeatLabel) {
        meta += '<span class="task-repeat" title="งานที่ทำซ้ำ">↻ ' + escHtml(repeatLabel) + '</span>';
    }

    const title = escHtml(task.title);
    return '<div class="task-item' + (isDone ? ' done' : '') + '" data-id="' + task.id + '" data-quadrant="' + task.quadrant + '">'
        + '<input type="checkbox" class="task-checkbox" ' + (isDone ? 'checked' : '') + ' aria-label="เสร็จแล้ว: ' + title + '" data-act="toggleTask" data-args="[' + task.id + ', &quot;$checked&quot;]" data-on="change">'
        + '<div class="task-content">'
        + '<button type="button" class="task-title" data-act="openEditTask" data-args="[' + task.id + ']">' + title + '</button>'
        + (meta ? '<div class="task-meta">' + meta + '</div>' : '')
        + '</div>'
        + '<div class="task-actions">'
        + '<button type="button" class="icon-btn sm" data-act="openEditTask" data-args="[' + task.id + ']" aria-label="แก้ไข: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button>'
        + '<button type="button" class="icon-btn sm danger" data-act="deleteTask" data-args="[' + task.id + ']" aria-label="ลบ: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
        + '</div>'
        + '</div>';
}

/* --- Quick add: type a title under a box and press Enter --- */
function initQuickAdd() {
    document.querySelectorAll('.quick-add').forEach(input => {
        input.addEventListener('keydown', async event => {
            if (event.key !== 'Enter') return;
            event.preventDefault();

            const title = input.value.trim();
            if (title === '') return;

            input.disabled = true;
            try {
                await apiFetch(BASE_URL + '/api/tasks', {
                    method: 'POST',
                    body: JSON.stringify({ title, quadrant: parseInt(input.dataset.quadrant, 10) }),
                });
                input.value = '';
                await loadTasks();
                toast('เพิ่มงานแล้ว');
            } catch (err) {
                toast(err.message || 'เพิ่มงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
            } finally {
                input.disabled = false;
                input.focus();
            }
        });
    });
}

/* --- Sortable (drag & drop between quadrants) --- */
function initSortable() {
    if (typeof Sortable === 'undefined') return;

    QUADRANTS.forEach(q => {
        const el = document.getElementById('q' + q + '-list');
        if (!el) return;

        Sortable.create(el, {
            group:       'tasks',
            animation:   150,
            ghostClass:  'sortable-ghost',
            dragClass:   'sortable-drag',
            handle:      '.task-content',
            filter:      '.quadrant-empty, .alert',
            delay:       120, // Smooth touch delay to avoid scroll locking
            delayOnTouchOnly: true,
            touchStartThreshold: 7, // Tolerates tiny finger tremors before starting drag
            onEnd:       handleDragEnd
        });
    });
}

function handleDragEnd() {
    const items = [];

    QUADRANTS.forEach(q => {
        const list = document.getElementById('q' + q + '-list');
        if (!list) return;

        list.querySelectorAll('.task-item[data-id]').forEach((el, idx) => {
            items.push({
                id:       parseInt(el.dataset.id, 10),
                quadrant: q,
                position: idx
            });
        });
    });

    apiFetch(BASE_URL + '/api/tasks/reorder', {
        method: 'POST',
        body:   JSON.stringify({ items: items })
    }).then(() => loadTasks()).catch(() => toast('บันทึกการเรียงลำดับไม่สำเร็จ', 'danger'));
}

/* --- Toggle done/open --- */
async function toggleTask(id, isDone) {
    try {
        const result = await apiFetch(BASE_URL + '/api/tasks/' + id, {
            method: 'PUT',
            body:   JSON.stringify({ status: isDone ? 'done' : 'open' })
        });
        await loadTasks();
        if (result && result.next_task) {
            toast('สร้างรอบถัดไปแล้ว: ' + formatDate(result.next_task.due_date));
        }
    } catch {
        toast('อัปเดตสถานะไม่สำเร็จ', 'danger');
        await loadTasks(); // restore
    }
}

/* --- The add and edit dialogs share their fields; only the prefix differs --- */
const TASK_FIELDS = ['Title', 'Desc', 'Quadrant', 'Due', 'Repeat', 'RepeatUntil'];

function taskField(prefix, name) {
    return document.getElementById(prefix + 'Task' + name);
}

function readTaskForm(prefix) {
    return {
        title:        taskField(prefix, 'Title').value.trim(),
        description:  taskField(prefix, 'Desc').value.trim(),
        quadrant:     parseInt(taskField(prefix, 'Quadrant').value, 10),
        due_date:     taskField(prefix, 'Due').value,
        repeat_rule:  taskField(prefix, 'Repeat').value,
        repeat_until: taskField(prefix, 'RepeatUntil').value,
    };
}

/** Returns what is wrong with the form in words, or '' when it is fine. */
function taskFormProblem(form) {
    if (!form.title) return 'ใส่ชื่องานก่อน';
    if (form.repeat_rule !== 'none' && !form.due_date) return 'งานที่ทำซ้ำต้องมีวันครบกำหนด เลือกวันครบกำหนดก่อน';
    return '';
}

/* --- Add Task (modal-based add) --- */
function openAddTask(quadrant) {
    TASK_FIELDS.forEach(name => { taskField('add', name).value = ''; });
    taskField('add', 'Quadrant').value = quadrant;
    taskField('add', 'Repeat').value = 'none';
    openModal('addTaskModal');
}

async function saveAddTask() {
    const form = readTaskForm('add');
    const problem = taskFormProblem(form);
    if (problem) { toast(problem, 'danger'); return; }

    try {
        await apiFetch(BASE_URL + '/api/tasks', { method: 'POST', body: JSON.stringify(form) });
        closeModal('addTaskModal');
        await loadTasks();
        toast('เพิ่มงานแล้ว');
    } catch (err) {
        toast(err.message || 'เพิ่มงานไม่สำเร็จ', 'danger');
    }
}

/* --- Edit Task Modal --- */
function openEditTask(id) {
    let task = null;
    for (const q in allTasks) {
        task = allTasks[q].find(t => t.id === id);
        if (task) break;
    }
    if (!task) return;

    document.getElementById('editTaskId').value = task.id;
    taskField('edit', 'Title').value = task.title;
    taskField('edit', 'Desc').value = task.description || '';
    taskField('edit', 'Quadrant').value = task.quadrant;
    taskField('edit', 'Due').value = task.due_date || '';
    taskField('edit', 'Repeat').value = task.repeat_rule || 'none';
    taskField('edit', 'RepeatUntil').value = task.repeat_until || '';

    openModal('editTaskModal');
}

async function saveEditTask() {
    const id = parseInt(document.getElementById('editTaskId').value, 10);
    const form = readTaskForm('edit');
    const problem = taskFormProblem(form);
    if (problem) { toast(problem, 'danger'); return; }

    try {
        await apiFetch(BASE_URL + '/api/tasks/' + id, { method: 'PUT', body: JSON.stringify(form) });
        closeModal('editTaskModal');
        await loadTasks();
        toast('บันทึกแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

/* --- Delete Task --- */
async function deleteTask(id) {
    if (!await confirmAction('ลบงานนี้แล้วกู้คืนไม่ได้', 'ลบงาน', 'ลบงานนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/tasks/' + id, { method: 'DELETE' });
        await loadTasks();
        toast('ลบงานแล้ว');
    } catch {
        toast('ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
