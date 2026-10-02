/* =====================================================
   projects-board.js — the board, its tasks and their checklists
   Loaded after projects.js on the projects page; shares its state.

   A card moves between columns by dragging, or by changing "อยู่ในคอลัมน์"
   in its dialog, so the board can be run from the keyboard as well.
===================================================== */

const COLUMNS = [
    { status: 'To Do',       slug: 'todo' },
    { status: 'In Progress', slug: 'inprogress' },
    { status: 'Review',      slug: 'review' },
    { status: 'Done',        slug: 'done' },
];

function isViewer() {
    return !!(activeProjectData && activeProjectData.project.user_role === 'Viewer');
}

function renderKanbanCards() {
    const viewer = isViewer();
    document.querySelectorAll('.kanban-quick-add').forEach(box => { box.hidden = viewer; });

    const tasks = activeProjectData.tasks || [];
    COLUMNS.forEach(col => {
        const mine = tasks.filter(t => t.status === col.status);
        document.getElementById(col.slug + '-count').textContent = mine.length;
        document.getElementById(col.slug + '-list').innerHTML = mine.length
            ? mine.map(renderKanbanCardItem).join('')
            : '<p class="kanban-empty">ไม่มีงานในคอลัมน์นี้</p>';
    });
}

function taskDue(task) {
    if (!task.due_date) return '';
    // A finished task is not late, whatever its date says.
    if (task.status === 'Done') return '<span class="kcard-due">' + escHtml(formatDate(task.due_date)) + '</span>';
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
        + (task.assignee ? '<span class="kcard-who" title="ผู้รับผิดชอบ: ' + escHtml(task.assignee) + '">' + escHtml(task.assignee) + '</span>' : '')
        + '</div>'
        + (viewer ? '' : '<div class="kcard-actions">'
            + '<button type="button" class="icon-btn sm" data-act="openEditTask" data-args="[' + task.id + ']" aria-label="แก้ไขงาน: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button>'
            + '<button type="button" class="icon-btn sm" data-act="deleteTask" data-args="[' + task.id + ']" aria-label="ลบงาน: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
            + '</div>')
        + '</div>';
}

/* ── Dragging ── */
function initSortable() {
    if (typeof Sortable === 'undefined') return;
    sortableInstances.forEach(inst => { try { inst.destroy(); } catch (_) { /* already gone */ } });
    sortableInstances = [];
    if (isViewer()) return;

    COLUMNS.forEach(col => {
        const el = document.getElementById(col.slug + '-list');
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

async function saveBoardOrder() {
    const items = [];
    COLUMNS.forEach(col => {
        document.querySelectorAll('#' + col.slug + '-list .kanban-card[data-id]').forEach((card, idx) => {
            items.push({ id: parseInt(card.dataset.id, 10), status: col.status, position: idx });
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
function slugOf(status) {
    return COLUMNS.find(c => c.status === status).slug;
}

function toggleQuickAddForm(status, show = true) {
    COLUMNS.forEach(c => { document.getElementById('quickadd-' + c.slug + '-form').hidden = true; });
    if (!show) return;
    const form = document.getElementById('quickadd-' + slugOf(status) + '-form');
    const input = document.getElementById('quickadd-' + slugOf(status) + '-input');
    form.hidden = false;
    input.value = '';
    input.focus();
}

function handleQuickAddKey(e, status) {
    if (e.key === 'Enter') { e.preventDefault(); submitQuickAdd(status); }
    else if (e.key === 'Escape') toggleQuickAddForm(status, false);
}

async function submitQuickAdd(status) {
    const input = document.getElementById('quickadd-' + slugOf(status) + '-input');
    const title = input.value.trim();
    if (!title) { input.focus(); return; }

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/tasks', {
            method: 'POST',
            body: JSON.stringify({ title, status, priority: 'Medium' })
        });
        toggleQuickAddForm(status, false);
        await selectProject(activeProjectId);
        await refreshProjectList();
        toast('เพิ่มงานแล้ว');
    } catch (err) {
        toast(err.message || 'เพิ่มงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/* ── A task and its checklist ── */
/** The people a task can go to: everyone on the team, and whoever it already names. */
function fillAssignees(current) {
    const select = document.getElementById('editTaskAssignee');
    const names = projectMembers.map(m => m.display_name || m.username).filter(Boolean);
    if (current && !names.includes(current)) names.push(current);
    select.innerHTML = '<option value="">ยังไม่ระบุ</option>' + names.map(n => '<option value="' + escHtml(n) + '">' + escHtml(n) + '</option>').join('');
    select.value = current || '';
}

function openEditTask(id) {
    const task = (activeProjectData.tasks || []).find(t => t.id === id);
    if (!task) return;

    document.getElementById('editTaskId').value = task.id;
    document.getElementById('editTaskTitle').value = task.title;
    document.getElementById('editTaskStatus').value = task.status;
    document.getElementById('editTaskPriority').value = task.priority;
    document.getElementById('editTaskCategory').value = task.category || '';
    fillAssignees(task.assignee || '');
    document.getElementById('editTaskDue').value = task.due_date || '';
    formErrorLine('taskError', '');

    currentChecklist = [];
    if (task.checklist) {
        try { currentChecklist = JSON.parse(task.checklist) || []; } catch (_) { /* an unreadable list starts empty */ }
    }
    renderChecklist();
    openModal('editTaskModal');
}

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

async function submitEditTask(e) {
    e.preventDefault();
    const id = parseInt(document.getElementById('editTaskId').value, 10);
    const title = document.getElementById('editTaskTitle').value.trim();
    if (!title) { formErrorLine('taskError', 'ตั้งชื่องานก่อน'); document.getElementById('editTaskTitle').focus(); return; }

    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + id, {
            method: 'PUT',
            body: JSON.stringify({
                title,
                status: document.getElementById('editTaskStatus').value,
                priority: document.getElementById('editTaskPriority').value,
                category: document.getElementById('editTaskCategory').value.trim(),
                assignee: document.getElementById('editTaskAssignee').value,
                due_date: document.getElementById('editTaskDue').value,
                checklist: currentChecklist,
            })
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
