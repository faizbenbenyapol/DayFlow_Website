/* =====================================================
   tasks.js — the Eisenhower matrix plus an inbox

   /api/tasks answers with the tasks grouped by quadrant. Inbox tasks live
   under key 0 (stored as NULL on the server). Everything is drawn from that
   one object (allTasks), and each change reloads it, so the page is never a
   guess about what the server holds.
===================================================== */

let allTasks = {};
let inboxExpanded = false;
let hashHandled = false;

const REPEAT_LABELS = { daily: 'ทุกวัน', weekly: 'ทุกสัปดาห์', monthly: 'ทุกเดือน', yearly: 'ทุกปี' };
const QUADRANTS = [1, 2, 3, 4];
const LISTS = [0, 1, 2, 3, 4];
const INBOX_MAX = 8;

document.addEventListener('DOMContentLoaded', async function () {
    await loadTasks();
    if (IS_READ_ONLY) return;
    initSortable();
    initQuickAdd();
});

// ถ้ากำลังอยู่บนหน้า /tasks แล้วคลิกผลค้นหา เบราว์เซอร์เปลี่ยนแค่ hash ไม่โหลดหน้าใหม่ จึงต้องโหลดงานเอง
window.addEventListener('hashchange', function () {
    if (!/^#task-\d+$/.test(location.hash) && location.hash !== '#inbox') return;
    hashHandled = false;
    loadTasks();
});

/**
 * โหลดรายการงานจาก API แล้ววาดทุกช่อง จากนั้นตรวจ hash ครั้งแรกสุดเพื่อเลื่อนไปงานเป้าหมาย
 */
async function loadTasks() {
    try {
        let url = BASE_URL + '/api/tasks';
        // ถ้าโหลดครั้งแรกและ URL มี #task-<id> ให้ขอแสดงงานนั้นด้วย
        // เพราะงานเสร็จเก่าใน inbox อาจถูกซ่อนตามกฎ 7 วัน
        if (!hashHandled) {
            const match = location.hash.match(/^#task-(\d+)$/);
            if (match) url += '?show=' + encodeURIComponent(match[1]);
        }
        const data = await apiFetch(url);
        allTasks = data.quadrants || {};
        renderAllQuadrants();
        if (!hashHandled) {
            handleLocationHash();
        }
    } catch (err) {
        showLoadError();
    }
}

/** โหลดไม่สำเร็จ: แสดงข้อความผิดพลาดใน inbox ช่องเดียว และล้างช่องอื่น */
function showLoadError() {
    const inbox = document.getElementById('q0-list');
    if (inbox) {
        inbox.removeAttribute('aria-busy');
        inbox.innerHTML = '<div class="alert alert-danger" role="alert">โหลดงานไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="loadTasks">ลองอีกครั้ง</button></div>';
    }
    QUADRANTS.forEach(q => {
        const list = document.getElementById('q' + q + '-list');
        if (!list) return;
        list.removeAttribute('aria-busy');
        list.innerHTML = '';
    });
    document.getElementById('tasksTally').textContent = 'โหลดงานไม่ได้';
}

function renderAllQuadrants() {
    LISTS.forEach(q => renderQuadrant(q));
    renderTally();
}

/** นับงานค้าง เกินกำหนด เสร็จแล้ว และยังไม่จัด ทั่วทุกช่อง */
function renderTally() {
    let open = 0, overdue = 0, done = 0, inboxOpen = 0;
    LISTS.forEach(q => (allTasks[q] || []).forEach(t => {
        if (t.status === 'done') { done++; return; }
        open++;
        if (q === 0) inboxOpen++;
        if (t.due_date && daysUntil(t.due_date) < 0) overdue++;
    }));

    const parts = [open === 0 ? 'ไม่มีงานค้าง' : 'ค้าง <b>' + open + '</b>'];
    if (overdue > 0) parts.push('<span class="text-danger">เกินกำหนด <b>' + overdue + '</b></span>');
    if (done > 0) parts.push('เสร็จแล้ว <b>' + done + '</b>');
    if (inboxOpen > 0) parts.push('ยังไม่จัด <b>' + inboxOpen + '</b>');
    document.getElementById('tasksTally').innerHTML = parts.join(' · ');
}

/**
 * วาดรายการในช่องหนึ่งช่อง สำหรับ inbox จะแสดง open ก่อน done และพับเหลือ 8 งาน
 *
 * @param {number} q หมายเลขช่อง 0 = inbox, 1-4 = ช่อง Eisenhower
 */
function renderQuadrant(q) {
    const list  = document.getElementById('q' + q + '-list');
    const count = document.getElementById('q' + q + '-count');
    if (!list) return;

    let tasks = allTasks[q] || [];
    if (q === 0) {
        tasks = sortInboxTasks(tasks);
    }

    const open = tasks.filter(t => t.status !== 'done').length;
    count.textContent = tasks.length === 0 ? '' : open + ' ค้าง' + (tasks.length > open ? ' · ' + (tasks.length - open) + ' เสร็จ' : '');
    list.removeAttribute('aria-busy');

    if (tasks.length === 0) {
        // Still a child of the list, so a task can be dragged into an empty box.
        list.innerHTML = '<p class="quadrant-empty">' + (q === 0 ? 'ไม่มีงานรอจัด' : 'ยังไม่มีงานในช่องนี้') + '</p>';
        return;
    }

    if (q === 0 && tasks.length > INBOX_MAX && !inboxExpanded) {
        const visible = tasks.slice(0, INBOX_MAX);
        const hiddenCount = tasks.length - INBOX_MAX;
        list.innerHTML = visible.map(t => renderTaskItem(t)).join('')
            + '<button type="button" class="btn btn-sm btn-ghost inbox-more" data-act="expandInbox">แสดงอีก ' + hiddenCount + ' งาน</button>';
        return;
    }

    list.innerHTML = tasks.map(t => renderTaskItem(t)).join('');
}

/**
 * เรียงงาน inbox ให้ open อยู่ก่อน done เพื่อให้งานที่ยังทำอยู่เห็นก่อน
 *
 * @param {Array} tasks งานใน inbox
 * @returns {Array} งานที่เรียงใหม่
 */
function sortInboxTasks(tasks) {
    return tasks.slice().sort((a, b) => {
        const ad = a.status === 'done' ? 1 : 0;
        const bd = b.status === 'done' ? 1 : 0;
        if (ad !== bd) return ad - bd;
        return (a.position ?? 0) - (b.position ?? 0) || a.id - b.id;
    });
}

/** กางรายการ inbox ที่ซ่อนไว้ออกมา */
function expandInbox() {
    inboxExpanded = true;
    renderQuadrant(0);
}

function renderTaskItem(task) {
    const isDone = task.status === 'done';
    const days   = daysUntil(task.due_date);
    const q      = task.quadrant || 0;

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
    if (IS_READ_ONLY) {
        // Looking only: the box says done or not, the title is just a title.
        return '<div class="task-item' + (isDone ? ' done' : '') + '" id="task-' + task.id + '" data-id="' + task.id + '">'
            + '<input type="checkbox" class="task-checkbox" disabled ' + (isDone ? 'checked' : '') + ' aria-label="เสร็จแล้ว: ' + title + '">'
            + '<div class="task-content"><span class="task-title"><span class="done-mark">' + title + '</span></span>'
            + (meta ? '<div class="task-meta">' + meta + '</div>' : '')
            + '</div></div>';
    }
    return '<div class="task-item' + (isDone ? ' done' : '') + '" id="task-' + task.id + '" data-id="' + task.id + '" data-quadrant="' + q + '">'
        + '<input type="checkbox" class="task-checkbox" ' + (isDone ? 'checked' : '') + ' aria-label="เสร็จแล้ว: ' + title + '" data-act="toggleTask" data-args="[' + task.id + ', &quot;$checked&quot;]" data-on="change">'
        + '<div class="task-content">'
        + '<button type="button" class="task-title" title="' + title + '" data-act="openEditTask" data-args="[' + task.id + ']"><span class="done-mark">' + title + '</span></button>'
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

/* --- Sortable (drag & drop between quadrants, including inbox) --- */
function initSortable() {
    if (typeof Sortable === 'undefined') return;

    LISTS.forEach(q => {
        const el = document.getElementById('q' + q + '-list');
        if (!el) return;

        Sortable.create(el, {
            group:       'tasks',
            animation:   150,
            ghostClass:  'sortable-ghost',
            dragClass:   'sortable-drag',
            handle:      '.task-content',
            filter:      '.quadrant-empty, .alert, .inbox-more',
            delay:       120, // Smooth touch delay to avoid scroll locking
            delayOnTouchOnly: true,
            touchStartThreshold: 7, // Tolerates tiny finger tremors before starting drag
            onEnd:       handleDragEnd
        });
    });
}

/** ส่งตำแหน่งปัจจุบันของงานทุกช่องกลับไปเซิร์ฟเวอร์ โดย inbox ใช้ quadrant 0 */
function handleDragEnd() {
    const items = [];

    LISTS.forEach(q => {
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
    taskField('edit', 'Quadrant').value = task.quadrant || 0;
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

/* --- Hash target: scroll to a task or to the inbox --- */

/**
 * ตรวจ location.hash ถ้าชี้ไปยัง #task-<id> หรือ #inbox ให้เลื่อนไปและไฮไลต์
 * ทำงานครั้งเดียวต่อการโหลดหน้า แล้วลบ hash ออกจาก URL เพื่อไม่ให้ re-run
 */
function handleLocationHash() {
    hashHandled = true;
    const hash = location.hash;
    clearLocationHash();

    if (hash === '#inbox') {
        document.getElementById('inbox')?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        return;
    }

    const match = hash.match(/^#task-(\d+)$/);
    if (!match) return;

    const id = parseInt(match[1], 10);
    ensureTaskVisible(id);

    const taskEl = document.getElementById('task-' + id);
    if (!taskEl) return;

    taskEl.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center' });
    taskEl.classList.add('is-target');
    const checkbox = taskEl.querySelector('.task-checkbox');
    if (checkbox) checkbox.focus();

    setTimeout(() => taskEl.classList.remove('is-target'), 2000);
}

/**
 * ลบ hash ออกจาก URL โดยเก็บ path และ query string ไว้
 */
function clearLocationHash() {
    if (location.hash === '') return;
    const url = new URL(location.href);
    url.hash = '';
    history.replaceState(null, '', url.toString());
}

/**
 * ถ้างานเป้าหมายอยู่ใน inbox แต่ถูกพับไว้ ให้กางออกก่อนเลื่อน
 *
 * @param {number} id รหัสงาน
 */
function ensureTaskVisible(id) {
    const inboxTasks = sortInboxTasks(allTasks[0] || []);
    const index = inboxTasks.findIndex(t => t.id === id);
    if (index === -1) return;
    if (index >= INBOX_MAX && !inboxExpanded) {
        inboxExpanded = true;
        renderQuadrant(0);
    }
}

/** @returns {boolean} ผู้ใช้เลือกลดการเคลื่อนไหวหรือไม่ */
function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}
