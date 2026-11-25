/**
 * projects-views.js — แถบตัวกรองงานและมุมมองรายการ/ปฏิทินของหน้าโปรเจค DayFlow
 * เก็บสถานะตัวกรอง/การเรียงใน sessionStorage และวาดทุกมุมมองจากข้อมูลที่อยู่ในหน่วยความจำ
 * เป็น classic script แชร์ global กับไฟล์อื่น ไม่มีการเรียก API และไม่ผูก event เอง
 */

const TASK_VIEW_DEFAULT = { q: '', who: '', grp: '', due: '', kind: '', epic: '', sort: 'due', dir: 1, group: 'column' };
const TASK_SORT_KEYS = ['title', 'column', 'who', 'due', 'priority'];
const TASK_PRIORITY_ORDER = { Critical: 0, High: 1, Medium: 2, Low: 3 };
const TASK_CAL_DOW = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

let taskView = { ...TASK_VIEW_DEFAULT };
let taskCalMonth = null;

/** สร้างสตริงวันที่ 'YYYY-MM-DD' จากปี ค.ศ. และเดือน 1..12 */
function isoDateStr(y, m, d) {
    return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
}

/** บวก/ลบจำนวนวันกับสตริง 'YYYY-MM-DD' ด้วย Date.UTC แล้วคืนสตริงรูปแบบเดิม */
function addDaysIso(dateStr, days) {
    const parts = dateStr.split('-').map(Number);
    const d = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] + days));
    return isoDateStr(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
}

/** คีย์ sessionStorage ของมุมมองงานของโปรเจคที่เปิดอยู่ */
function taskViewStorageKey() {
    return 'df-proj-view-' + activeProjectId;
}

/** บันทึก taskView ลง sessionStorage (เงียบเมื่อเขียนไม่ได้) */
function saveTaskView() {
    try {
        sessionStorage.setItem(taskViewStorageKey(), JSON.stringify(taskView));
    } catch (e) {
        /* เก็บไม่ได้ก็ใช้ค่าในหน่วยความจำต่อ */
    }
}

/** อ่าน taskView จาก sessionStorage แล้ว merge กับค่าเริ่มต้น คืนค่าที่ใช้ได้เสมอ */
function readTaskView() {
    let saved = null;
    try {
        const raw = sessionStorage.getItem(taskViewStorageKey());
        saved = raw ? JSON.parse(raw) : null;
    } catch (e) {
        saved = null;
    }
    const view = { ...TASK_VIEW_DEFAULT, ...(saved && typeof saved === 'object' ? saved : {}) };
    if (Number(view.dir) !== -1) view.dir = 1;
    view.cf = view.cf && typeof view.cf === 'object' && !Array.isArray(view.cf) ? { ...view.cf } : {};
    return view;
}

/** ตั้ง taskCalMonth เป็นเดือนของ serverToday() */
function taskCalResetMonth() {
    const parts = serverToday().split('-').map(Number);
    taskCalMonth = { y: parts[0], m: parts[1] - 1 };
}

/** ใส่ค่า taskView ลงช่องกรอก/เลือกในแถบตัวกรอง (ข้ามช่องที่ไม่มีในหน้า) */
function syncTaskFilterInputs() {
    const map = { taskFilterQ: 'q', taskFilterWho: 'who', taskFilterGrp: 'grp', taskFilterDue: 'due', taskFilterKind: 'kind', taskFilterEpic: 'epic', taskListGroup: 'group' };
    Object.keys(map).forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = taskView[map[id]];
    });
}

/** สร้าง option ของ #taskFilterWho จากสมาชิกโปรเจค และล้าง who ที่ไม่มีในรายการแล้ว */
function buildTaskViewWhoOptions() {
    const items = [{ value: '', label: 'ทุกคน' }];
    if (CURRENT_USER_ID) items.push({ value: 'me', label: 'งานของฉัน' });
    items.push({ value: 'none', label: 'ยังไม่มีผู้รับผิดชอบ' });
    (projectMembers || []).forEach(member => {
        const id = String(member.id);
        items.push({ value: id, label: String(member.display_name || member.username || ('ผู้ใช้ ' + id)) });
    });
    if (!items.some(item => item.value === taskView.who)) taskView.who = '';
    const sel = document.getElementById('taskFilterWho');
    if (sel) {
        sel.innerHTML = items.map(item => '<option value="' + escHtml(item.value) + '">' + escHtml(item.label) + '</option>').join('');
    }
}

/** สร้างตัวเลือกงานใหญ่ของโปรเจคซอฟต์แวร์ และล้างค่าที่ไม่มีในรายการแล้ว */
function buildTaskViewSoftwareFilters() {
    const software = isSoftwareProject();
    const kindSelect = document.getElementById('taskFilterKind');
    const epicSelect = document.getElementById('taskFilterEpic');
    if (kindSelect) kindSelect.hidden = !software;
    if (epicSelect) epicSelect.hidden = !software;
    if (!software) {
        taskView.kind = '';
        taskView.epic = '';
        return;
    }

    const epics = ((activeProjectData && activeProjectData.tasks) || []).filter(task => (task.kind || 'task') === 'epic');
    if (!epics.some(task => String(task.id) === String(taskView.epic))) taskView.epic = '';
    if (epicSelect) {
        epicSelect.innerHTML = '<option value="">ทุกงานใหญ่</option>' + epics.map(task =>
            '<option value="' + Number(task.id) + '">' + escHtml(taskKey(task) + ' ' + (task.title || '')) + '</option>').join('');
    }
}

/** คืนจำนวนตัวกรองที่เปิดใช้อยู่ (0 = ไม่ได้กรอง) */
function countActiveTaskFilters() {
    return (String(taskView.q || '').trim() ? 1 : 0)
        + (taskView.who ? 1 : 0)
        + (taskView.grp ? 1 : 0)
        + (taskView.due ? 1 : 0)
        + (taskView.kind ? 1 : 0)
        + (taskView.epic ? 1 : 0)
        + Object.values(taskView.cf || {}).filter(Boolean).length;
}

/**
 * โหลดมุมมองงานของโปรเจคที่เปิดอยู่: อ่าน sessionStorage ตั้งเดือนปฏิทิน
 * สร้างตัวเลือกผู้รับผิดชอบและงานใหญ่ เติมค่าในฟอร์ม แล้ววาดทุกมุมมอง
 */
function loadTaskView() {
    taskView = readTaskView();
    taskCalResetMonth();
    buildTaskViewWhoOptions();
    buildTaskViewSoftwareFilters();
    if (!isTaskSortKey(taskView.sort)) taskView.sort = 'due';
    buildTaskFieldFilters();
    syncTaskFilterInputs();
    renderTaskViews();
}

/** เปลี่ยนตัวกรองหนึ่งตัว (q/who/grp/due/kind/epic/group) บันทึกแล้ววาดใหม่ */
function setTaskFilter(key, value) {
    if (!['q', 'who', 'grp', 'due', 'kind', 'epic', 'group'].includes(key)) return;
    taskView[key] = value == null ? '' : String(value);
    saveTaskView();
    renderTaskViews();
}

/** ล้างตัวกรอง q/who/grp/due/kind/epic (คง sort/dir/group) แล้ววาดใหม่ */
function clearTaskFilters() {
    taskView.q = '';
    taskView.who = '';
    taskView.grp = '';
    taskView.due = '';
    taskView.kind = '';
    taskView.epic = '';
    taskView.cf = {};
    buildTaskViewSoftwareFilters();
    buildTaskFieldFilters();
    syncTaskFilterInputs();
    saveTaskView();
    renderTaskViews();
}

/** true เมื่อมีตัวกรองอย่างน้อยหนึ่งตัว */
function hasTaskFilter() {
    return countActiveTaskFilters() > 0;
}

/**
 * คืนงานที่ผ่านตัวกรองทั้งหมด ตามลำดับเดิมของ activeProjectData.tasks
 * โปรเจคซอฟต์แวร์ค้นด้วยเลขงานได้ เช่น df-49 กรองตามชนิดงานได้
 * และกรองงานใหญ่โดยนับงานลูกตรงและงานย่อยของเรื่องผู้ใช้ในงานใหญ่นั้นด้วย
 */
function filteredTasks() {
    const tasks = (activeProjectData && activeProjectData.tasks) || [];
    const q = String(taskView.q || '').trim().toLowerCase();
    const today = serverToday();
    const weekEnd = addDaysIso(today, 7);
    const software = isSoftwareProject();
    if (software) buildTaskIndex();
    const prefix = software ? String((activeProjectData.project.key_prefix || '')).toLowerCase() : '';
    const keyMatch = software ? q.match(/^([a-z]{2,6})-(\d+)$/) : null;
    const keyNumber = keyMatch && keyMatch[1] === prefix ? Number(keyMatch[2]) : null;
    return tasks.filter(task => {
        if (q) {
            if (keyNumber !== null) {
                if (Number(task.number) !== keyNumber) return false;
            } else {
                const title = String(task.title || '').toLowerCase();
                const category = String(task.category || '').toLowerCase();
                if (!title.includes(q) && !category.includes(q) && !taskTextFieldsInclude(task, q)) return false;
            }
        }
        if (!taskMatchesFieldFilters(task)) return false;
        if (software) {
            if (taskView.kind && (task.kind || 'task') !== taskView.kind) return false;
            if (taskView.epic) {
                const parentId = task.parent_id == null || task.parent_id === '' ? null : Number(task.parent_id);
                const parent = parentId === null ? null : softwareTaskIndex.byId.get(parentId) || null;
                const grandParentId = parent && parent.parent_id != null && parent.parent_id !== '' ? Number(parent.parent_id) : null;
                if (parentId !== Number(taskView.epic) && grandParentId !== Number(taskView.epic)) return false;
            }
        }
        if (taskView.who === 'me') {
            if (!CURRENT_USER_ID || Number(task.assignee_user_id) !== Number(CURRENT_USER_ID)) return false;
        } else if (taskView.who === 'none') {
            if (task.assignee_user_id || task.assignee_name) return false;
        } else if (taskView.who) {
            if (!task.assignee_user_id || Number(task.assignee_user_id) !== Number(taskView.who)) return false;
        }
        if (taskView.grp) {
            const column = columnById(task.column_id);
            if (!column || column.grp !== taskView.grp) return false;
        }
        if (taskView.due) {
            const due = task.due_date || '';
            if (taskView.due === 'none' && due) return false;
            if (taskView.due === 'over' && (!due || due >= today || isDoneTask(task))) return false;
            if (taskView.due === 'week' && (!due || due < today || due > weekEnd)) return false;
        }
        return true;
    });
}

/* ── ช่องข้อมูลเพิ่มเติมในตัวกรองและรายการ (ฟังก์ชันอ่านค่าอยู่ใน projects-fields.js) ── */
const FILTERABLE_FIELD_KINDS = ['select', 'person', 'check'];

/** คีย์เรียงที่ใช้ได้: คอลัมน์ตายตัว หรือ 'f:<id>' ของช่องที่ยังอยู่ */
function isTaskSortKey(key) {
    if (TASK_SORT_KEYS.includes(key)) return true;
    return String(key).startsWith('f:') && projectFields().some(field => String(field.id) === String(key).slice(2));
}

/** ตัวเลือกของตัวกรองหนึ่งช่อง [{value, label}] (ค่าว่าง = ทั้งหมด) */
function fieldFilterChoices(field) {
    const all = { value: '', label: field.name + ': ทั้งหมด' };
    if (field.kind === 'check') return [all, { value: 'yes', label: field.name + ': ใช่' }, { value: 'no', label: field.name + ': ไม่ใช่' }];
    const values = field.kind === 'person'
        ? (projectMembers || []).map(member => ({ value: String(member.id), label: member.display_name || member.username }))
        : (field.options || []).map(option => ({ value: option, label: option }));
    return [all, ...values];
}

/**
 * วาดตัวกรองของช่องแบบตัวเลือก/คน/ติ๊กลงใน #taskFieldFilters และทิ้งค่าที่จำไว้ซึ่งใช้ไม่ได้แล้ว
 * (ช่องถูกลบ ตัวเลือกถูกเอาออก สมาชิกออก) ผลข้างเคียง: แก้ taskView.cf
 */
function buildTaskFieldFilters() {
    const fields = projectFields().filter(field => FILTERABLE_FIELD_KINDS.includes(field.kind));
    const kept = {};
    const html = fields.map(field => {
        const choices = fieldFilterChoices(field);
        const current = (taskView.cf || {})[field.id] || '';
        if (current && choices.some(choice => choice.value === current)) kept[field.id] = current;
        return '<select class="form-control" aria-label="กรองตาม ' + escHtml(field.name) + '"'
            + ' data-act="setTaskFieldFilter" data-args=\'[' + Number(field.id) + ', "$value"]\' data-on="change">'
            + choices.map(choice => '<option value="' + escHtml(choice.value) + '"' + (choice.value === kept[field.id] ? ' selected' : '') + '>'
                + escHtml(choice.label) + '</option>').join('')
            + '</select>';
    }).join('');
    const box = document.getElementById('taskFieldFilters');
    if (box) box.innerHTML = html;
    taskView.cf = kept;
}

/** เปลี่ยนตัวกรองของช่องหนึ่งช่อง (ค่าว่าง = เลิกกรองช่องนั้น) บันทึกแล้ววาดใหม่ */
function setTaskFieldFilter(fieldId, value) {
    taskView.cf = { ...(taskView.cf || {}) };
    if (value) taskView.cf[fieldId] = String(value);
    else delete taskView.cf[fieldId];
    saveTaskView();
    renderTaskViews();
}

/** งานผ่านตัวกรองของทุกช่องที่ตั้งไว้หรือไม่ */
function taskMatchesFieldFilters(task) {
    const wanted = Object.entries(taskView.cf || {}).filter(([, value]) => value);
    if (!wanted.length) return true;
    const values = taskCustom(task);
    return wanted.every(([fieldId, value]) => {
        const field = projectFields().find(f => String(f.id) === fieldId);
        if (!field) return true;
        if (field.kind === 'check') return value === 'yes' ? values[fieldId] === true : values[fieldId] !== true;
        return String(values[fieldId] ?? '') === value;
    });
}

/** ช่องค้นหาเจอข้อความในช่องแบบข้อความของงานหรือไม่ (q เป็นตัวพิมพ์เล็กแล้ว) */
function taskTextFieldsInclude(task, q) {
    const values = taskCustom(task);
    return projectFields().some(field => field.kind === 'text' && String(values[field.id] || '').toLowerCase().includes(q));
}

/** เทียบค่าช่องของสองงานเพื่อเรียง: ไม่มีค่าอยู่ท้ายเสมอ ตัวเลขเทียบเป็นตัวเลข */
function compareFieldValues(fieldId, ta, tb, dir) {
    const field = projectFields().find(f => String(f.id) === String(fieldId));
    if (!field) return 0;
    const a = fieldSortValue(field, taskCustom(ta)[field.id]);
    const b = fieldSortValue(field, taskCustom(tb)[field.id]);
    if (a === null || b === null) return a === b ? 0 : (a === null ? 1 : -1);
    return (typeof a === 'number' && typeof b === 'number' ? a - b : String(a).localeCompare(String(b), 'th')) * dir;
}

/** จำนวนคอลัมน์ของตารางรายการ: 5 คอลัมน์ตายตัว + ช่องข้อมูล */
function taskListColspan() {
    return 5 + projectFields().length;
}

/** HTML ของช่องข้อมูลในแถวงาน หนึ่งเซลล์ต่อช่อง */
function taskFieldCellsHtml(task) {
    const values = taskCustom(task);
    return projectFields().map(field => '<td class="c-field">' + escHtml(fieldValueText(field, values[field.id]) || '—') + '</td>').join('');
}

/** วาดหัวคอลัมน์ของช่องข้อมูลต่อท้ายหัวตารางเดิม (ลบของเก่าก่อน) */
function renderTaskFieldHeaders() {
    const row = document.querySelector('#taskListTable thead tr');
    if (!row) return;
    row.querySelectorAll('th.c-field').forEach(th => th.remove());
    row.insertAdjacentHTML('beforeend', projectFields().map(field => '<th scope="col" class="c-field" data-sort="f:' + Number(field.id) + '">'
        + '<button class="th-sort" type="button" data-act="sortTaskList" data-args=\'["f:' + Number(field.id) + '"]\'>' + escHtml(field.name) + '</button></th>').join(''));
}

/** อัปเดตข้อความสรุปตัวกรองและซ่อนปุ่ม/ข้อความเมื่อไม่ได้กรอง */
function renderTaskFilterState() {
    const total = ((activeProjectData && activeProjectData.tasks) || []).length;
    const active = hasTaskFilter();
    const stateEl = document.getElementById('taskFilterState');
    const clearEl = document.getElementById('taskFilterClear');
    if (clearEl) clearEl.hidden = !active;
    if (stateEl) {
        stateEl.hidden = !active;
        stateEl.textContent = active
            ? 'กรองอยู่ ' + countActiveTaskFilters() + ' · แสดง ' + filteredTasks().length + ' จาก ' + total + ' งาน'
            : '';
    }
}

/** วาดบอร์ด รายการ ปฏิทิน และสถานะตัวกรองใหม่ทั้งหมดจากข้อมูลในหน่วยความจำ */
function renderTaskViews() {
    if (!activeProjectData) return;
    renderKanbanCards();
    initSortable();
    renderTaskList();
    renderTaskCalendar();
    renderTaskFilterState();
}

/** เทียบสองค่าแบบสตริงโดยให้ค่าว่างอยู่ท้ายเสมอไม่ว่า dir จะเป็นเท่าไร */
function compareEmptyLast(a, b, dir) {
    if (!a && !b) return 0;
    if (!a) return 1;
    if (!b) return -1;
    return String(a).localeCompare(String(b), 'th') * dir;
}

/** คืนงานที่เรียงตาม taskView.sort/dir (stable, ค่าที่ไม่มีอยู่ท้ายเสมอ) */
function sortedTasks(tasks) {
    const dir = taskView.dir === -1 ? -1 : 1;
    const key = taskView.sort;
    const columnOrder = new Map();
    projectColumns().forEach((column, index) => columnOrder.set(Number(column.id), index));
    return tasks
        .map((task, index) => ({ task, index }))
        .sort((a, b) => {
            const ta = a.task;
            const tb = b.task;
            let r = 0;
            if (key === 'title') r = String(ta.title || '').localeCompare(String(tb.title || ''), 'th') * dir;
            else if (key === 'column') {
                r = ((columnOrder.has(Number(ta.column_id)) ? columnOrder.get(Number(ta.column_id)) : 9999)
                    - (columnOrder.has(Number(tb.column_id)) ? columnOrder.get(Number(tb.column_id)) : 9999)) * dir;
            } else if (key === 'who') r = compareEmptyLast(ta.assignee_name, tb.assignee_name, dir);
            else if (key === 'due') r = compareEmptyLast(ta.due_date, tb.due_date, dir);
            else if (key.startsWith('f:')) r = compareFieldValues(key.slice(2), ta, tb, dir);
            else if (key === 'priority') {
                const pa = TASK_PRIORITY_ORDER[ta.priority] != null ? TASK_PRIORITY_ORDER[ta.priority] : 2;
                const pb = TASK_PRIORITY_ORDER[tb.priority] != null ? TASK_PRIORITY_ORDER[tb.priority] : 2;
                r = (pa - pb) * dir;
            }
            return r === 0 ? a.index - b.index : r;
        })
        .map(item => item.task);
}

/** แบ่งงานที่เรียงแล้วออกเป็นกลุ่มตาม taskView.group คืน [{label, grp, tasks}] */
function groupedTaskList(tasks) {
    const mode = taskView.group;
    if (mode === 'column') {
        return projectColumns()
            .map(column => ({
                label: column.name,
                grp: column.grp,
                tasks: tasks.filter(task => Number(task.column_id) === Number(column.id))
            }))
            .filter(group => group.tasks.length);
    }
    if (mode === 'who') {
        const byName = new Map();
        tasks.forEach(task => {
            const name = task.assignee_name || '';
            if (!byName.has(name)) byName.set(name, []);
            byName.get(name).push(task);
        });
        const groups = [...byName.keys()]
            .filter(name => name)
            .sort((a, b) => a.localeCompare(b, 'th'))
            .map(name => ({ label: name, grp: '', tasks: byName.get(name) }));
        if (byName.has('')) groups.push({ label: 'ยังไม่มีผู้รับผิดชอบ', grp: '', tasks: byName.get('') });
        return groups;
    }
    if (mode === 'priority') {
        const byPriority = new Map();
        tasks.forEach(task => {
            const key = TASK_PRIORITY_ORDER[task.priority] != null ? task.priority : 'Medium';
            if (!byPriority.has(key)) byPriority.set(key, []);
            byPriority.get(key).push(task);
        });
        return ['Critical', 'High', 'Medium', 'Low']
            .filter(key => byPriority.has(key))
            .map(key => ({ label: PRIORITY_LABEL[key] || key, grp: '', tasks: byPriority.get(key) }));
    }
    return [{ label: '', grp: '', tasks }];
}

/** HTML ของแถวงานหนึ่งแถวในตารางรายการ; โปรเจคซอฟต์แวร์มีไอคอนชนิดงานและเลขงานนำหน้าชื่อ */
function taskListRowHtml(task, today) {
    const id = Number(task.id);
    const column = columnById(task.column_id);
    const grp = column ? column.grp : '';
    const done = isDoneTask(task);
    const over = !!task.due_date && !done && task.due_date < today;
    const dueText = task.due_date
        ? escHtml(formatDate(task.due_date)) + (over ? ' · เลยกำหนด' : '')
        : '—';
    const priority = task.priority || 'Medium';
    const key = isSoftwareProject() ? taskKey(task) : '';
    const leading = isSoftwareProject()
        ? kindIcon(task.kind || 'task') + (key ? '<span class="key">' + escHtml(key) + '</span> ' : '')
        : '';
    return '<tr class="row" data-g="' + escHtml(grp) + '" data-act="openEditTask" data-args="[' + id + ']">'
        + '<td class="c-title"><button type="button" class="task-open" data-act="openEditTask" data-args="[' + id + ']">'
        + leading + escHtml(task.title || '') + '</button>'
        + (task.category ? '<span class="tag">' + escHtml(task.category) + '</span>' : '')
        + '</td>'
        + '<td class="c-col"><span class="gdot" aria-hidden="true"></span>' + escHtml(column ? column.name : '') + '</td>'
        + '<td class="c-who">' + (task.assignee_name ? escHtml(task.assignee_name) : '—') + '</td>'
        + '<td class="c-due' + (over ? ' over' : '') + '">' + dueText + '</td>'
        + '<td class="c-pri">' + escHtml(PRIORITY_LABEL[priority] || priority) + '</td>'
        + taskFieldCellsHtml(task)
        + '</tr>';
}

/** อัปเดต aria-sort ของหัวตารางให้ตรงกับคีย์ที่เรียงอยู่ */
function updateTaskListAriaSort() {
    document.querySelectorAll('#taskListTable thead th[data-sort]').forEach(th => {
        if (th.getAttribute('data-sort') === taskView.sort) {
            th.setAttribute('aria-sort', taskView.dir === -1 ? 'descending' : 'ascending');
        } else {
            th.removeAttribute('aria-sort');
        }
    });
}

/** กดหัวคอลัมน์เพื่อเรียง: คีย์เดิมสลับทิศ คีย์ใหม่เริ่มจากน้อยไปมาก */
function sortTaskList(key) {
    if (!isTaskSortKey(key)) return;
    if (taskView.sort === key) {
        taskView.dir = taskView.dir === 1 ? -1 : 1;
    } else {
        taskView.sort = key;
        taskView.dir = 1;
    }
    saveTaskView();
    renderTaskList();
}

/** วาดตารางมุมมองรายการ (จัดกลุ่มตาม taskView.group) และปรับ aria-sort */
function renderTaskList() {
    if (!activeProjectData) return;
    renderTaskFieldHeaders();
    updateTaskListAriaSort();
    const tbody = document.getElementById('taskListBody');
    if (!tbody) return;
    const all = activeProjectData.tasks || [];
    const tasks = filteredTasks();
    if (!all.length) {
        tbody.innerHTML = '<tr><td colspan="' + taskListColspan() + '" class="kanban-empty">ยังไม่มีงานในโปรเจคนี้</td></tr>';
        return;
    }
    if (!tasks.length) {
        tbody.innerHTML = '<tr><td colspan="' + taskListColspan() + '" class="kanban-empty">ไม่มีงานที่ตรงกับตัวกรอง '
            + '<button type="button" class="btn btn-link btn-sm" data-act="clearTaskFilters">ล้างตัวกรอง</button></td></tr>';
        return;
    }
    const today = serverToday();
    tbody.innerHTML = groupedTaskList(sortedTasks(tasks)).map(group => {
        const header = group.label
            ? '<tr class="grp-row"' + (group.grp ? ' data-g="' + escHtml(group.grp) + '"' : '') + '>'
                + '<th colspan="' + taskListColspan() + '" scope="colgroup"><span class="gdot" aria-hidden="true"></span>'
                + escHtml(group.label) + ' <span class="grp-n">' + group.tasks.length + '</span></th></tr>'
            : '';
        return header + group.tasks.map(task => taskListRowHtml(task, today)).join('');
    }).join('');
}

/** ปุ่มชิปงานหนึ่งรายการสำหรับปฏิทิน/รายการตามวัน (agenda) */
function taskCalChipHtml(task) {
    const column = columnById(task.column_id);
    return '<button type="button" class="cal-chip' + (isDoneTask(task) ? ' done' : '') + '"'
        + ' data-g="' + escHtml(column ? column.grp : '') + '"'
        + ' data-act="openEditTask" data-args="[' + Number(task.id) + ']">'
        + escHtml(task.title || '') + '</button>';
}

/** ป้ายวันที่ในรายการตามวัน (agenda) เช่น '5 พ.ย.' และต่อท้าย ' (วันนี้)' เมื่อเป็นวันนี้ */
function agendaDayLabel(dateStr, today) {
    const text = formatDate(dateStr).replace(/\s*\d{4}\s*$/, '');
    return escHtml(text) + (dateStr === today ? ' (วันนี้)' : '');
}

/** วาดปฏิทินเดือนของ taskCalMonth พร้อมรายการตามวัน (agenda) บนมือถือและรายการงานที่ยังไม่ลงวัน */
function renderTaskCalendar() {
    if (!activeProjectData) return;
    if (!taskCalMonth) taskCalResetMonth();
    const today = serverToday();
    const tasks = filteredTasks();
    const year = taskCalMonth.y;
    const month = taskCalMonth.m;
    const monthPrefix = isoDateStr(year, month + 1, 1).slice(0, 7);

    const titleEl = document.getElementById('taskCalTitle');
    if (titleEl) titleEl.textContent = THAI_MONTHS[month] + ' ' + (year + 543);

    const byDate = new Map();
    tasks.forEach(task => {
        if (!task.due_date) return;
        if (!byDate.has(task.due_date)) byDate.set(task.due_date, []);
        byDate.get(task.due_date).push(task);
    });

    const gridEl = document.getElementById('taskCalGrid');
    if (gridEl) {
        const offset = new Date(Date.UTC(year, month, 1)).getUTCDay();
        const daysInMonth = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
        const rows = Math.max(5, Math.ceil((offset + daysInMonth) / 7));
        let html = TASK_CAL_DOW.map(label => '<div class="dow">' + label + '</div>').join('');
        for (let i = 0; i < rows * 7; i++) {
            const cellDate = new Date(Date.UTC(year, month, i - offset + 1));
            const cy = cellDate.getUTCFullYear();
            const cm = cellDate.getUTCMonth();
            const cd = cellDate.getUTCDate();
            const key = isoDateStr(cy, cm + 1, cd);
            const other = cm !== month || cy !== year;
            const isToday = key === today;
            const chips = other ? '' : (byDate.get(key) || []).map(taskCalChipHtml).join('');
            html += '<div class="cal-cell' + (other ? ' other' : '') + (isToday ? ' today' : '') + '"'
                + (isToday ? ' aria-current="date"' : '') + '>'
                + '<span class="d">' + cd + '</span>' + chips + '</div>';
        }
        gridEl.innerHTML = html;
    }

    const agendaEl = document.getElementById('taskCalAgenda');
    if (agendaEl) {
        const days = [...byDate.keys()].filter(key => key.slice(0, 7) === monthPrefix).sort();
        agendaEl.innerHTML = days.length
            ? days.map(key => '<h4>' + agendaDayLabel(key, today) + '</h4>' + byDate.get(key).map(taskCalChipHtml).join('')).join('')
            : '<p class="proj-note">เดือนนี้ไม่มีงานที่มีวันส่ง</p>';
    }

    const unscheduledEl = document.getElementById('taskCalUnscheduled');
    if (unscheduledEl) {
        const unscheduled = tasks.filter(task => !task.due_date);
        unscheduledEl.innerHTML = '<h4>ยังไม่ลงวัน (' + unscheduled.length + ')</h4>'
            + unscheduled.map(taskCalChipHtml).join('')
            + '<p class="proj-note">ใส่วันครบกำหนดในรายละเอียดงานเพื่อให้ขึ้นในปฏิทิน</p>';
    }
}

/** เลื่อนเดือนปฏิทิน ±1 เดือน (ข้ามปีถูกต้อง) แล้ววาดใหม่ */
function navTaskCalendar(delta) {
    if (!taskCalMonth) taskCalResetMonth();
    const next = new Date(Date.UTC(taskCalMonth.y, taskCalMonth.m + Number(delta), 1));
    taskCalMonth = { y: next.getUTCFullYear(), m: next.getUTCMonth() };
    renderTaskCalendar();
}

/** กลับไปเดือนของวันนี้ (ตามเซิร์ฟเวอร์) แล้ววาดปฏิทินใหม่ */
function taskCalendarToday() {
    taskCalResetMonth();
    renderTaskCalendar();
}
