/* =====================================================
   projects.js — projects and the open project's board

   The list of projects and the one that is open. The board, the team, the
   public link and the chat are in projects-board.js, projects-team.js and
   projects-share.js, which share the state declared here.
===================================================== */

let allProjects = [];
let activeProjectId = null;
let activeProjectData = null;     // the open project: tasks, activity, summary
let projectMembers = [];
let currentCalendarDate = new Date();
let currentChecklist = [];        // the checklist of the task being edited
let chatPollTimer = null;
let sortableInstances = [];

const STATUS_LABEL = { 'Planning': 'วางแผน', 'In Progress': 'กำลังทำ', 'Review': 'รอตรวจ', 'Completed': 'เสร็จแล้ว' };
const PRIORITY_LABEL = { 'Low': 'ต่ำ', 'Medium': 'ปานกลาง', 'High': 'สูง', 'Critical': 'วิกฤต' };
const COLUMN_LABEL = { 'To Do': 'ต้องทำ', 'In Progress': 'กำลังทำ', 'Review': 'รอตรวจ', 'Done': 'เสร็จแล้ว' };
const THAI_MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

document.addEventListener('DOMContentLoaded', async function () {
    await loadProjects();
    initSortable();
    renderCalendar();

    // A chat nobody is looking at is not worth asking the server about every few seconds.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopChatPolling();
        else if (activeProjectId) startChatPolling();
    });
});

function show(id, visible) {
    const el = document.getElementById(id);
    if (el) el.hidden = !visible;
}

function formErrorLine(id, message) {
    const line = document.getElementById(id);
    line.textContent = message;
    line.hidden = message === '';
}

/* ── The list ── */
async function loadProjects() {
    const grid = document.getElementById('projectsGrid');
    try {
        const data = await apiFetch(BASE_URL + '/api/projects');
        allProjects = data.projects || [];
        grid.removeAttribute('aria-busy');
        filterProjects();

        if (allProjects.length > 0) {
            let selectId = activeProjectId;
            if (!selectId && ACTIVE_PROJECT_ID_OVERRIDE && allProjects.some(p => p.id === ACTIVE_PROJECT_ID_OVERRIDE)) {
                selectId = ACTIVE_PROJECT_ID_OVERRIDE;
            }
            if (!selectId || !allProjects.some(p => p.id === selectId)) selectId = allProjects[0].id;
            await selectProject(selectId);
        } else {
            activeProjectId = null;
            stopChatPolling();
            ['kanbanBoardSection', 'aiSummaryCardWrap', 'analyticsWidgetCard', 'activityWidgetCard', 'chatWidgetCard', 'smartWarningBanner']
                .forEach(id => show(id, false));
            show('projectsEmptyState', true);
        }
    } catch {
        grid.removeAttribute('aria-busy');
        document.getElementById('projectsTally').textContent = 'โหลดโปรเจคไม่ได้';
        grid.innerHTML = '<div class="alert alert-danger" role="alert">โหลดโปรเจคไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="loadProjects">ลองอีกครั้ง</button></div>';
    }
}

/** "เกินกำหนด 3 วัน", "ส่งวันนี้", "เหลือ 5 วัน", or nothing for a project with no date. */
function dueWords(dueDate) {
    if (!dueDate) return { text: 'ไม่มีกำหนดส่ง', cls: '' };
    const days = daysUntil(dueDate);
    if (days === null) return { text: formatDate(dueDate), cls: '' };
    if (days < 0) return { text: 'เกินกำหนด ' + Math.abs(days) + ' วัน', cls: 'late' };
    if (days === 0) return { text: 'ส่งวันนี้', cls: 'soon' };
    return { text: 'เหลือ ' + days + ' วัน', cls: '' };
}

function projectRow(p) {
    const total = parseInt(p.total_tasks || 0, 10);
    const done = parseInt(p.completed_tasks || 0, 10);
    const percent = total > 0 ? Math.round(done / total * 100) : 0;
    const due = dueWords(p.due_date);
    const name = escHtml(p.name);
    const current = p.id === activeProjectId;

    return '<li><button type="button" class="proj-row" data-priority="' + escHtml(p.priority.toLowerCase()) + '" aria-pressed="' + current + '" data-act="selectProject" data-args="[' + p.id + ']">'
        + '<span class="proj-row-main"><span class="proj-row-name">' + name + '</span>'
        + '<span class="proj-row-desc">' + (p.description ? escHtml(p.description) : 'ไม่มีคำอธิบาย') + '</span></span>'
        + '<span class="proj-row-status">' + escHtml(STATUS_LABEL[p.status] || p.status) + '</span>'
        + '<span class="proj-row-priority">' + escHtml(PRIORITY_LABEL[p.priority] || p.priority) + '</span>'
        + '<span class="proj-row-due ' + due.cls + '">' + escHtml(due.text) + '</span>'
        + '<span class="proj-row-progress"><span class="progress" aria-hidden="true"><span class="progress-bar" style="--v:' + percent + '%"></span></span>'
        + '<span class="proj-row-pct">' + percent + '%<span class="sr-only"> เสร็จ</span></span></span>'
        + '</button></li>';
}

function renderProjectsList(list) {
    const grid = document.getElementById('projectsGrid');
    document.getElementById('projectsTally').textContent = allProjects.length
        ? (list.length === allProjects.length ? allProjects.length + ' โปรเจค' : 'แสดง ' + list.length + ' จาก ' + allProjects.length + ' โปรเจค')
        : 'ยังไม่มีโปรเจค';

    if (!allProjects.length) { grid.innerHTML = ''; return; }
    show('projectsEmptyState', false);

    if (!list.length) {
        grid.innerHTML = '<div class="empty-state"><p class="empty-state-text">ไม่พบโปรเจคที่ตรงกับตัวกรอง</p>'
            + '<button type="button" class="btn btn-sm" data-act="clearProjectFilters">ล้างตัวกรอง</button></div>';
        return;
    }
    grid.innerHTML = '<ul class="ruled-list">' + list.map(projectRow).join('') + '</ul>';
}

function clearProjectFilters() {
    document.getElementById('projectSearch').value = '';
    document.getElementById('projectStatusFilter').value = '';
    document.getElementById('projectPriorityFilter').value = '';
    filterProjects();
}

function filterProjects() {
    const q = document.getElementById('projectSearch').value.trim().toLowerCase();
    const status = document.getElementById('projectStatusFilter').value;
    const priority = document.getElementById('projectPriorityFilter').value;
    const sort = document.getElementById('projectSort').value;

    const list = allProjects.filter(p =>
        (p.name.toLowerCase().includes(q) || (p.description && p.description.toLowerCase().includes(q)))
        && (!status || p.status === status)
        && (!priority || p.priority === priority));

    if (sort === 'priority') {
        const order = { 'Critical': 1, 'High': 2, 'Medium': 3, 'Low': 4 };
        list.sort((a, b) => order[a.priority] - order[b.priority]);
    } else if (sort === 'due_date') {
        list.sort((a, b) => (a.due_date ? new Date(a.due_date) : Infinity) - (b.due_date ? new Date(b.due_date) : Infinity));
    } else if (sort === 'name') {
        list.sort((a, b) => a.name.localeCompare(b.name, 'th'));
    }
    renderProjectsList(list);
}

/** After a change, the list's counts and progress bars are read again. */
async function refreshProjectList() {
    const data = await apiFetch(BASE_URL + '/api/projects');
    allProjects = data.projects || [];
    filterProjects();
}

/* ── Opening a project ── */
async function selectProject(projectId) {
    activeProjectId = projectId;
    stopChatPolling();

    try {
        const data = await apiFetch(BASE_URL + '/api/projects/' + projectId + '/tasks');
        activeProjectData = data;
        document.getElementById('activeProjectTitle').textContent = data.project.name;

        show('btnInviteMember', true);   // everyone can see who is on the team
        show('btnEditProject', !!data.project.is_owner);
        show('btnDeleteProject', !!data.project.is_owner);

        await loadProjectMembers(false);

        ['kanbanBoardSection', 'aiSummaryCardWrap', 'analyticsWidgetCard', 'activityWidgetCard', 'chatWidgetCard']
            .forEach(id => show(id, true));
        show('projectsEmptyState', false);

        const guestBox = document.getElementById('guestRenameContainer');
        if (CURRENT_GUEST_NAME && guestBox) {
            guestBox.hidden = false;
            document.getElementById('lblGuestName').textContent = 'คุณ: ' + CURRENT_GUEST_NAME;
        }

        renderKanbanCards();
        initSortable();
        updateAnalyticsCharts();
        renderActivityFeed();
        renderAiInsights();
        renderCalendar();
        filterProjects();

        await fetchChatMessages();
        startChatPolling();
    } catch {
        toast('โหลดโปรเจคที่เลือกไม่สำเร็จ', 'danger');
    }
}

function startChatPolling() {
    stopChatPolling();
    chatPollTimer = setInterval(fetchChatMessages, 3000);
}

function stopChatPolling() {
    if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; }
}

/* ── The calendar ── */
function renderCalendar() {
    const grid = document.getElementById('miniCalendarGrid');
    const title = document.getElementById('calendarMonthTitle');
    if (!grid || !title) return;

    const year = currentCalendarDate.getFullYear();
    const month = currentCalendarDate.getMonth();
    title.textContent = THAI_MONTHS[month] + ' ' + (year + 543);

    // The days that have something due: every project, and the open project's tasks.
    const deadlines = new Set();
    allProjects.forEach(p => { if (p.due_date) deadlines.add(p.due_date); });
    if (activeProjectData && activeProjectData.tasks) {
        activeProjectData.tasks.forEach(t => { if (t.due_date) deadlines.add(t.due_date); });
    }

    const firstDay = new Date(year, month, 1).getDay();
    const totalDays = new Date(year, month + 1, 0).getDate();
    const prevTotal = new Date(year, month, 0).getDate();
    const today = new Date();

    let html = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'].map(d => '<div class="mini-cal-day-label" aria-hidden="true">' + d + '</div>').join('');
    for (let i = firstDay; i > 0; i--) html += '<div class="mini-cal-cell other-month" aria-hidden="true">' + (prevTotal - i + 1) + '</div>';

    for (let i = 1; i <= totalDays; i++) {
        const key = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(i).padStart(2, '0');
        const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === i;
        const has = deadlines.has(key);
        html += '<div class="mini-cal-cell' + (isToday ? ' cal-today' : '') + (has ? ' has-deadline' : '') + '"'
            + (isToday ? ' aria-current="date"' : '') + '>' + i
            + (has ? '<span class="sr-only"> มีกำหนดส่ง</span>' : '') + '</div>';
    }

    const filled = firstDay + totalDays;
    for (let i = 1; i <= 42 - filled; i++) html += '<div class="mini-cal-cell other-month" aria-hidden="true">' + i + '</div>';
    grid.innerHTML = html;
}

function navCalendar(direction) {
    currentCalendarDate.setMonth(currentCalendarDate.getMonth() + direction);
    renderCalendar();
}

/* ── Figures ── */
function updateAnalyticsCharts() {
    const tasks = activeProjectData.tasks || [];
    const done = tasks.filter(t => t.status === 'Done').length;
    const percent = tasks.length > 0 ? Math.round(done / tasks.length * 100) : 0;

    document.getElementById('statCompletedTasks').textContent = done;
    document.getElementById('statRemainingTasks').textContent = tasks.length - done;
    document.getElementById('statProductivity').textContent = percent + '%';

    const counts = { 'To Do': 0, 'In Progress': 0, 'Review': 0, 'Done': 0 };
    tasks.forEach(t => { if (counts[t.status] !== undefined) counts[t.status]++; });

    // One bar split by status, and the same figures as words.
    const bar = document.getElementById('projectStatusBar');
    bar.innerHTML = tasks.length
        ? Object.entries(counts).filter(([, n]) => n > 0).map(([status, n]) =>
            '<span class="seg" data-status="' + status.toLowerCase().replace(' ', '-') + '" style="--w:' + (n / tasks.length * 100) + '%"></span>').join('')
        : '';
    bar.classList.toggle('empty', !tasks.length);

    document.getElementById('projectStatusLegend').innerHTML = tasks.length
        ? Object.entries(counts).map(([status, n]) =>
            '<li data-status="' + status.toLowerCase().replace(' ', '-') + '"><span class="key" aria-hidden="true"></span>' + COLUMN_LABEL[status] + ' <strong>' + n + '</strong></li>').join('')
        : '<li class="proj-note">ยังไม่มีงานในบอร์ด</li>';
}

function renderActivityFeed() {
    const list = document.getElementById('projectActivityList');
    const acts = activeProjectData.activities || [];
    list.innerHTML = acts.length
        ? acts.map(a => '<li class="activity-feed-item"><span class="activity-item-text">' + escHtml(a.action) + '</span>'
            + '<span class="activity-item-time">' + escHtml(chatTime(a.created_at)) + '</span></li>').join('')
        : '<li class="proj-note">ยังไม่มีกิจกรรมที่บันทึกไว้</li>';
}

/** The summary is worked out on the server from task counts, priorities and dates; it is not AI. */
function renderAiInsights() {
    const ai = activeProjectData.ai;
    if (!ai) return;

    document.getElementById('aiInsightText').textContent = ai.insight;
    const warnBox = document.getElementById('aiWarningBox');
    warnBox.textContent = ai.warning || '';
    warnBox.hidden = !ai.warning;

    const urgent = ai.warning && (ai.status === 'warning' || ai.status === 'danger');
    document.getElementById('smartWarningText').textContent = urgent ? 'โปรเจคนี้ต้องดูด่วน: ' + ai.insight : '';
    show('smartWarningBanner', !!urgent);
}

/* ── New and edit ── */
function openCreateProjectModal() {
    document.getElementById('createProjectForm').reset();
    formErrorLine('newProjError', '');
    openModal('createProjectModal');
    document.getElementById('newProjName').focus();
}

async function submitCreateProject(e) {
    e.preventDefault();
    const name = document.getElementById('newProjName').value.trim();
    if (!name) { formErrorLine('newProjError', 'ตั้งชื่อโปรเจคก่อน'); document.getElementById('newProjName').focus(); return; }

    try {
        const res = await apiFetch(BASE_URL + '/api/projects', {
            method: 'POST',
            body: JSON.stringify({
                name,
                description: document.getElementById('newProjDesc').value.trim(),
                priority: document.getElementById('newProjPriority').value,
                status: document.getElementById('newProjStatus').value,
                due_date: document.getElementById('newProjDue').value,
            })
        });
        closeModal('createProjectModal');
        toast('สร้างโปรเจคแล้ว');
        activeProjectId = res.id;
        await loadProjects();
    } catch (err) {
        formErrorLine('newProjError', err.message || 'สร้างโปรเจคไม่สำเร็จ ลองอีกครั้ง');
    }
}

function openEditProjectModal() {
    if (!activeProjectData || !activeProjectData.project) return;
    const p = activeProjectData.project;
    document.getElementById('editProjId').value = p.id;
    document.getElementById('editProjName').value = p.name;
    document.getElementById('editProjDesc').value = p.description || '';
    document.getElementById('editProjPriority').value = p.priority;
    document.getElementById('editProjStatus').value = p.status;
    document.getElementById('editProjDue').value = p.due_date || '';
    formErrorLine('editProjError', '');
    openModal('editProjectModal');
}

async function submitEditProject(e) {
    e.preventDefault();
    const id = parseInt(document.getElementById('editProjId').value, 10);
    const name = document.getElementById('editProjName').value.trim();
    if (!name) { formErrorLine('editProjError', 'ตั้งชื่อโปรเจคก่อน'); return; }

    try {
        await apiFetch(BASE_URL + '/api/projects/' + id, {
            method: 'PUT',
            body: JSON.stringify({
                name,
                description: document.getElementById('editProjDesc').value.trim(),
                priority: document.getElementById('editProjPriority').value,
                status: document.getElementById('editProjStatus').value,
                due_date: document.getElementById('editProjDue').value,
            })
        });
        closeModal('editProjectModal');
        toast('บันทึกโปรเจคแล้ว');
        await loadProjects();
        await selectProject(activeProjectId);
    } catch (err) {
        formErrorLine('editProjError', err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function deleteActiveProject() {
    if (!activeProjectId) return;
    const name = activeProjectData && activeProjectData.project ? '"' + activeProjectData.project.name + '"' : 'โปรเจคนี้';
    if (!await confirmAction('ลบ ' + name + ' แล้วงานทั้งหมดบนบอร์ด กิจกรรม และแชทจะหายถาวร', 'ลบโปรเจค', 'ลบโปรเจคนี้?')) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId, { method: 'DELETE' });
        toast('ลบโปรเจคแล้ว');
        activeProjectId = null;
        await loadProjects();
    } catch {
        toast('ลบโปรเจคไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
