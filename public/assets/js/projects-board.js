/* =====================================================
   projects-board.js — the board, its tasks and their checklists
   Loaded after projects.js on the projects page; shares its state.

   A card moves between columns by dragging, or by changing "คอลัมน์"
   in its dialog, so the board can be run from the keyboard as well.
===================================================== */

const GROUP_LABEL = { 'todo': 'ยังไม่เริ่ม', 'doing': 'กำลังทำ', 'done': 'เสร็จ' };

/** คืนรายการคอลัมน์ของโปรเจคที่เปิดอยู่ (เรียงตามบอร์ดแล้ว) */
function projectColumns() {
    return (activeProjectData && activeProjectData.columns) || [];
}

/** หาคอลัมน์จากรหัส คืน null เมื่อไม่พบ */
function columnById(id) {
    return projectColumns().find(c => Number(c.id) === Number(id)) || null;
}

/** งานถือว่าเสร็จเมื่ออยู่ในคอลัมน์กลุ่ม done (ไม่ใช้ชื่อหรือสถานะเดิม) */
function isDoneTask(task) {
    const column = columnById(task.column_id);
    return !!column && column.grp === 'done';
}

function isViewer() {
    return !!(activeProjectData && activeProjectData.project.user_role === 'Viewer');
}

/**
 * วาดโครงบอร์ดหนึ่งคอลัมน์ต่อหนึ่งแถวข้อมูล: หัวคอลัมน์ ชื่องาน และช่องเพิ่มงาน
 * ผู้ดูอย่างเดียวไม่ได้ช่องเพิ่มงาน ใช้เมื่อเปิดโปรเจคหรือคอลัมน์เปลี่ยน
 */
function renderBoardColumns() {
    const board = document.getElementById('kanbanBoard');
    if (!board) return;
    const viewer = isViewer();

    board.innerHTML = projectColumns().map(column => {
        const id = Number(column.id);
        const name = escHtml(column.name);
        return '<div class="kanban-column" data-g="' + escHtml(column.grp) + '" data-column-id="' + id + '">'
            + '<div class="kanban-column-header">'
            + '<h3 class="kanban-column-title">' + name + '</h3>'
            + '<span class="grp">' + escHtml(GROUP_LABEL[column.grp] || column.grp) + '</span>'
            + '<span class="kanban-column-count" id="col-' + id + '-count">0</span>'
            + '</div>'
            + '<div class="kanban-cards-list" id="col-' + id + '-list" data-column-id="' + id + '"></div>'
            + '<div class="kanban-quick-add"' + (viewer ? ' hidden' : '') + '>'
            + '<button type="button" class="btn btn-link btn-sm kanban-quick-add-btn" data-act="toggleQuickAddForm" data-args="[' + id + ']">เพิ่มงานใน "' + name + '"</button>'
            + '<div class="kanban-quick-add-form" id="quickadd-col-' + id + '-form" hidden>'
            + '<label class="sr-only" for="quickadd-col-' + id + '-input">ชื่องาน</label>'
            + '<input type="text" class="form-control" id="quickadd-col-' + id + '-input" placeholder="ชื่องาน แล้วกด Enter" autocomplete="off"'
            + ' data-act="handleQuickAddKey" data-args=\'[' + id + ', "$event"]\' data-on="keydown">'
            + '<div class="kanban-quick-add-actions">'
            + '<button type="button" class="btn btn-sm" data-act="toggleQuickAddForm" data-args="[' + id + ', false]">ยกเลิก</button>'
            + '<button type="button" class="btn btn-sm btn-primary" data-act="submitQuickAdd" data-args="[' + id + ']">เพิ่ม</button>'
            + '</div></div></div></div>';
    }).join('');
}

/** เติมการ์ดที่ผ่านตัวกรองร่วม (projects-views.js) ลงในคอลัมน์ของมัน และนับเฉพาะการ์ดที่เห็น */
function renderKanbanCards() {
    const tasks = filteredTasks();
    projectColumns().forEach(column => {
        const id = Number(column.id);
        const mine = tasks.filter(t => Number(t.column_id) === id);
        const countEl = document.getElementById('col-' + id + '-count');
        const listEl = document.getElementById('col-' + id + '-list');
        if (!countEl || !listEl) return;
        countEl.textContent = mine.length;
        listEl.innerHTML = mine.length
            ? mine.map(renderKanbanCardItem).join('')
            : '<p class="kanban-empty">ไม่มีงานในคอลัมน์นี้</p>';
    });
}

/** งานที่ยังไม่เสร็จและเลยกำหนดแสดงว่าเกินกำหนด ส่วนงานในกลุ่ม done ไม่ถือว่าสาย */
function taskDue(task) {
    if (!task.due_date) return '';
    // A finished task is not late, whatever its date says.
    if (isDoneTask(task)) return '<span class="kcard-due">' + escHtml(formatDate(task.due_date)) + '</span>';
    const days = daysUntil(task.due_date);
    if (days !== null && days < 0) return '<span class="kcard-due late">เกินกำหนด ' + Math.abs(days) + ' วัน</span>';
    if (days === 0) return '<span class="kcard-due soon">ครบวันนี้</span>';
    return '<span class="kcard-due">' + escHtml(formatDate(task.due_date)) + '</span>';
}

function taskChecklist(task) {
    if (!task.checklist) return '';
    let list = [];
    try { list = JSON.parse(task.checklist) || []; } catch { return ''; }
    if (!list.length) return '';
    const done = list.filter(item => item.done).length;
    return '<span class="kcard-check' + (done === list.length ? ' all' : '') + '">เช็กลิสต์ ' + done + '/' + list.length + '</span>';
}

function renderKanbanCardItem(task) {
    const viewer = isViewer();
    const title = escHtml(task.title);
    const priority = (task.priority || 'Medium');

    return '<div class="kanban-card" data-id="' + task.id + '" data-priority="' + escHtml(priority.toLowerCase()) + '">'
        + '<div class="kanban-card-title">' + title + '</div>'
        + '<div class="kcard-meta">'
        + '<span class="kcard-priority">' + escHtml(PRIORITY_LABEL[priority] || priority) + '</span>'
        + (task.category ? '<span class="tag kcard-tag">' + escHtml(task.category) + '</span>' : '')
        + taskChecklist(task) + taskDue(task)
        + (task.assignee_name ? '<span class="kcard-who" title="ผู้รับผิดชอบ: ' + escHtml(task.assignee_name) + '">' + escHtml(task.assignee_name) + '</span>' : '')
        + '</div>'
        + (viewer ? '' : '<div class="kcard-actions">'
            + '<button type="button" class="icon-btn sm" data-act="openEditTask" data-args="[' + task.id + ']" aria-label="แก้ไขงาน: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button>'
            + '<button type="button" class="icon-btn sm" data-act="deleteTask" data-args="[' + task.id + ']" aria-label="ลบงาน: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
            + '</div>')
        + '</div>';
}

/* ── Dragging ── */
/**
 * ผูก Sortable กับการ์ดทุกคอลัมน์ (ข้ามคอลัมน์ได้) ปิดไว้สำหรับผู้ดูอย่างเดียว
 * และปิดระหว่างกรองอยู่ เพราะ saveBoardOrder ส่งลำดับเฉพาะการ์ดที่เห็น การ์ดที่ถูกซ่อนจะได้ตำแหน่งชนกัน
 */
function initSortable() {
    if (typeof Sortable === 'undefined') return;
    sortableInstances.forEach(inst => { try { inst.destroy(); } catch (_) { /* already gone */ } });
    sortableInstances = [];
    const filtered = hasTaskFilter();
    show('boardFilterNote', filtered && !isViewer());
    if (isViewer() || filtered) return;

    projectColumns().forEach(column => {
        const el = document.getElementById('col-' + Number(column.id) + '-list');
        if (!el) return;
        sortableInstances.push(Sortable.create(el, {
            group: 'kanban-tasks',
            animation: 160,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            draggable: '.kanban-card',
            handle: '.kanban-card-title',
            delay: 120,                    // a short hold, so scrolling by touch does not start a drag
            delayOnTouchOnly: true,
            touchStartThreshold: 7,
            onEnd: saveBoardOrder,
        }));
    });
}

/** เก็บลำดับหลังลาก: ส่งคอลัมน์และตำแหน่งของทุกการ์ดบนบอร์ด */
async function saveBoardOrder() {
    const items = [];
    projectColumns().forEach(column => {
        const id = Number(column.id);
        document.querySelectorAll('#col-' + id + '-list .kanban-card[data-id]').forEach((card, idx) => {
            items.push({ id: parseInt(card.dataset.id, 10), column_id: id, position: idx });
        });
    });

    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/reorder', {
            method: 'POST',
            body: JSON.stringify({ project_id: activeProjectId, items })
        });
        await selectProject(activeProjectId);
        await refreshProjectList();
    } catch {
        toast('บันทึกการย้ายงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
        await selectProject(activeProjectId);
    }
}

/* ── Adding a task to a column ── */
/** เปิดช่องเพิ่มงานของคอลัมน์ที่เลือก และปิดช่องของคอลัมน์อื่น */
function toggleQuickAddForm(columnId, show = true) {
    projectColumns().forEach(c => {
        const form = document.getElementById('quickadd-col-' + Number(c.id) + '-form');
        if (form) form.hidden = true;
    });
    if (!show) return;
    const form = document.getElementById('quickadd-col-' + Number(columnId) + '-form');
    const input = document.getElementById('quickadd-col-' + Number(columnId) + '-input');
    if (!form || !input) return;
    form.hidden = false;
    input.value = '';
    input.focus();
}

/** Enter ส่งงานใหม่, Escape ปิดช่องของคอลัมน์นั้น */
function handleQuickAddKey(columnId, e) {
    if (e.key === 'Enter') { e.preventDefault(); submitQuickAdd(columnId); }
    else if (e.key === 'Escape') toggleQuickAddForm(columnId, false);
}

/** สร้างงานในคอลัมน์ที่กำหนดด้วย column_id แล้วโหลดบอร์ดใหม่ */
async function submitQuickAdd(columnId) {
    const input = document.getElementById('quickadd-col-' + Number(columnId) + '-input');
    const title = input.value.trim();
    if (!title) { input.focus(); return; }

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/tasks', {
            method: 'POST',
            body: JSON.stringify({ title, column_id: Number(columnId), priority: 'Medium' })
        });
        toggleQuickAddForm(columnId, false);
        await selectProject(activeProjectId);
        await refreshProjectList();
        toast('เพิ่มงานแล้ว');
    } catch (err) {
        toast(err.message || 'เพิ่มงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/* ── A task and its checklist ── */
/** The people a task can go to: everyone on the team, and whoever it already names. */
function fillAssignees(task) {
    const select = document.getElementById('editTaskAssignee');
    const currentUserId = task && task.assignee_user_id ? String(task.assignee_user_id) : '';
    const currentText = task && task.assignee ? task.assignee : '';

    let options = '<option value="">ยังไม่ระบุ</option>'
        + projectMembers.map(m => '<option value="' + escHtml(String(m.id)) + '">' + escHtml(m.display_name || m.username) + '</option>').join('');

    if (currentText && !currentUserId) {
        options += '<option value="legacy">' + escHtml(currentText) + ' (ข้อความเดิม)</option>';
    }

    select.innerHTML = options;
    select.value = currentUserId || (currentText ? 'legacy' : '');
}

/**
 * เปิดกล่องแก้งาน: เติมช่องต่างๆ และสร้างตัวเลือกคอลัมน์จากคอลัมน์จริงของโปรเจค
 * เก็บคอลัมน์เดิมไว้ใน data-original เพื่อส่ง column_id เฉพาะเมื่อผู้ใช้เปลี่ยน
 */
function openEditTask(id) {
    const task = (activeProjectData.tasks || []).find(t => t.id === id);
    if (!task) return;

    document.getElementById('editTaskId').value = task.id;
    document.getElementById('editTaskTitle').value = task.title;
    document.getElementById('editTaskDescription').value = task.description || '';
    const columnSelect = document.getElementById('editTaskColumn');
    columnSelect.innerHTML = projectColumns().map(column =>
        '<option value="' + Number(column.id) + '">' + escHtml(column.name) + '</option>').join('');
    columnSelect.value = String(task.column_id);
    columnSelect.dataset.original = String(task.column_id);
    document.getElementById('editTaskPriority').value = task.priority;
    document.getElementById('editTaskCategory').value = task.category || '';
    fillAssignees(task);
    document.getElementById('editTaskDue').value = task.due_date || '';
    formErrorLine('taskError', '');

    currentChecklist = [];
    if (task.checklist) {
        try { currentChecklist = JSON.parse(task.checklist) || []; } catch (_) { /* an unreadable list starts empty */ }
    }
    renderChecklist();
    renderTaskCustomFields(task);
    openModal('editTaskModal');
    setTaskParam(task.id);
    loadTaskHistory(task.id);
    loadTaskComments(task.id);
    loadTaskFiles(task.id);
}

/**
 * ใส่หรือเอา ?task=<id> ออกจาก URL ด้วย replaceState (ไม่เพิ่มประวัติให้ปุ่มย้อนกลับ)
 * ลิงก์ที่คัดลอกหรือรีเฟรชจึงเปิดงานเดิม
 * @param {number|null} taskId งานที่เปิดอยู่ หรือ null เมื่อปิดแผง
 */
function setTaskParam(taskId) {
    const url = new URL(window.location.href);
    if (taskId) {
        url.searchParams.set('project', String(activeProjectId));
        url.searchParams.set('task', String(taskId));
    } else {
        url.searchParams.delete('task');
    }
    history.replaceState(history.state, '', url);
}

/** คัดลอกลิงก์ของงานที่เปิดอยู่ (URL ตอนนี้มี ?project=&task= แล้ว) */
async function copyTaskLink() {
    try {
        await navigator.clipboard.writeText(window.location.href);
        toast('คัดลอกลิงก์ของงานแล้ว');
    } catch (_) {
        toast('คัดลอกไม่ได้ คัดลอกจากแถบที่อยู่ของเบราว์เซอร์แทน', 'error');
    }
}

/**
 * โหลดประวัติการเปลี่ยนของงานมาใส่แท็บประวัติในแผง
 * @param {number} taskId รหัสงาน
 */
async function loadTaskHistory(taskId) {
    const list = document.getElementById('taskHistoryList');
    list.innerHTML = '<li class="text-muted">กำลังโหลด…</li>';
    try {
        const data = await apiFetch(BASE_URL + '/api/projects/tasks/' + taskId + '/history');
        const items = data.items || [];
        list.innerHTML = items.length
            ? items.map(item => '<li><time>' + escHtml(historyWhen(item.created_at)) + '</time>'
                + (item.display_name ? '<b>' + escHtml(item.display_name) + '</b> ' : '') + escHtml(item.action) + '</li>').join('')
            : '<li class="text-muted">ยังไม่มีการเปลี่ยนที่บันทึกไว้ งานที่สร้างก่อนมีแท็บนี้จะเริ่มเก็บจากการแก้ครั้งถัดไป</li>';
    } catch (_) {
        list.innerHTML = '<li class="text-muted">โหลดประวัติไม่ได้</li>';
    }
}

/** วันเวลาสั้น ๆ ของบรรทัดประวัติ เช่น "24 พ.ย. 09:02" */
function historyWhen(stamp) {
    const d = new Date(String(stamp).replace(' ', 'T'));
    return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' }) + ' '
        + d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

// ปิดแผงด้วยทางไหนก็ได้ (ปุ่ม Esc กดข้างนอก บันทึก) → เอา ?task= ออก
document.addEventListener('DOMContentLoaded', () => {
    const panel = document.getElementById('editTaskModal');
    if (!panel) return;
    new MutationObserver(() => {
        if (!panel.classList.contains('active')) {
            setTaskParam(null);
            stopCommentPolling();
        }
    }).observe(panel, { attributes: true, attributeFilter: ['class'] });
});

function renderChecklist() {
    const listEl = document.getElementById('modalChecklistList');
    const label = document.getElementById('checklistPercentageLabel');
    const fill = document.getElementById('checklistProgressFill');

    if (!currentChecklist.length) {
        listEl.innerHTML = '<p class="proj-note">ยังไม่มีรายการย่อยในงานนี้</p>';
        label.textContent = '0% เสร็จ';
        fill.style.setProperty('--v', '0%');
        return;
    }

    const done = currentChecklist.filter(item => item.done).length;
    const percent = Math.round(done / currentChecklist.length * 100);
    label.textContent = percent + '% เสร็จ (' + done + '/' + currentChecklist.length + ')';
    fill.style.setProperty('--v', percent + '%');

    listEl.innerHTML = currentChecklist.map((item, idx) =>
        '<div class="checklist-item">'
        + '<input type="checkbox" class="checklist-box" ' + (item.done ? 'checked ' : '') + 'aria-label="ทำแล้ว: ' + escHtml(item.text) + '" data-act="toggleChecklistItem" data-args=\'[' + idx + ', "$checked"]\' data-on="change">'
        + '<input type="text" class="checklist-item-input' + (item.done ? ' done' : '') + '" value="' + escHtml(item.text) + '" aria-label="รายการที่ ' + (idx + 1) + '" data-act="updateChecklistItemText" data-args=\'[' + idx + ', "$value"]\' data-on="change">'
        + '<button type="button" class="icon-btn sm" data-act="deleteChecklistItem" data-args="[' + idx + ']" aria-label="ลบรายการ: ' + escHtml(item.text) + '"><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></button>'
        + '</div>'
    ).join('');
}

function toggleChecklistItem(index, checked) {
    if (!currentChecklist[index]) return;
    currentChecklist[index].done = checked;
    renderChecklist();
    saveChecklistQuickly();
}

function updateChecklistItemText(index, value) {
    if (!currentChecklist[index]) return;
    currentChecklist[index].text = value.trim();
    renderChecklist();
    saveChecklistQuickly();
}

function addChecklistItem() {
    const input = document.getElementById('newChecklistItemInput');
    const text = input.value.trim();
    if (!text) { input.focus(); return; }
    currentChecklist.push({ text, done: false });
    input.value = '';
    renderChecklist();
    saveChecklistQuickly();
    input.focus();
}

function deleteChecklistItem(index) {
    currentChecklist.splice(index, 1);
    renderChecklist();
    saveChecklistQuickly();
}

/** The checklist is saved as it is ticked, so closing the dialog loses nothing. */
async function saveChecklistQuickly() {
    const id = parseInt(document.getElementById('editTaskId').value, 10);
    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + id, { method: 'PUT', body: JSON.stringify({ checklist: currentChecklist }) });
        activeProjectData = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/tasks');
        renderKanbanCards();
        updateAnalyticsCharts();
        renderAiInsights();
    } catch {
        formErrorLine('taskError', 'บันทึกเช็กลิสต์ไม่สำเร็จ ลองอีกครั้ง');
    }
}

/**
 * บันทึกงานจากกล่องแก้ไข: ส่ง column_id เฉพาะเมื่อผู้ใช้เปลี่ยนคอลัมน์
 * ฟิลด์อื่นส่งตามปกติ แล้วโหลดบอร์ดและรายการโปรเจคใหม่
 */
async function submitEditTask(e) {
    e.preventDefault();
    const id = parseInt(document.getElementById('editTaskId').value, 10);
    const title = document.getElementById('editTaskTitle').value.trim();
    if (!title) { formErrorLine('taskError', 'ตั้งชื่องานก่อน'); document.getElementById('editTaskTitle').focus(); return; }

    const assigneeSelect = document.getElementById('editTaskAssignee');
    const assigneeValue = assigneeSelect.value;
    const assigneePayload = assigneeValue === 'legacy'
        ? {}
        : assigneeValue === ''
            ? { assignee_user_id: null }
            : { assignee_user_id: parseInt(assigneeValue, 10) };

    const columnSelect = document.getElementById('editTaskColumn');
    const payload = {
        title,
        description: document.getElementById('editTaskDescription').value,
        priority: document.getElementById('editTaskPriority').value,
        category: document.getElementById('editTaskCategory').value.trim(),
        due_date: document.getElementById('editTaskDue').value,
        checklist: currentChecklist,
        ...assigneePayload,
    };
    // ช่องข้อมูลเพิ่มเติม: ส่งทุกช่อง เซิร์ฟเวอร์เขียนเฉพาะช่องที่ค่าเปลี่ยน
    if (projectFields().length) payload.custom = collectTaskCustom();
    if (columnSelect.value !== columnSelect.dataset.original) {
        payload.column_id = parseInt(columnSelect.value, 10);
    }

    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + id, {
            method: 'PUT',
            body: JSON.stringify(payload)
        });
        closeModal('editTaskModal');
        toast('บันทึกงานแล้ว');
        await selectProject(activeProjectId);
        await refreshProjectList();
    } catch (err) {
        formErrorLine('taskError', err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function deleteTask(id) {
    const task = (activeProjectData.tasks || []).find(t => t.id === id);
    if (!await confirmAction('ลบ' + (task ? ' "' + task.title + '"' : 'งานนี้') + ' ออกจากบอร์ดแล้วกู้คืนไม่ได้', 'ลบงาน', 'ลบงานนี้?')) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + id, { method: 'DELETE' });
        toast('ลบงานแล้ว');
        await selectProject(activeProjectId);
        await refreshProjectList();
    } catch {
        toast('ลบงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
