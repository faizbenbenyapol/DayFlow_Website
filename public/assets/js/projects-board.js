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

/**
 * เติมการ์ดที่ผ่านตัวกรองร่วม (projects-views.js) ลงในคอลัมน์ของมัน และนับเฉพาะการ์ดที่เห็น
 * งานใหญ่ไม่ขึ้นบอร์ดเพราะไม่มีงานในคอลัมน์ แต่ยังเห็นได้ในมุมมองรายการ
 * โปรเจคซอฟต์แวร์ที่ใช้รอบงานแสดงเฉพาะงานของรอบที่กำลังทำ และแสดงข้อความว่างเมื่อยังไม่มีรอบ
 */
function renderKanbanCards() {
    buildTaskIndex();
    let tasks = filteredTasks();
    if (isSoftwareProject()) tasks = tasks.filter(task => task.kind !== 'epic');
    const sprintMode = typeof isSprintMode === 'function' && isSprintMode();
    const sprint = sprintMode && typeof activeSprint === 'function' ? activeSprint() : null;
    if (sprintMode) {
        tasks = sprint ? tasks.filter(task => Number(task.sprint_id) === Number(sprint.id)) : [];
    }
    const boardEl = document.getElementById('kanbanBoard');
    if (boardEl) boardEl.hidden = sprintMode && !sprint;
    show('boardSprintEmpty', sprintMode && !sprint);

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

/**
 * วาดการ์ดงานหนึ่งใบ: งานซอฟต์แวร์มีไอคอนชนิดงาน เลขงาน งานแม่ และแต้ม
 * ส่วนโปรเจคประเภทอื่นยังใช้การ์ดเดิมทุกช่อง
 */
function renderKanbanCardItem(task) {
    if (isSoftwareProject()) return renderSoftwareKanbanCard(task);
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

/**
 * การ์ดงานของโปรเจคซอฟต์แวร์: ชนิดงานกับเลขงานบนสุด งานย่อยบอกงานแม่
 * และท้ายการ์ดมีป้ายงานใหญ่กับแต้ม
 */
function renderSoftwareKanbanCard(task) {
    const viewer = isViewer();
    const title = escHtml(task.title);
    const priority = (task.priority || 'Medium');
    const kind = task.kind || 'task';
    const key = taskKey(task);
    const parent = task.parent_id == null ? null : softwareTaskIndex.byId.get(Number(task.parent_id)) || null;
    const epic = parent && parent.kind === 'epic' ? parent : null;
    const open = openBlockers(task);
    const stuckTitle = open.length
        ? 'รอ ' + open.map(blocker => (taskKey(blocker) + ' ' + (blocker.title || '')).trim()).join(', ')
        : '';

    return '<div class="kanban-card' + (kind === 'sub' ? ' is-sub' : '') + '" data-id="' + task.id + '" data-priority="' + escHtml(priority.toLowerCase()) + '">'
        + (kind === 'sub' && parent
            ? '<span class="kcard-parent">↳ ' + escHtml(taskKey(parent)) + ' ' + escHtml(parent.title || '') + '</span>'
            : '')
        + '<div class="kcard-top">' + kindIcon(kind)
        + (key ? '<span class="key">' + escHtml(key) + '</span>' : '')
        // ป้ายอยู่ต่อจากเลขงาน ไม่ดันไปขวาสุด เพราะมุมขวาบนเป็นที่ของปุ่มแก้/ลบที่ลอยทับ
        + (open.length ? '<span class="stuck" title="' + escHtml(stuckTitle) + '">ติด</span>' : '')
        + '</div>'
        + '<div class="kanban-card-title">' + title + '</div>'
        + '<div class="kcard-meta">'
        + '<span class="kcard-priority">' + escHtml(PRIORITY_LABEL[priority] || priority) + '</span>'
        + (task.category ? '<span class="tag kcard-tag">' + escHtml(task.category) + '</span>' : '')
        + taskChecklist(task) + taskDue(task)
        + (task.assignee_name ? '<span class="kcard-who" title="ผู้รับผิดชอบ: ' + escHtml(task.assignee_name) + '">' + escHtml(task.assignee_name) + '</span>' : '')
        + '</div>'
        + '<div class="kcard-foot">'
        + (epic ? '<span class="epic-tag" title="' + escHtml(epic.title) + '">' + escHtml(epic.title) + '</span>' : '')
        + '<span class="sp"></span>' + pointsPill(task.story_points)
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

/** สร้างงานในคอลัมน์ที่กำหนดด้วย column_id แล้วโหลดบอร์ดใหม่ (โปรเจคซอฟต์แวร์สร้างงานธรรมดา และโหมดรอบงานใส่รอบที่กำลังทำ) */
async function submitQuickAdd(columnId) {
    const input = document.getElementById('quickadd-col-' + Number(columnId) + '-input');
    const title = input.value.trim();
    if (!title) { input.focus(); return; }

    const body = { title, column_id: Number(columnId), priority: 'Medium' };
    if (isSoftwareProject()) body.kind = 'task';
    if (typeof isSprintMode === 'function' && isSprintMode()) {
        const sprint = activeSprint();
        if (sprint) body.sprint_id = Number(sprint.id);
    }

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/tasks', {
            method: 'POST',
            body: JSON.stringify(body)
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
 * โปรเจคซอฟต์แวร์เติมชนิดงาน งานแม่ แต้ม และรายการงานลูกในแผงเดียวกัน
 */
function openEditTask(id) {
    buildTaskIndex();
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
    fillSoftwareTaskFields(task);

    currentChecklist = [];
    if (task.checklist) {
        try { currentChecklist = JSON.parse(task.checklist) || []; } catch (_) { /* an unreadable list starts empty */ }
    }
    renderChecklist();
    renderTaskCustomFields(task);
    renderSoftwareTaskSections(task);
    openModal('editTaskModal');
    setTaskParam(task.id);
    loadTaskHistory(task.id);
    loadTaskComments(task.id);
    loadTaskFiles(task.id);
}

/**
 * เติมช่องเฉพาะงานซอฟต์แวร์ในแผงรายละเอียด: ชนิดงาน แต้ม งานใหญ่ งานแม่ และรอบงาน
 * ซ่อนทั้งชุดเมื่อโปรเจคไม่ใช่ซอฟต์แวร์ และซ่อนช่องรอบงานเมื่อโปรเจคไม่ได้ใช้รอบงาน
 */
function fillSoftwareTaskFields(task) {
    const software = isSoftwareProject();
    const keyEl = document.getElementById('editTaskKey');
    if (keyEl) {
        const key = software ? taskKey(task) : '';
        keyEl.textContent = key;
        keyEl.hidden = !key;
    }
    const wrap = document.getElementById('editTaskSoftware');
    if (!wrap) return;
    wrap.hidden = !software;
    if (!software) return;

    const tasks = (activeProjectData && activeProjectData.tasks) || [];
    const kind = task.kind || 'task';

    const kindSelect = document.getElementById('editTaskKind');
    kindSelect.innerHTML = Object.keys(KIND_LABEL).map(value =>
        '<option value="' + escHtml(value) + '">' + escHtml(KIND_LABEL[value]) + '</option>').join('');
    kindSelect.value = kind;

    const pointsSelect = document.getElementById('editTaskPoints');
    const currentPoints = task.story_points == null || task.story_points === '' ? '' : String(task.story_points);
    const choices = pointChoices().map(String);
    pointsSelect.innerHTML = '<option value="">—</option>' + choices.map(value =>
        '<option value="' + escHtml(value) + '">' + escHtml(value) + '</option>').join('');
    if (currentPoints && !choices.includes(currentPoints)) {
        pointsSelect.insertAdjacentHTML('beforeend', '<option value="' + escHtml(currentPoints) + '">' + escHtml(currentPoints) + '</option>');
    }
    pointsSelect.value = currentPoints;

    // รอบงาน: เรื่องผู้ใช้/งาน/บั๊ก เลือกได้ระหว่างคลังงานกับรอบที่ยังไม่ปิด
    // รอบที่ปิดแล้วยังแสดงเป็นตัวเลือกของงานที่อยู่รอบนั้น เพื่อไม่ให้การแก้ช่องอื่นย้ายงานออกโดยไม่ตั้งใจ
    const sprintSelect = document.getElementById('editTaskSprint');
    if (sprintSelect) {
        const sprints = typeof isSprintMode === 'function' && isSprintMode()
            ? projectSprints().filter(sprint => sprint.state !== 'closed')
            : [];
        const currentSprint = task.sprint_id == null || task.sprint_id === ''
            ? null : sprintById(task.sprint_id);
        let options = '<option value="">คลังงาน</option>' + sprints.map(sprint =>
            '<option value="' + Number(sprint.id) + '">' + escHtml(sprint.name)
            + ' (' + escHtml(SPRINT_STATE_LABEL[sprint.state] || sprint.state) + ')</option>').join('');
        if (currentSprint && !sprints.some(sprint => Number(sprint.id) === Number(currentSprint.id))) {
            options += '<option value="' + Number(currentSprint.id) + '">' + escHtml(currentSprint.name)
                + ' (' + escHtml(SPRINT_STATE_LABEL[currentSprint.state] || currentSprint.state) + ')</option>';
        }
        sprintSelect.innerHTML = options;
        const sprintId = currentSprint ? String(Number(currentSprint.id)) : '';
        sprintSelect.value = sprintId;
        sprintSelect.dataset.original = sprintId;
    }

    const epicSelect = document.getElementById('editTaskEpic');
    const epics = tasks.filter(item => (item.kind || 'task') === 'epic' && Number(item.id) !== Number(task.id));
    epicSelect.innerHTML = '<option value="">ไม่มี</option>' + epics.map(item =>
        '<option value="' + Number(item.id) + '">' + escHtml(taskKey(item) + ' ' + (item.title || '')) + '</option>').join('');
    const parent = task.parent_id == null ? null : softwareTaskIndex.byId.get(Number(task.parent_id)) || null;
    epicSelect.value = parent && parent.kind === 'epic' ? String(parent.id) : '';

    const parentSelect = document.getElementById('editTaskParent');
    const parents = tasks.filter(item => ['story', 'task', 'bug'].includes(item.kind || 'task') && Number(item.id) !== Number(task.id));
    parentSelect.innerHTML = '<option value="">เลือกงานแม่</option>' + parents.map(item =>
        '<option value="' + Number(item.id) + '">' + escHtml(taskKey(item) + ' ' + (item.title || '')) + '</option>').join('');
    parentSelect.value = parent && parent.kind !== 'epic' ? String(parent.id) : '';

    toggleSoftwareTaskFields(kind);
}

/** แสดงช่องที่ใช้กับชนิดงานที่เลือก: แต้ม งานใหญ่ งานแม่ และรอบงานตามชนิดและโหมดรอบงาน */
function toggleSoftwareTaskFields(kind) {
    const pointsGroup = document.getElementById('editTaskPointsGroup');
    const epicGroup = document.getElementById('editTaskEpicGroup');
    const parentGroup = document.getElementById('editTaskParentGroup');
    const sprintGroup = document.getElementById('editTaskSprintGroup');
    if (pointsGroup) pointsGroup.hidden = kind === 'epic';
    if (epicGroup) epicGroup.hidden = !['story', 'task', 'bug'].includes(kind);
    if (parentGroup) parentGroup.hidden = kind !== 'sub';
    if (sprintGroup) {
        sprintGroup.hidden = !(typeof isSprintMode === 'function' && isSprintMode())
            || !['story', 'task', 'bug'].includes(kind);
    }
    const parentSelect = document.getElementById('editTaskParent');
    if (parentSelect) parentSelect.required = kind === 'sub';
}

/** เปลี่ยนชนิดงานในแผงรายละเอียดแล้วแสดง/ซ่อนช่องที่เกี่ยวข้องทันที */
function handleTaskKindChange(value) {
    toggleSoftwareTaskFields(value);
}

/** วาดส่วนงานย่อยของเรื่องผู้ใช้/งาน/บั๊ก ส่วนงานลูกของงานใหญ่ และงานขวางในแผงรายละเอียด */
function renderSoftwareTaskSections(task) {
    const subtasks = document.getElementById('taskSubtasksSection');
    const epicSection = document.getElementById('taskEpicChildrenSection');
    const blocksSection = document.getElementById('taskBlocksSection');
    if (!subtasks || !epicSection) return;
    if (!isSoftwareProject()) {
        subtasks.hidden = true;
        epicSection.hidden = true;
        if (blocksSection) blocksSection.hidden = true;
        return;
    }

    renderSoftwareTaskBlocks(task);

    const kind = task.kind || 'task';
    if (['story', 'task', 'bug'].includes(kind)) {
        subtasks.hidden = false;
        epicSection.hidden = true;
        const children = childrenOf(task.id).filter(item => item.kind === 'sub');
        const done = children.filter(isDoneTask).length;
        document.getElementById('taskSubtasksHeading').innerHTML = 'งานย่อย <small>' + done + '/' + children.length + '</small>';
        document.getElementById('taskSubtasksList').innerHTML = children.length
            ? children.map(softwareChildRow).join('')
            : '<p class="proj-note">ยังไม่มีงานย่อย</p>';
        show('taskAddSubtaskButton', !isViewer());
        return;
    }

    if (kind === 'epic') {
        subtasks.hidden = true;
        epicSection.hidden = false;
        const progress = epicProgress(task.id);
        const percent = progress.total ? Math.round(progress.done / progress.total * 100) : 0;
        document.getElementById('taskEpicChildrenHeading').innerHTML = 'งานในงานใหญ่นี้ <small>เสร็จ '
            + progress.done + '/' + progress.total + ' · ' + percent + '%</small>';
        const meter = document.getElementById('taskEpicChildrenMeter');
        meter.max = progress.total || 1;
        meter.value = progress.done;
        meter.setAttribute('aria-label', 'เสร็จ ' + progress.done + ' จาก ' + progress.total + ' งาน');
        document.getElementById('taskEpicChildrenList').innerHTML = progress.total
            ? childrenOf(task.id).map(softwareChildRow).join('')
            : '<p class="proj-note">ยังไม่มีงานในงานใหญ่นี้</p>';
        return;
    }

    subtasks.hidden = true;
    epicSection.hidden = true;
}

/**
 * วาดส่วน "งานขวาง" ของงานที่เปิดอยู่: แถวที่ต้องรอก่อน แล้วตามด้วยแถวที่งานนี้ขวาง
 * หัวข้อบอกจำนวนงานที่ยังติด ผู้ดูอย่างเดียวเห็นรายการแต่ไม่มีปุ่มเอาออกและไม่มีฟอร์มผูก
 * @param {object} task งานที่เปิดในแผง
 */
function renderSoftwareTaskBlocks(task) {
    const section = document.getElementById('taskBlocksSection');
    if (!section) return;
    section.hidden = false;

    const waits = blockersOf(task.id);
    const blocks = blockedTasksOf(task.id);
    const open = waits.filter(blocker => !isDoneTask(blocker));
    document.getElementById('taskBlocksHeading').innerHTML = 'งานขวาง'
        + (open.length ? ' <small>ยังติด ' + open.length + '</small>' : '');

    const viewer = isViewer();
    const rows = waits.map(blocker => softwareBlockRow(blocker, 'ต้องรอ', viewer))
        .concat(blocks.map(blocked => softwareBlockRow(blocked, 'ขวาง', viewer)));
    document.getElementById('taskBlocksList').innerHTML = rows.length
        ? rows.join('')
        : '<p class="proj-note">ยังไม่มีงานขวาง</p>';

    const form = document.getElementById('taskBlocksForm');
    if (form) form.hidden = viewer;
    const error = document.getElementById('taskBlockError');
    if (error) error.hidden = true;

    const datalist = document.getElementById('taskBlockOptions');
    if (datalist) {
        datalist.innerHTML = ((activeProjectData && activeProjectData.tasks) || [])
            .filter(item => Number(item.id) !== Number(task.id))
            .map(item => '<option value="' + escHtml((taskKey(item) + ' ' + (item.title || '')).trim()) + '"></option>')
            .join('');
    }
}

/** แถวงานขวางในแผงรายละเอียด: ความสัมพันธ์ เลขงาน ชื่องาน ชื่อคอลัมน์ และปุ่มเอาออก */
function softwareBlockRow(other, relation, viewer) {
    const column = columnById(other.column_id);
    const key = taskKey(other);
    return '<div class="dep-row">'
        + '<span class="rel">' + escHtml(relation) + '</span>'
        + (key ? '<span class="key">' + escHtml(key) + '</span>' : '')
        + '<span class="title">' + escHtml(other.title || '') + '</span>'
        + '<span class="state">' + escHtml(column ? column.name : '') + '</span>'
        + (viewer ? '' : '<button type="button" class="icon-btn sm" data-act="removeTaskBlock" data-args="[' + Number(other.id) + ']" aria-label="เอาออก: ' + escHtml(key) + '">×</button>')
        + '</div>';
}

/** แถวงานลูกในแผงรายละเอียด: ไอคอน เลขงาน ชื่องาน และชื่อคอลัมน์ */
function softwareChildRow(task) {
    const column = columnById(task.column_id);
    const key = taskKey(task);
    return '<div class="child-row" data-type="' + escHtml(task.kind || 'task') + '">'
        + kindIcon(task.kind || 'task')
        + (key ? '<span class="key">' + escHtml(key) + '</span>' : '')
        + '<span class="title">' + escHtml(task.title || '') + '</span>'
        + '<span class="state">' + escHtml(column ? column.name : '') + '</span>'
        + '</div>';
}

/** เพิ่มงานย่อยใต้งานที่เปิดอยู่: ถามชื่อแล้ว POST เข้าคอลัมน์เดียวกับงานแม่ */
async function addSubtask() {
    if (!isSoftwareProject() || isViewer()) return;
    const parentId = parseInt(document.getElementById('editTaskId').value, 10);
    const parent = softwareTaskIndex.byId.get(parentId);
    if (!parent) return;

    const title = (await askText({
        title: 'เพิ่มงานย่อย',
        message: 'งานย่อยจะอยู่คอลัมน์เดียวกับงานแม่ และมีเลขงานของตัวเอง',
        label: 'ชื่องานย่อย',
        okLabel: 'เพิ่ม',
        required: true
    }) || '').trim();
    if (!title) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/tasks', {
            method: 'POST',
            body: JSON.stringify({
                title,
                kind: 'sub',
                parent_id: parentId,
                column_id: Number(parent.column_id)
            })
        });
        toast('เพิ่มงานย่อยแล้ว');
        await selectProject(activeProjectId);
        await refreshProjectList();
        openEditTask(parentId);
    } catch (err) {
        formErrorLine('taskError', err.message || 'เพิ่มงานย่อยไม่สำเร็จ ลองอีกครั้ง');
    }
}

/**
 * เอาผลลัพธ์ขอบงานขวางจากเซิร์ฟเวอร์มาใส่ state แล้ววาดบอร์ดและแผงใหม่
 * ไม่โหลดโปรเจคซ้ำทั้งก้อน เพราะเซิร์ฟเวอร์คืน blocks ล่าสุดมาแล้ว
 * @param {object} task งานที่เปิดในแผง
 * @param {Array<object>} blocks ขอบทั้งหมดของโปรเจค
 */
function applyTaskBlocks(task, blocks) {
    activeProjectData.blocks = blocks || [];
    buildTaskIndex();
    renderKanbanCards();
    if (task) renderSoftwareTaskSections(task);
}

/**
 * ผูกงานในแผงกับงานที่พิมพ์ในช่อง: รับเลขงานนำหน้า (df-50 / DF-50 ชื่อ) หรือชื่อที่ตรงกับงานเดียว
 * แสดงข้อความผิดพลาดใน #taskBlockError และไม่ใช้กล่องของเบราว์เซอร์
 */
async function addTaskBlock() {
    if (!isSoftwareProject() || isViewer()) return;
    const taskId = parseInt(document.getElementById('editTaskId').value, 10);
    const task = softwareTaskIndex.byId.get(taskId);
    const input = document.getElementById('taskBlockInput');
    const relation = document.getElementById('taskBlockRelation').value;
    const errorEl = document.getElementById('taskBlockError');
    if (!task || !input || !errorEl) return;
    const showError = message => {
        errorEl.textContent = message;
        errorEl.hidden = false;
    };

    const text = input.value.trim();
    errorEl.hidden = true;
    if (!text) { input.focus(); return; }

    const allTasks = (activeProjectData && activeProjectData.tasks) || [];
    let target = null;
    const keyMatch = text.match(/^(\S+-\d+)(?=\s|$)/u);
    if (keyMatch) {
        const wanted = keyMatch[1].toLowerCase();
        target = allTasks.find(item => taskKey(item).toLowerCase() === wanted) || null;
    }
    if (!target) {
        const needle = text.toLowerCase();
        // ไม่นับงานที่เปิดอยู่ ชื่อที่ตรงกับตัวเองและอีกงานเดียวจึงยังผูกได้
        const matches = allTasks.filter(item => Number(item.id) !== taskId && (item.title || '').toLowerCase().includes(needle));
        if (matches.length === 0) { showError('ไม่พบงานนี้ในโปรเจค'); return; }
        if (matches.length > 1) { showError('พบหลายงาน พิมพ์เลขงานให้ชัด'); return; }
        target = matches[0];
    }
    if (Number(target.id) === taskId) { showError('งานขวางตัวเองไม่ได้'); return; }

    try {
        const data = await apiFetch(BASE_URL + '/api/projects/tasks/' + taskId + '/blocks', {
            method: 'POST',
            body: JSON.stringify({ other_id: Number(target.id), relation })
        });
        input.value = '';
        applyTaskBlocks(task, data.blocks);
        toast('ผูกงานแล้ว', 'success');
    } catch (err) {
        showError(err.message || 'ผูกงานไม่สำเร็จ ลองอีกครั้ง');
    }
}

/**
 * เอาความสัมพันธ์ของงานออกจากแผง แล้ววาดบอร์ดและแผงใหม่
 * @param {number} otherId งานอีกฝั่งของความสัมพันธ์
 */
async function removeTaskBlock(otherId) {
    if (!isSoftwareProject() || isViewer()) return;
    const taskId = parseInt(document.getElementById('editTaskId').value, 10);
    const task = softwareTaskIndex.byId.get(taskId);
    if (!task) return;
    const errorEl = document.getElementById('taskBlockError');

    try {
        const data = await apiFetch(BASE_URL + '/api/projects/tasks/' + taskId + '/blocks/' + Number(otherId), {
            method: 'DELETE'
        });
        applyTaskBlocks(task, data.blocks);
        toast('เอาออกแล้ว', 'success');
    } catch (err) {
        if (errorEl) {
            errorEl.textContent = err.message || 'เอาออกไม่สำเร็จ ลองอีกครั้ง';
            errorEl.hidden = false;
        }
    }
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
 * โปรเจคซอฟต์แวร์ส่งชนิดงาน งานแม่ แต้ม และรอบงานเฉพาะช่องที่ค่าเปลี่ยนจากตอนเปิดแผง
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
    if (isSoftwareProject()) {
        const loaded = (activeProjectData.tasks || []).find(task => Number(task.id) === id) || null;
        const loadedKind = loaded && loaded.kind ? loaded.kind : 'task';
        const loadedParent = loaded && loaded.parent_id != null && loaded.parent_id !== '' ? Number(loaded.parent_id) : null;
        const loadedPoints = loaded && loaded.story_points != null && loaded.story_points !== '' ? Number(loaded.story_points) : null;

        const kind = document.getElementById('editTaskKind').value;
        let parentId = null;
        if (kind === 'sub' || ['story', 'task', 'bug'].includes(kind)) {
            const select = kind === 'sub' ? document.getElementById('editTaskParent') : document.getElementById('editTaskEpic');
            parentId = parseInt(select.value, 10) || null;
        }
        const points = kind === 'epic' ? null : (parseInt(document.getElementById('editTaskPoints').value, 10) || null);

        if (kind !== loadedKind) payload.kind = kind;
        if (parentId !== loadedParent) payload.parent_id = parentId;
        if (points !== loadedPoints) payload.story_points = points;

        // ส่งรอบงานเฉพาะเมื่อเปลี่ยน และเฉพาะชนิดที่อยู่บนบอร์ดได้
        const sprintSelect = document.getElementById('editTaskSprint');
        if (sprintSelect && typeof isSprintMode === 'function' && isSprintMode()
            && ['story', 'task', 'bug'].includes(kind)) {
            const loadedSprint = loaded && loaded.sprint_id != null && loaded.sprint_id !== ''
                ? Number(loaded.sprint_id) : null;
            const sprintId = sprintSelect.value ? Number(sprintSelect.value) : null;
            if (sprintId !== loadedSprint) payload.sprint_id = sprintId;
        }
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

/** ลบงานหลังยืนยัน และบอกถ้างานนั้นมีงานย่อยที่จะถูกลบตามไปด้วย */
async function deleteTask(id) {
    buildTaskIndex();
    const task = (activeProjectData.tasks || []).find(t => t.id === id);
    let message = 'ลบ' + (task ? ' "' + task.title + '"' : 'งานนี้') + ' ออกจากบอร์ดแล้วกู้คืนไม่ได้';
    if (isSoftwareProject()) {
        const subs = childrenOf(id).filter(item => item.kind === 'sub');
        if (subs.length) message += ' · งานย่อย ' + subs.length + ' งานจะถูกลบด้วย';
    }
    if (!await confirmAction(message, 'ลบงาน', 'ลบงานนี้?')) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + id, { method: 'DELETE' });
        toast('ลบงานแล้ว');
        await selectProject(activeProjectId);
        await refreshProjectList();
    } catch {
        toast('ลบงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
