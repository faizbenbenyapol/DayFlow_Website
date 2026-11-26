/* =====================================================
   projects-sprints.js — รอบงาน (sprint) และคลังงานของโปรเจคซอฟต์แวร์
   โหลดหลัง projects-software.js ก่อน projects-views.js และใช้สถานะร่วมกับไฟล์อื่น

   ทุกส่วนในไฟล์นี้ทำงานเฉพาะเมื่อโปรเจคเป็นซอฟต์แวร์และเปิดใช้รอบงาน
   (project.use_sprints) ประเภทอื่นและโปรเจคที่ปิดรอบงานยังใช้หน้าจอเดิมทุกอย่าง
===================================================== */

/** ชื่อสถานะรอบงานภาษาไทย ใช้บนป้ายและตัวเลือกในแผงรายละเอียดงาน */
const SPRINT_STATE_LABEL = { planned: 'วางแผน', active: 'กำลังทำ', closed: 'ปิดแล้ว' };

/** รหัสรอบงานที่กำลังแก้แบบ inline อยู่ในคลังงาน (null = ไม่มี) */
let sprintEditId = null;

/** Sortable ของบล็อกในคลังงาน สร้างใหม่ทุกครั้งที่วาด */
let backlogSortables = [];

/** รหัสรอบงานที่กล่องปิดรอบกำลังจะปิด (null = ยังไม่เปิด) */
let closeSprintId = null;

/** true เมื่อโปรเจคที่เปิดอยู่เป็นซอฟต์แวร์และเปิดใช้รอบงาน */
function isSprintMode() {
    const project = activeProjectData && activeProjectData.project;
    return isSoftwareProject() && !!project && !!Number(project.use_sprints);
}

/** รอบงานทั้งหมดของโปรเจคที่โหลดอยู่ (เรียงตามที่เซิร์ฟเวอร์ส่งมา) */
function projectSprints() {
    return (activeProjectData && activeProjectData.sprints) || [];
}

/** รอบงานที่กำลังทำ หรือ null เมื่อยังไม่มีรอบที่เริ่มแล้ว */
function activeSprint() {
    return projectSprints().find(sprint => sprint.state === 'active') || null;
}

/** รอบงานที่วางแผนไว้ เรียงตามวันเริ่มและรหัสจากเซิร์ฟเวอร์ */
function plannedSprints() {
    return projectSprints().filter(sprint => sprint.state === 'planned');
}

/** หารอบงานจากรหัส หรือ null เมื่อไม่พบ */
function sprintById(id) {
    return projectSprints().find(sprint => Number(sprint.id) === Number(id)) || null;
}

/** งานที่ไม่ใช่งานใหญ่ของรอบหนึ่ง (งานย่อย included ตามข้อมูลที่โหลด) */
function sprintTaskList(sprintId) {
    const tasks = (activeProjectData && activeProjectData.tasks) || [];
    return tasks.filter(task => (task.kind || 'task') !== 'epic'
        && task.sprint_id != null && Number(task.sprint_id) === Number(sprintId));
}

/** ผลรวมแต้มของงานในรายการ: ทุกงาน และเฉพาะงานที่เสร็จ */
function sumTaskPoints(tasks) {
    let total = 0;
    let done = 0;
    tasks.forEach(task => {
        const points = Number(task.story_points) || 0;
        total += points;
        if (isDoneTask(task)) done += points;
    });
    return { total, done };
}

/** วันที่สั้นแบบ "16–29 พ.ย." หรือ "30 พ.ย.–13 ธ.ค." ของช่วงรอบงาน */
function sprintRange(startDate, endDate) {
    const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
        'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    if (!startDate && !endDate) return '';
    if (!startDate) return formatDate(endDate);
    if (!endDate) return formatDate(startDate);
    const start = parseServerDate(startDate);
    const end = parseServerDate(endDate);
    if (start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()) {
        return start.getDate() + '–' + end.getDate() + ' ' + months[end.getMonth()];
    }
    return start.getDate() + ' ' + months[start.getMonth()] + '–'
        + end.getDate() + ' ' + months[end.getMonth()];
}

/** คำบอกเวลาที่เหลือถึงวันจบของรอบ โดยนับจากวันของเซิร์ฟเวอร์ */
function sprintRemainingWords(endDate) {
    if (!endDate) return '';
    const days = serverDaysUntil(endDate);
    if (days === null) return '';
    if (days < 0) return 'เลยกำหนด ' + Math.abs(days) + ' วัน';
    if (days === 0) return 'วันสุดท้าย';
    return 'เหลือ <b>' + days + '</b> วัน';
}

/** วาดแถบรอบงานเหนือบอร์ดและซ่อนแท็บคลังงานเมื่อไม่ได้ใช้รอบงาน */
function renderSprintChrome() {
    const mode = isSprintMode();
    show('tabBacklog', mode);
    if (!mode && projectSettingsTab === 'backlog') showProjectTab('board');

    const bar = document.getElementById('boardSprintBar');
    if (!bar) return;
    const sprint = mode ? activeSprint() : null;
    if (!sprint) {
        bar.innerHTML = '';
        bar.hidden = true;
        return;
    }

    const stats = sumTaskPoints(sprintTaskList(sprint.id));
    const committed = Number(sprint.committed_points) || 0;
    const total = committed > 0 ? committed : stats.total;
    const max = Math.max(total, stats.done, 1);
    const range = sprintRange(sprint.start_date, sprint.end_date);
    const remaining = sprintRemainingWords(sprint.end_date);

    bar.innerHTML = '<div>'
        + '<h3>' + escHtml(sprint.name) + (range ? ' · ' + escHtml(range) : '') + '</h3>'
        + (sprint.goal ? '<p class="goal">เป้าหมาย: ' + escHtml(sprint.goal) + '</p>' : '')
        + '</div>'
        + (isProjectAdmin()
            ? '<button class="btn btn-sm" type="button" data-act="openCloseSprint" data-args="[' + Number(sprint.id) + ']">ปิดรอบ</button>'
            : '')
        + '<div class="sprint-meta">'
        + (remaining ? '<span>' + remaining + '</span>' : '')
        + '<span>เสร็จ <b>' + stats.done + '</b> จาก <b>' + total + '</b> แต้ม '
        + '<progress class="sprint-meter" max="' + max + '" value="' + stats.done + '"'
        + ' aria-label="เสร็จ ' + stats.done + ' จาก ' + total + ' แต้ม"></progress></span>'
        + '</div>';
    bar.hidden = false;
}

/** เรียงงานในบล็อกตาม backlog_rank แล้วตามเลขงาน */
function compareBacklogRank(a, b) {
    const rankA = a.backlog_rank == null || a.backlog_rank === '' ? null : Number(a.backlog_rank);
    const rankB = b.backlog_rank == null || b.backlog_rank === '' ? null : Number(b.backlog_rank);
    if (rankA !== null && rankB !== null && rankA !== rankB) return rankA - rankB;
    if (rankA === null && rankB !== null) return 1;
    if (rankB === null && rankA !== null) return -1;
    return Number(a.number || 0) - Number(b.number || 0);
}

/** วาดบล็อกรอบงานและคลังงานทั้งหมด แล้วผูกการลากใหม่ */
function renderBacklog() {
    const box = document.getElementById('backlogBlocks');
    if (!box) return;

    const mode = isSprintMode();
    show('btnAddSprint', mode && isProjectAdmin());
    if (!mode) {
        box.innerHTML = '';
        show('backlogFilterNote', false);
        initBacklogSortables();
        return;
    }

    buildTaskIndex();
    const rows = filteredTasks().filter(task => !['epic', 'sub'].includes(task.kind || 'task'));
    const byBlock = new Map();
    rows.forEach(task => {
        const key = task.sprint_id == null || task.sprint_id === '' ? '' : String(Number(task.sprint_id));
        if (!byBlock.has(key)) byBlock.set(key, []);
        byBlock.get(key).push(task);
    });
    byBlock.forEach(list => list.sort(compareBacklogRank));

    // งานใหญ่ของแต่ละงานอ่านจากดัชนีที่สร้างครั้งเดียว ไม่ค้นซ้ำในลูป
    const epicOf = new Map();
    rows.forEach(task => {
        const parent = task.parent_id == null ? null : softwareTaskIndex.byId.get(Number(task.parent_id)) || null;
        epicOf.set(Number(task.id), parent && (parent.kind || 'task') === 'epic' ? parent : null);
    });

    const active = activeSprint();
    const planned = plannedSprints();
    let html = '';
    if (active) {
        html += sprintBlockHtml(active, byBlock.get(String(Number(active.id))) || [], epicOf, false);
    }
    planned.forEach(sprint => {
        html += sprintBlockHtml(sprint, byBlock.get(String(Number(sprint.id))) || [], epicOf, !!active);
    });
    html += backlogBlockHtml(byBlock.get('') || [], epicOf);

    box.innerHTML = html;
    show('backlogFilterNote', hasTaskFilter() && !isViewer());
    initBacklogSortables();
}

/** บล็อกรอบงานหนึ่งบล็อก: หัวบล็อก ปุ่มของผู้ดูแล และรายการงาน */
function sprintBlockHtml(sprint, tasks, epicOf, hasActive) {
    const points = sumTaskPoints(tasks).total;
    const state = sprint.state === 'active' ? 'active' : 'planned';
    const dates = sprintRange(sprint.start_date, sprint.end_date) || 'ยังไม่กำหนดวัน';
    const admin = isProjectAdmin();
    const id = Number(sprint.id);

    let actions = '';
    if (admin && state === 'active') {
        actions = '<button class="btn btn-sm" type="button" data-act="openCloseSprint" data-args="[' + id + ']">ปิดรอบ</button>';
    } else if (admin) {
        const disabled = hasActive ? ' disabled title="ปิดรอบที่กำลังทำก่อน"' : '';
        actions = '<button class="btn btn-sm" type="button" data-act="startSprint" data-args="[' + id + ']"' + disabled + '>เริ่มรอบ</button>'
            + '<button class="btn btn-sm btn-ghost" type="button" data-act="startSprintEdit" data-args="[' + id + ']"'
            + ' aria-label="แก้ชื่อ วัน และเป้าหมายของรอบ ' + escHtml(sprint.name) + '">✎</button>'
            + '<button class="btn btn-sm btn-ghost" type="button" data-act="deleteSprint" data-args="[' + id + ']"'
            + ' aria-label="ลบรอบ ' + escHtml(sprint.name) + '">ลบ</button>';
    }

    return '<section class="bl-block" data-sprint-id="' + id + '" data-state="' + state + '"'
        + ' aria-labelledby="blSprintTitle-' + id + '">'
        + '<div class="bl-head">'
        + '<h3 id="blSprintTitle-' + id + '">' + escHtml(sprint.name) + '</h3>'
        + '<span class="state">' + escHtml(SPRINT_STATE_LABEL[sprint.state] || sprint.state) + '</span>'
        + '<span class="sub">' + escHtml(dates) + ' · ' + tasks.length + ' งาน · ' + points + ' แต้ม'
        + (state === 'planned' && sprint.goal ? ' · เป้าหมาย: ' + escHtml(sprint.goal) : '') + '</span>'
        + '<span class="sp"></span>' + actions
        + '</div>'
        + (sprintEditId === id ? sprintEditFormHtml(sprint) : '')
        + backlogListHtml('blListSprint-' + id, tasks, epicOf)
        + '</section>';
}

/** บล็อกคลังงาน (เส้นประ) พร้อมช่องเพิ่มงานเร็วสำหรับสมาชิกขึ้นไป */
function backlogBlockHtml(tasks, epicOf) {
    return '<section class="bl-block backlog" data-sprint-id="" aria-labelledby="blBacklogTitle">'
        + '<div class="bl-head">'
        + '<h3 id="blBacklogTitle">คลังงาน</h3>'
        + '<span class="sub">' + tasks.length + ' งาน</span>'
        + '</div>'
        + backlogListHtml('blListBacklog', tasks, epicOf)
        + (isViewer() ? '' : '<div class="bl-add">'
            + '<label class="sr-only" for="backlogQuickAdd">เพิ่มงานเข้าคลังงาน</label>'
            + '<input type="text" class="form-control" id="backlogQuickAdd" autocomplete="off"'
            + ' placeholder="+ พิมพ์ชื่องานแล้วกด Enter (ใส่ ! นำหน้าเป็นบั๊ก)"'
            + ' data-act="handleBacklogQuickAddKey" data-args=\'["$event"]\' data-on="keydown">'
            + '</div>')
        + '</section>';
}

/** รายการงานในบล็อก หรือข้อความบอกว่าว่างเมื่อไม่มีงาน */
function backlogListHtml(listId, tasks, epicOf) {
    const rows = tasks.length
        ? tasks.map(task => backlogRowHtml(task, epicOf.get(Number(task.id)))).join('')
        : '<p class="bl-empty">ว่าง ลากงานมาวางที่นี่</p>';
    return '<div class="bl-list" id="' + escHtml(listId) + '">' + rows + '</div>';
}

/** แถวงานหนึ่งแถวในคลังงาน: ที่จับ ลาก ชนิดงาน เลขงาน ชื่องาน งานใหญ่ ผู้รับ และแต้ม */
function backlogRowHtml(task, epic) {
    const id = Number(task.id);
    const title = task.title || '';
    const key = taskKey(task);
    const done = isDoneTask(task);
    const grip = isViewer()
        ? '<span class="bl-grip is-hidden" aria-hidden="true"></span>'
        : '<span class="bl-grip" data-stop aria-hidden="true">⋮⋮</span>';
    const who = task.assignee_name
        ? '<span class="avatar-xs" aria-hidden="true" title="ผู้รับผิดชอบ: ' + escHtml(task.assignee_name) + '">'
            + escHtml(memberInitial(task.assignee_name)) + '</span>'
        : '<span aria-hidden="true"></span>';

    return '<div class="bl-row' + (done ? ' done' : '') + '" data-id="' + id + '"'
        + ' data-act="openEditTask" data-args="[' + id + ']">'
        + grip
        + kindIcon(task.kind || 'task')
        + (key ? '<span class="key">' + escHtml(key) + '</span>' : '<span></span>')
        + '<button type="button" class="bl-title" data-act="openEditTask" data-args="[' + id + ']"'
        + ' aria-label="เปิดงาน: ' + escHtml(title) + '">' + escHtml(title) + '</button>'
        + '<span class="bl-epic">' + (epic ? '<span class="epic-tag" title="' + escHtml(epic.title) + '">'
            + escHtml(epic.title) + '</span>' : '') + '</span>'
        + who
        + pointsPill(task.story_points)
        + '</div>';
}

/** ฟอร์มแก้รอบงานแบบ inline ในหัวบล็อก (ชื่อ เป้าหมาย วันเริ่ม วันจบ) */
function sprintEditFormHtml(sprint) {
    const id = Number(sprint.id);
    return '<form class="bl-edit" data-act="submitSprintEdit" data-args=\'["$event", ' + id + ']\' data-on="submit" novalidate>'
        + '<div class="form-group">'
        + '<label class="form-label" for="sprintEditName-' + id + '">ชื่อรอบงาน</label>'
        + '<input type="text" class="form-control" id="sprintEditName-' + id + '" maxlength="60" autocomplete="off"'
        + ' value="' + escHtml(sprint.name || '') + '">'
        + '</div>'
        + '<div class="form-group">'
        + '<label class="form-label" for="sprintEditGoal-' + id + '">เป้าหมาย</label>'
        + '<input type="text" class="form-control" id="sprintEditGoal-' + id + '" maxlength="255" autocomplete="off"'
        + ' placeholder="เป้าหมายของรอบนี้" value="' + escHtml(sprint.goal || '') + '">'
        + '</div>'
        + '<div class="bl-edit-dates">'
        + '<div class="form-group">'
        + '<label class="form-label" for="sprintEditStart-' + id + '">วันเริ่ม</label>'
        + '<input type="date" class="form-control" id="sprintEditStart-' + id + '" value="' + escHtml(sprint.start_date || '') + '">'
        + '</div>'
        + '<div class="form-group">'
        + '<label class="form-label" for="sprintEditEnd-' + id + '">วันจบ</label>'
        + '<input type="date" class="form-control" id="sprintEditEnd-' + id + '" value="' + escHtml(sprint.end_date || '') + '">'
        + '</div>'
        + '</div>'
        + '<p class="form-error" id="sprintEditError-' + id + '" role="alert" hidden></p>'
        + '<div class="bl-edit-actions">'
        + '<button type="submit" class="btn btn-sm btn-primary">บันทึก</button>'
        + '<button type="button" class="btn btn-sm" data-act="cancelSprintEdit">ยกเลิก</button>'
        + '</div>'
        + '</form>';
}

/** ผูก Sortable ให้ทุกบล็อกในคลังงานใช้กลุ่มเดียวกัน และปิดเมื่อกรองอยู่หรือเป็นผู้ดู */
function initBacklogSortables() {
    if (typeof Sortable === 'undefined') return;
    backlogSortables.forEach(instance => { try { instance.destroy(); } catch (_) { /* ถูกทำลายไปแล้ว */ } });
    backlogSortables = [];
    if (!isSprintMode() || isViewer() || hasTaskFilter()) return;

    document.querySelectorAll('#backlogBlocks .bl-list').forEach(list => {
        backlogSortables.push(Sortable.create(list, {
            group: 'backlog-tasks',
            animation: 160,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            draggable: '.bl-row',
            handle: '.bl-grip',
            delay: 120,
            delayOnTouchOnly: true,
            touchStartThreshold: 7,
            onEnd: saveBacklogOrder,
        }));
    });
}

/** บันทึกลำดับหลังลาก: ส่งรอบปลายทางและรหัสงานทั้งหมดในบล็อกนั้นตามลำดับ */
async function saveBacklogOrder(event) {
    const block = event.to.closest('.bl-block');
    if (!block) return;
    const raw = block.dataset.sprintId;
    const sprintId = raw ? Number(raw) : null;
    const ids = Array.from(event.to.querySelectorAll('.bl-row[data-id]'))
        .map(row => parseInt(row.dataset.id, 10));

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/plan', {
            method: 'POST',
            body: JSON.stringify({ sprint_id: sprintId, ids })
        });
        await selectProject(activeProjectId);
    } catch (err) {
        toast(err.message || 'ย้ายงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
        await selectProject(activeProjectId);
    }
}

/** สร้างรอบงานใหม่ทันที (ชื่อว่างให้เซิร์ฟเวอร์ตั้ง) แล้วเปิดฟอร์มแก้ของบล็อกนั้น */
async function createSprint() {
    if (!isSprintMode() || !isProjectAdmin()) return;
    const ends = projectSprints()
        .filter(sprint => ['planned', 'active'].includes(sprint.state) && sprint.end_date)
        .map(sprint => sprint.end_date)
        .sort();
    const start = ends.length ? addDaysIso(ends[ends.length - 1], 1) : serverToday();
    const end = addDaysIso(start, 13);

    try {
        const created = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/sprints', {
            method: 'POST',
            body: JSON.stringify({ start_date: start, end_date: end })
        });
        sprintEditId = Number(created.id);
        toast('สร้างรอบงานแล้ว');
        await selectProject(activeProjectId);
        showProjectTab('backlog');
        focusSprintEdit(sprintEditId);
    } catch (err) {
        toast(err.message || 'สร้างรอบงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/** เปิดฟอร์มแก้รอบงานของบล็อกที่เลือก */
function startSprintEdit(id) {
    if (!isProjectAdmin()) return;
    sprintEditId = Number(id);
    renderBacklog();
    focusSprintEdit(sprintEditId);
}

/** ปิดฟอร์มแก้รอบงานโดยไม่บันทึก */
function cancelSprintEdit() {
    sprintEditId = null;
    renderBacklog();
}

/** โฟกัสช่องชื่อของฟอร์มแก้รอบงานหลังวาด */
function focusSprintEdit(id) {
    const input = document.getElementById('sprintEditName-' + Number(id));
    if (input) input.focus();
}

/** บันทึกชื่อ เป้าหมาย และวันของรอบงานผ่าน PUT แล้วโหลดโปรเจคใหม่ */
async function submitSprintEdit(event, id) {
    event.preventDefault();
    const name = document.getElementById('sprintEditName-' + id).value.trim();
    const goal = document.getElementById('sprintEditGoal-' + id).value.trim();
    const startDate = document.getElementById('sprintEditStart-' + id).value;
    const endDate = document.getElementById('sprintEditEnd-' + id).value;

    try {
        await apiFetch(BASE_URL + '/api/projects/sprints/' + id, {
            method: 'PUT',
            body: JSON.stringify({ name, goal, start_date: startDate, end_date: endDate })
        });
        sprintEditId = null;
        toast('บันทึกรอบงานแล้ว');
        await selectProject(activeProjectId);
        showProjectTab('backlog');
    } catch (err) {
        formErrorLine('sprintEditError-' + id, err.message || 'บันทึกรอบงานไม่สำเร็จ');
    }
}

/** ลบรอบงานที่วางแผนไว้หลังยืนยัน (งานในรอบกลับไปคลังงาน) */
async function deleteSprint(id) {
    const sprint = sprintById(id);
    if (!sprint || !isProjectAdmin()) return;
    const count = sprintTaskList(id).length;
    if (!await confirmAction('งานในรอบนี้ ' + count + ' งานจะกลับไปคลังงาน', 'ลบรอบ',
        'ลบ "' + sprint.name + '"?')) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/sprints/' + id, { method: 'DELETE' });
        if (sprintEditId === Number(id)) sprintEditId = null;
        toast('ลบรอบงานแล้ว');
        await selectProject(activeProjectId);
        showProjectTab('backlog');
    } catch (err) {
        toast(err.message || 'ลบรอบงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/** เริ่มรอบงานที่วางแผนไว้หลังยืนยัน แล้วโหลดโปรเจคใหม่ */
async function startSprint(id) {
    const sprint = sprintById(id);
    if (!sprint || !isProjectAdmin()) return;
    const stats = sumTaskPoints(sprintTaskList(id));
    if (!await confirmAction('งาน ' + sprintTaskList(id).length + ' งาน · ' + stats.total
        + ' แต้ม จะย้ายเข้าบอร์ด และบอร์ดจะแสดงเฉพาะงานในรอบนี้',
        'เริ่มรอบ', 'เริ่มรอบ "' + sprint.name + '"?', 'primary')) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/sprints/' + id + '/start', { method: 'POST' });
        toast('เริ่มรอบแล้ว');
        await selectProject(activeProjectId);
        showProjectTab('backlog');
    } catch (err) {
        toast(err.message || 'เริ่มรอบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/** เปิดกล่องปิดรอบ พร้อมตัวเลขสรุป งานค้าง และตัวเลือกปลายทาง */
function openCloseSprint(sprintId) {
    if (!isProjectAdmin()) return;
    const sprint = sprintById(sprintId) || activeSprint();
    if (!sprint) return;

    const tasks = sprintTaskList(sprint.id);
    const done = tasks.filter(isDoneTask);
    const unfinished = tasks.filter(task => !isDoneTask(task));
    const donePoints = sumTaskPoints(done).total;
    const unfinishedPoints = sumTaskPoints(unfinished).total;

    document.getElementById('closeSprintTitle').textContent = 'ปิด' + sprint.name;
    document.getElementById('closeSprintSummary').innerHTML =
        '<div><b>' + donePoints + '</b>แต้มเสร็จ</div>'
        + '<div><b>' + done.length + '</b>งานเสร็จ</div>'
        + '<div><b>' + unfinished.length + '</b>งานยังไม่เสร็จ</div>';
    document.getElementById('closeSprintLeftHeading').textContent =
        'งานที่ยังไม่เสร็จ (' + unfinishedPoints + ' แต้ม)';
    document.getElementById('closeSprintLeftList').innerHTML = unfinished.length
        ? unfinished.map(task => '<li>' + kindIcon(task.kind || 'task')
            + (taskKey(task) ? '<span class="key">' + escHtml(taskKey(task)) + '</span>' : '')
            + '<span class="left-title">' + escHtml(task.title || '') + '</span>'
            + pointsPill(task.story_points) + '</li>').join('')
        : '<li class="proj-note">ไม่มีงานค้างในรอบนี้</li>';

    const planned = plannedSprints();
    let choices = '';
    let first = true;
    planned.forEach(target => {
        const targetPoints = sumTaskPoints(sprintTaskList(target.id)).total + unfinishedPoints;
        const starts = target.start_date ? ' เริ่ม ' + formatDate(target.start_date) : '';
        choices += '<label class="choice"><input type="radio" name="closeCarry" value="' + Number(target.id) + '"'
            + (first ? ' checked' : '') + '>'
            + '<span>' + escHtml(target.name) + ' (วางแผนอยู่' + escHtml(starts) + ')'
            + '<small>' + escHtml(target.name) + ' จะมี ' + targetPoints + ' แต้ม</small></span></label>';
        first = false;
    });
    if (!planned.length) {
        choices += '<label class="choice"><input type="radio" name="closeCarry" value="new"' + (first ? ' checked' : '') + '>'
            + '<span>สร้างรอบใหม่แล้วย้ายเข้าไป<small>ได้ชื่อ "รอบ" ถัดไปให้อัตโนมัติ</small></span></label>';
        first = false;
    }
    choices += '<label class="choice"><input type="radio" name="closeCarry" value="backlog"'
        + (first ? ' checked' : '') + '><span>คลังงาน<small>วางแผนใหม่ทีหลัง</small></span></label>';
    document.getElementById('closeSprintChoices').innerHTML = choices;

    formErrorLine('closeSprintError', '');
    closeSprintId = Number(sprint.id);
    openModal('closeSprintModal');
}

/** ปิดรอบงานตามปลายทางที่เลือก แล้วไปที่คลังงาน */
async function submitCloseSprint(event) {
    event.preventDefault();
    if (!closeSprintId) return;
    const selected = document.querySelector('#closeSprintModal input[name="closeCarry"]:checked');
    if (!selected) { formErrorLine('closeSprintError', 'เลือกที่ย้ายงานค้างก่อน'); return; }

    try {
        const result = await apiFetch(BASE_URL + '/api/projects/sprints/' + closeSprintId + '/close', {
            method: 'POST',
            body: JSON.stringify({ carry: selected.value })
        });
        closeSprintId = null;
        closeModal('closeSprintModal');
        toast('ปิดรอบแล้ว ย้ายงานค้าง ' + (Number(result.moved) || 0) + ' งาน');
        await selectProject(activeProjectId);
        showProjectTab('backlog');
    } catch (err) {
        formErrorLine('closeSprintError', err.message || 'ปิดรอบไม่สำเร็จ ลองอีกครั้ง');
    }
}

/** Enter ในช่องเพิ่มงานของคลังงานสร้างงานใหม่ */
function handleBacklogQuickAddKey(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        submitBacklogQuickAdd();
    }
}

/** สร้างงานในคลังงาน (นำหน้าด้วย ! เป็นบั๊ก) ลงคอลัมน์กลุ่ม todo แรก */
async function submitBacklogQuickAdd() {
    const input = document.getElementById('backlogQuickAdd');
    if (!input) return;
    let title = input.value.trim();
    let kind = 'task';
    if (title.startsWith('!')) {
        kind = 'bug';
        title = title.slice(1).trim();
    }
    if (!title) { input.focus(); return; }

    const column = projectColumns().find(item => item.grp === 'todo') || projectColumns()[0];
    if (!column) {
        toast('ยังไม่มีคอลัมน์ให้วางงาน', 'danger');
        return;
    }

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/tasks', {
            method: 'POST',
            body: JSON.stringify({
                title,
                kind,
                column_id: Number(column.id),
                priority: 'Medium',
                sprint_id: null
            })
        });
        toast('เพิ่มงานแล้ว');
        await selectProject(activeProjectId);
        showProjectTab('backlog');
        const again = document.getElementById('backlogQuickAdd');
        if (again) again.focus();
    } catch (err) {
        toast(err.message || 'เพิ่มงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/** เปิด/ปิด "ใช้รอบงาน" ของโปรเจคแล้วโหลดหน้าใหม่ */
async function saveUseSprints(checked) {
    if (!activeProjectId || !isProjectAdmin()) return;
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId, {
            method: 'PUT',
            body: JSON.stringify({ use_sprints: checked ? 1 : 0 })
        });
        toast(checked ? 'เปิดใช้รอบงานแล้ว' : 'ปิดใช้รอบงานแล้ว');
        await selectProject(activeProjectId);
        await refreshProjectList();
    } catch (err) {
        renderSoftwareSettings();
        toast(err.message || 'บันทึกการตั้งค่าไม่สำเร็จ', 'danger');
    }
}
