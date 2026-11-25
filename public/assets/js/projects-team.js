/* =====================================================
   projects-team.js — the team and the project chat
   Loaded after projects.js on the projects page; shares its state.
===================================================== */

/** "วันนี้ 14:05 น." or "2 ต.ค. 14:05 น." — short, for a chat line or an activity. */
function chatTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(/-/g, '/'));
    const time = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    if (d.toDateString() === new Date().toDateString()) return 'วันนี้ ' + time + ' น.';
    const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + time + ' น.';
}

/* ── Chat ── */
async function fetchChatMessages() {
    if (!activeProjectId) return;
    const status = document.getElementById('chatStatusText');
    try {
        const data = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/chat');
        status.textContent = 'ต่อกันอยู่';
        const list = document.getElementById('chatMessagesList');

        // Stay at the bottom only if the reader was already there.
        const atBottom = list.scrollHeight - list.clientHeight <= list.scrollTop + 60;

        const messages = data.messages || [];
        list.innerHTML = messages.length
            ? messages.map(msg => {
                const own = CURRENT_USER_ID > 0
                    ? CURRENT_USER_ID === parseInt(msg.user_id, 10)
                    : (!!CURRENT_GUEST_NAME && !msg.user_id && msg.guest_name === CURRENT_GUEST_NAME);
                return '<div class="chat-msg-item ' + (own ? 'chat-msg-own' : 'chat-msg-other') + '">'
                    + '<div class="chat-msg-meta"><span class="chat-msg-sender">' + escHtml(msg.display_name || msg.username) + '</span>'
                    + '<span class="chat-msg-time">' + escHtml(chatTime(msg.created_at)) + '</span></div>'
                    + '<div class="chat-msg-bubble">' + escHtml(msg.message) + '</div></div>';
            }).join('')
            : '<p class="proj-note">ยังไม่มีข้อความ เริ่มคุยกับทีมได้เลย</p>';

        if (atBottom || list.dataset.firstLoad === undefined) {
            list.scrollTop = list.scrollHeight;
            list.dataset.firstLoad = 'done';
        }
    } catch {
        status.textContent = 'ต่อไม่ได้ กำลังลองใหม่';
    }
}

async function sendChatMessage() {
    if (!activeProjectId) return;
    const input = document.getElementById('chatMessageInput');
    const message = input.value.trim();
    if (!message) return;

    input.value = '';
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/chat', { method: 'POST', body: JSON.stringify({ message }) });
        await fetchChatMessages();
    } catch {
        input.value = message;            // not sent: give the text back
        toast('ส่งข้อความไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/* ── The tabs and the settings sub-navigation ── */
let projectSettingsTab = 'board';       // remembered for this page load only
let projectSettingsSection = 'team';

/** บอกว่าผู้เรียกเป็นเจ้าของหรือผู้ดูแลของโปรเจคที่เปิดอยู่หรือไม่ */
function isProjectAdmin() {
    const project = activeProjectData && activeProjectData.project;
    return !!project && (!!project.is_owner || project.user_role === 'Admin');
}

const PROJECT_TABS = ['board', 'list', 'calendar', 'overview', 'settings'];
const FILTERED_TABS = ['board', 'list', 'calendar'];   // the tabs that show tasks, so the filter bar shows with them

/**
 * สลับแท็บของโปรเจค (บอร์ด รายการ ปฏิทิน ภาพรวม ตั้งค่า) และจำแท็บไว้จนกว่าจะโหลดหน้าใหม่
 * รับชื่อแท็บ ชื่อที่ไม่รู้จักหรือแท็บที่หน้านี้ไม่มี (แขกไม่มี "ตั้งค่า") กลับไปบอร์ด
 * ไม่คืนค่า แก้ aria-selected ซ่อน/แสดงแผง และแสดงแถบตัวกรองเฉพาะแท็บที่มีงานให้กรอง
 */
function showProjectTab(name) {
    const tabId = key => 'tab' + key.charAt(0).toUpperCase() + key.slice(1);
    projectSettingsTab = PROJECT_TABS.includes(name) && document.getElementById(tabId(name)) ? name : 'board';
    PROJECT_TABS.forEach(key => {
        const tab = document.getElementById(tabId(key));
        if (tab) tab.setAttribute('aria-selected', String(key === projectSettingsTab));
        show(key + 'Panel', key === projectSettingsTab);
    });
    show('taskFilters', FILTERED_TABS.includes(projectSettingsTab));
}

/** วาดแท็บที่จำไว้กลับมาหลังโหลดโปรเจค (ไม่คืนค่า) */
function applyProjectTab() {
    showProjectTab(projectSettingsTab);
}

const SETTINGS_SECTIONS = ['team', 'columns', 'fields', 'type'];

/**
 * สลับหัวข้อย่อยในแท็บตั้งค่า: ทีมและสิทธิ์ / คอลัมน์ / ช่องข้อมูล / ประเภท
 * รับชื่อหัวข้อ ไม่คืนค่า แก้ aria-current และซ่อน/แสดงแผง
 */
function showSettingsSection(name) {
    projectSettingsSection = SETTINGS_SECTIONS.includes(name) ? name : 'team';
    SETTINGS_SECTIONS.forEach(key => {
        const button = document.getElementById('settingsNav' + key.charAt(0).toUpperCase() + key.slice(1));
        if (button) button.setAttribute('aria-current', key === projectSettingsSection ? 'page' : 'false');
        show('settings' + key.charAt(0).toUpperCase() + key.slice(1), key === projectSettingsSection);
    });
}

/* ── Members ── */
const MEMBER_ROLE_LABEL = { Owner: 'เจ้าของ', Admin: 'ผู้ดูแล', Editor: 'สมาชิก', Viewer: 'ดูอย่างเดียว' };

/** ชื่อบทบาทของผู้ใช้ในโปรเจค: Owner | Admin | Editor | Viewer */
function callerRole() {
    const project = activeProjectData && activeProjectData.project;
    if (!project) return 'Viewer';
    return project.is_owner ? 'Owner' : (project.user_role || 'Viewer');
}

/** นับงานที่มอบให้สมาชิกคนนี้จากงานที่โหลดอยู่ */
function memberTaskCount(userId) {
    const tasks = (activeProjectData && activeProjectData.tasks) || [];
    return tasks.filter(t => Number(t.assignee_user_id) === Number(userId)).length;
}

/** อักษรแรกของชื่อสำหรับรูปโปรไฟล์กลม */
function memberInitial(name) {
    return Array.from(String(name || '?').trim())[0] || '?';
}

/**
 * โหลดรายชื่อสมาชิกของโปรเจคที่เปิดอยู่
 * รับว่าต้องวาดแผงทีมหรือไม่ คืนค่า Promise ไม่มีค่า
 */
async function loadProjectMembers(shouldRender = true) {
    if (!activeProjectId) return;
    try {
        const data = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/members');
        projectMembers = data.members || [];
        if (shouldRender) renderTeamPanel();
    } catch {
        toast('โหลดรายชื่อสมาชิกไม่สำเร็จ', 'danger');
    }
}

/** วาดแผง "ทีมและสิทธิ์": แถวสมาชิก ฟอร์มเชิญ ลิงก์แชร์ และตารางสิทธิ์ */
function renderTeamPanel() {
    const project = activeProjectData && activeProjectData.project;
    if (!project) return;

    const caller = callerRole();
    const owner = caller === 'Owner';
    const admin = owner || caller === 'Admin';

    const list = document.getElementById('projectMembersList');
    const solo = projectMembers.length <= 1;
    list.hidden = solo;
    list.innerHTML = solo ? '' : projectMembers.map(memberRow).join('');
    show('teamSoloEmpty', solo);

    const form = document.getElementById('inviteMemberForm');
    form.hidden = !admin;
    show('btnFocusInvite', admin);
    if (admin) {
        document.getElementById('inviteRoleSelect').innerHTML = (owner ? '<option value="Admin">ผู้ดูแล</option>' : '')
            + '<option value="Editor">สมาชิก</option><option value="Viewer">ดูอย่างเดียว</option>';
        formErrorLine('inviteError', '');
    }

    show('publicShareSection', admin);
    if (admin) loadProjectShareSettings();

    const column = caller.toLowerCase();
    document.querySelectorAll('.perm th[data-role-col]').forEach(th => {
        th.classList.toggle('mine', th.dataset.roleCol === column);
    });
}

/** สร้างแถวสมาชิกหนึ่งแถว: รูปย่อ ชื่อ จำนวนงาน ตัวเลือกบทบาท และปุ่มเอาออก */
function memberRow(member) {
    const caller = callerRole();
    const owner = caller === 'Owner';
    const name = member.display_name || member.username;
    const safeName = escHtml(name);
    const isMe = CURRENT_USER_ID === parseInt(member.id, 10);
    const isOwnerRow = member.role === 'Owner';
    const count = memberTaskCount(member.id);

    // What the caller may do to this row, worked out here rather than in CSS.
    let roles = null;
    if (!isOwnerRow && owner) roles = ['Admin', 'Editor', 'Viewer'];
    else if (!isOwnerRow && caller === 'Admin' && ['Editor', 'Viewer'].includes(member.role)) roles = ['Editor', 'Viewer'];

    let roleControl;
    if (roles) {
        roleControl = '<select class="form-control member-role" aria-label="บทบาทของ ' + safeName + '"'
            + ' data-act="changeMemberRole" data-args="[' + member.id + ', &quot;$el&quot;]" data-on="change" data-original="' + escHtml(member.role) + '">'
            + roles.map(role => '<option value="' + role + '"' + (role === member.role ? ' selected' : '') + '>' + MEMBER_ROLE_LABEL[role] + '</option>').join('')
            + '</select>';
    } else {
        roleControl = '<span class="role-fixed">' + escHtml(MEMBER_ROLE_LABEL[member.role] || member.role) + '</span>';
    }

    let action = '';
    if (owner && !isOwnerRow) {
        action = '<button class="btn btn-sm btn-ghost" type="button" data-act="removeMember" data-args="[' + member.id + ']">เอาออก</button>';
    } else if (!owner && !isOwnerRow && caller === 'Admin' && !isMe && ['Editor', 'Viewer'].includes(member.role)) {
        action = '<button class="btn btn-sm btn-ghost" type="button" data-act="removeMember" data-args="[' + member.id + ']">เอาออก</button>';
    } else if (isMe && !isOwnerRow) {
        action = '<button class="btn btn-sm btn-ghost" type="button" data-act="removeMember" data-args="[' + member.id + ']">ออกจากโปรเจค</button>';
    }

    return '<div class="member">'
        + '<span class="avatar" aria-hidden="true">' + escHtml(memberInitial(name)) + '</span>'
        + '<span class="member-name">' + safeName + (isMe ? ' (คุณ)' : '')
        + '<span class="sub">รับผิดชอบ ' + count + ' งาน</span></span>'
        + roleControl + action
        + '</div>';
}

/** เลื่อนไปที่ช่องเชิญคนเมื่อกด "ชวนคนเข้าทีม" */
function focusInviteForm() {
    const input = document.getElementById('inviteSearchInput');
    const form = document.getElementById('inviteMemberForm');
    if (!input || form.hidden) return;
    form.scrollIntoView({ block: 'center', behavior: 'smooth' });
    input.focus();
}

/**
 * เปลี่ยนบทบาทของสมาชิกด้วย PUT แล้วโหลดใหม่
 * ถ้าพลาดให้คืนค่าที่เลือกไว้เดิมแล้วบอกข้อความของเซิร์ฟเวอร์
 */
async function changeMemberRole(memberId, select) {
    if (!activeProjectId) return;
    const role = select.value;
    const original = select.dataset.original || '';
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/members/' + memberId, {
            method: 'PUT',
            body: JSON.stringify({ role })
        });
        toast('เปลี่ยนสิทธิ์แล้ว');
        await loadProjectMembers();
        await selectProject(activeProjectId);
    } catch (err) {
        select.value = original;
        toast(err.message || 'เปลี่ยนสิทธิ์ไม่สำเร็จ', 'danger');
    }
}

/** ส่งคำเชิญสมาชิกใหม่ด้วยชื่อผู้ใช้หรืออีเมลและบทบาทที่เลือก */
async function submitInviteMember(event) {
    event.preventDefault();
    if (!activeProjectId) return;
    const input = document.getElementById('inviteSearchInput');
    const target = input.value.trim();
    if (!target) { formErrorLine('inviteError', 'ใส่ชื่อผู้ใช้หรืออีเมลของคนที่จะเชิญ'); input.focus(); return; }

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/members', {
            method: 'POST',
            body: JSON.stringify({ email_or_username: target, role: document.getElementById('inviteRoleSelect').value })
        });
        toast('เชิญเข้าทีมแล้ว');
        input.value = '';
        await loadProjectMembers();
        await selectProject(activeProjectId);
    } catch (err) {
        formErrorLine('inviteError', err.message || 'เชิญไม่สำเร็จ ลองอีกครั้ง');
    }
}

/**
 * เอาสมาชิกออก (หรือออกเอง) พร้อมกล่องยืนยันตาม EDGE-P3
 * คนที่ออกเองจะกลับไปยังรายการโปรเจค
 */
async function removeMember(userId) {
    if (!activeProjectId) return;
    const isMe = CURRENT_USER_ID === userId;
    const member = projectMembers.find(m => Number(m.id) === Number(userId));
    const name = member ? (member.display_name || member.username) : '';

    if (isMe) {
        if (!await confirmAction('คุณจะไม่เห็นโปรเจคนี้อีก จนกว่าเจ้าของจะเชิญกลับ', 'ออกจากโปรเจค', 'ออกจากโปรเจคนี้?')) return;
    } else {
        const count = memberTaskCount(userId);
        const message = count > 0
            ? name + ' รับผิดชอบอยู่ ' + count + ' งาน งานยังอยู่ครบ แต่จะไม่มีผู้รับผิดชอบ'
            : name + ' จะไม่เห็นโปรเจคนี้อีก';
        if (!await confirmAction(message, 'เอา' + name + 'ออก', 'เอา ' + name + ' ออกจากโปรเจค')) return;
    }

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/members/' + userId, { method: 'DELETE' });
        toast(isMe ? 'ออกจากโปรเจคแล้ว' : 'เอาออกจากทีมแล้ว');
        if (isMe) {
            activeProjectId = null;
            await loadProjects();
        } else {
            await loadProjectMembers();
            await selectProject(activeProjectId);
        }
    } catch (err) {
        toast(err.message || 'ทำไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/* ── Columns (variant A: a list per group in the settings tab) ── */
const COLUMN_GROUPS = ['todo', 'doing', 'done'];
let columnSortables = [];
let columnPendingDelete = null;

/** วาดแผงคอลัมน์ทั้งหมด หรือบอกว่าผู้เรียกตั้งไม่ได้ */
function renderColumnsSettings() {
    const editor = document.getElementById('columnsEditor');
    const denied = document.getElementById('columnsDenied');
    if (!editor) return;

    if (!isProjectAdmin()) {
        editor.innerHTML = '';
        editor.hidden = true;
        show('columnsDenied', true);
        return;
    }
    denied.hidden = true;
    editor.hidden = false;
    editor.innerHTML = '<p class="proj-note">ลาก ⠿ เพื่อเรียง แต่ละกลุ่มต้องมีอย่างน้อย 1 คอลัมน์ '
        + 'ตัวเลขความคืบหน้านับงานในกลุ่ม "เสร็จ"</p>'
        + COLUMN_GROUPS.map(columnGroup).join('');
    initColumnSortables();
}

/** วาดกลุ่มคอลัมน์หนึ่งกลุ่ม: ชื่อกลุ่ม จำนวนคอลัมน์ แถว และปุ่มเพิ่ม */
function columnGroup(grp) {
    const columns = projectColumns().filter(c => c.grp === grp);
    return '<div class="col-group" data-g="' + grp + '">'
        + '<h3><span class="grp">' + escHtml(GROUP_LABEL[grp]) + '</span><small>' + columns.length + ' คอลัมน์</small></h3>'
        + '<div class="col-lines" id="colLines-' + grp + '">' + columns.map(columnLine).join('') + '</div>'
        + '<div class="col-add">'
        + '<button class="btn btn-link btn-sm" type="button" id="colAddButton-' + grp + '" data-act="openAddColumnForm" data-args=\'["' + grp + '"]\'>เพิ่มคอลัมน์ในกลุ่มนี้</button>'
        + '<div class="col-add-form" id="colAdd-' + grp + '" hidden>'
        + '<label class="sr-only" for="colAddInput-' + grp + '">ชื่อคอลัมน์ใหม่</label>'
        + '<input type="text" class="form-control" id="colAddInput-' + grp + '" maxlength="60" autocomplete="off" placeholder="ชื่อคอลัมน์"'
        + ' data-act="handleAddColumnKey" data-args=\'["' + grp + '", "$event"]\' data-on="keydown">'
        + '<button class="btn btn-sm btn-primary" type="button" data-act="submitAddColumn" data-args=\'["' + grp + '"]\'>เพิ่ม</button>'
        + '<button class="btn btn-sm" type="button" data-act="closeAddColumnForm" data-args=\'["' + grp + '"]\'>ยกเลิก</button>'
        + '</div></div></div>';
}

/** วาดแถวคอลัมน์หนึ่งแถว: ที่ลาก ช่องชื่อ จำนวนงาน และปุ่มลบ */
function columnLine(column) {
    const columns = projectColumns().filter(c => c.grp === column.grp);
    const tasks = (activeProjectData && activeProjectData.tasks) || [];
    const count = tasks.filter(t => Number(t.column_id) === Number(column.id)).length;
    const name = escHtml(column.name);
    const last = columns.length === 1;

    const remove = last
        ? '<button class="btn btn-sm btn-ghost" type="button" disabled>ลบ</button>'
        : '<button class="btn btn-sm btn-ghost" type="button" data-act="deleteColumn" data-args="[' + Number(column.id) + ']">ลบ</button>';

    return '<div class="col-line" data-column-id="' + Number(column.id) + '">'
        + '<span class="drag-handle" aria-hidden="true">⠿</span>'
        + '<input class="form-control" value="' + name + '" aria-label="ชื่อคอลัมน์ ' + name + '" maxlength="60" autocomplete="off"'
        + ' data-act="renameColumn" data-args="[' + Number(column.id) + ', &quot;$value&quot;, &quot;$el&quot;]" data-on="change" data-original="' + name + '">'
        + '<span class="n">' + count + ' งาน</span>'
        + remove
        + (last ? '<p class="why">คอลัมน์เดียวของกลุ่ม "' + escHtml(GROUP_LABEL[column.grp]) + '" ต้องมีไว้ ลบไม่ได้</p>' : '')
        + '</div>';
}

/** ผูก Sortable ให้เรียงคอลัมน์ได้เฉพาะภายในกลุ่มของมัน */
function initColumnSortables() {
    if (typeof Sortable === 'undefined') return;
    columnSortables.forEach(instance => { try { instance.destroy(); } catch (_) { /* already gone */ } });
    columnSortables = [];
    if (!isProjectAdmin()) return;

    COLUMN_GROUPS.forEach(grp => {
        const el = document.getElementById('colLines-' + grp);
        if (!el) return;
        columnSortables.push(Sortable.create(el, {
            group: 'project-cols-' + grp,      // a different group name per column group
            animation: 160,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            onEnd: () => saveColumnOrder(grp),
        }));
    });
}

/** โหลดบอร์ดและรายการโปรเจคใหม่หลังแก้คอลัมน์สำเร็จ */
async function afterColumnsChange() {
    await selectProject(activeProjectId);
    await refreshProjectList();
}

/** ส่งลำดับใหม่ของคอลัมน์ในกลุ่มเดียว แล้วโหลดใหม่ */
async function saveColumnOrder(grp) {
    const ids = Array.from(document.querySelectorAll('#colLines-' + grp + ' [data-column-id]'))
        .map(el => parseInt(el.dataset.columnId, 10));
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/columns/reorder', {
            method: 'POST',
            body: JSON.stringify({ grp, ids })
        });
        await afterColumnsChange();
    } catch (err) {
        toast(err.message || 'บันทึกลำดับคอลัมน์ไม่สำเร็จ', 'danger');
        await selectProject(activeProjectId);
    }
}

/**
 * เปลี่ยนชื่อคอลัมน์เมื่อออกจากช่อง
 * ชื่อว่างหรือซ้ำจะได้ 422 แล้วคืนชื่อเดิมพร้อมข้อความของเซิร์ฟเวอร์
 */
async function renameColumn(columnId, value, input) {
    const name = value.trim();
    const original = input.dataset.original || '';
    if (name === original) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/columns/' + columnId, {
            method: 'PUT',
            body: JSON.stringify({ name })
        });
        toast('เปลี่ยนชื่อคอลัมน์แล้ว');
        await afterColumnsChange();
    } catch (err) {
        input.value = original;
        toast(err.message || 'เปลี่ยนชื่อคอลัมน์ไม่สำเร็จ', 'danger');
    }
}

/** เปิดช่องเพิ่มคอลัมน์ของกลุ่ม แล้วโฟกัสช่องชื่อ */
function openAddColumnForm(grp) {
    const form = document.getElementById('colAdd-' + grp);
    const input = document.getElementById('colAddInput-' + grp);
    show('colAddButton-' + grp, false);
    if (!form || !input) return;
    form.hidden = false;
    input.value = '';
    input.focus();
}

/** ปิดช่องเพิ่มคอลัมน์ของกลุ่ม */
function closeAddColumnForm(grp) {
    const form = document.getElementById('colAdd-' + grp);
    if (form) form.hidden = true;
    show('colAddButton-' + grp, true);
}

/** Enter เพิ่มคอลัมน์, Escape ปิดช่อง */
function handleAddColumnKey(grp, event) {
    if (event.key === 'Enter') { event.preventDefault(); submitAddColumn(grp); }
    else if (event.key === 'Escape') closeAddColumnForm(grp);
}

/** สร้างคอลัมน์ใหม่ต่อท้ายกลุ่มด้วย POST แล้วโหลดใหม่ */
async function submitAddColumn(grp) {
    const input = document.getElementById('colAddInput-' + grp);
    if (!input) return;
    const name = input.value.trim();
    if (!name) { input.focus(); return; }

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/columns', {
            method: 'POST',
            body: JSON.stringify({ name, grp })
        });
        toast('เพิ่มคอลัมน์แล้ว');
        await afterColumnsChange();
    } catch (err) {
        toast(err.message || 'เพิ่มคอลัมน์ไม่สำเร็จ', 'danger');
    }
}

/**
 * ลบคอลัมน์: ถ้ามีงานเปิดกล่องเลือกที่ย้ายงานก่อน ถ้าว่างให้ยืนยันแล้วลบเลย
 * เก็บคอลัมน์ที่รออยู่ใน columnPendingDelete
 */
async function deleteColumn(columnId) {
    const column = columnById(columnId);
    if (!column) return;
    const count = ((activeProjectData && activeProjectData.tasks) || [])
        .filter(t => Number(t.column_id) === Number(columnId)).length;

    if (count > 0) {
        columnPendingDelete = Number(columnId);
        document.getElementById('deleteColumnTitle').textContent = 'ลบคอลัมน์ "' + column.name + '"';
        document.getElementById('deleteColumnMessage').textContent = 'คอลัมน์นี้มีงาน ' + count + ' งาน เลือกที่ให้งานไปอยู่ก่อนลบ';
        document.getElementById('deleteColumnMoveTo').innerHTML = projectColumns()
            .filter(c => Number(c.id) !== Number(columnId))
            .map(c => '<option value="' + Number(c.id) + '">' + escHtml(c.name) + ' (' + escHtml(GROUP_LABEL[c.grp]) + ')</option>')
            .join('');
        formErrorLine('deleteColumnError', '');
        openModal('deleteColumnModal');
        return;
    }

    if (!await confirmAction('ลบคอลัมน์ "' + column.name + '" ออกจากบอร์ดแล้วกู้คืนไม่ได้', 'ลบคอลัมน์', 'ลบคอลัมน์ "' + column.name + '"?')) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/columns/' + columnId, { method: 'DELETE' });
        toast('ลบคอลัมน์แล้ว');
        await afterColumnsChange();
    } catch (err) {
        toast(err.message || 'ลบคอลัมน์ไม่สำเร็จ', 'danger');
    }
}

/** ยืนยันลบคอลัมน์ที่มีงาน: ส่ง move_to แล้วปิดกล่องและโหลดใหม่ */
async function confirmDeleteColumn(event) {
    event.preventDefault();
    if (!columnPendingDelete) return;
    const moveTo = parseInt(document.getElementById('deleteColumnMoveTo').value, 10);
    if (!moveTo) { formErrorLine('deleteColumnError', 'เลือกคอลัมน์ที่จะย้ายงานไป'); return; }

    try {
        await apiFetch(BASE_URL + '/api/projects/columns/' + columnPendingDelete, {
            method: 'DELETE',
            body: JSON.stringify({ move_to: moveTo })
        });
        columnPendingDelete = null;
        closeModal('deleteColumnModal');
        toast('ย้ายงานและลบคอลัมน์แล้ว');
        await afterColumnsChange();
    } catch (err) {
        formErrorLine('deleteColumnError', err.message || 'ลบคอลัมน์ไม่สำเร็จ');
    }
}

/* ── The project type ── */
/** วาดแผงประเภท: หูสีของประเภทปัจจุบัน และปุ่มเปลี่ยนเฉพาะเจ้าของ */
function renderTypeSettings() {
    const project = activeProjectData && activeProjectData.project;
    if (!project) return;
    const type = project.type || 'general';

    const wrap = document.getElementById('typeCurrentWrap');
    wrap.dataset.ptype = type;
    document.getElementById('typeCurrentEar').textContent = PROJECT_TYPE_EAR[type] || PROJECT_TYPE_EAR.general;

    show('typeChangeOwner', !!project.is_owner);
    show('typeDenied', !project.is_owner);
    document.querySelectorAll('#typeChangeOwner [data-type-button]').forEach(button => {
        button.hidden = button.dataset.typeButton === type;
    });
}

/**
 * เปลี่ยนประเภทของโปรเจคหลังยืนยันแล้ว
 * รับรหัสประเภท ไม่คืนค่า แล้วโหลดโปรเจคใหม่ให้หูสีและบอร์ดตรงกัน
 */
async function changeProjectType(type) {
    const project = activeProjectData && activeProjectData.project;
    if (!project || !project.is_owner) return;
    const ear = PROJECT_TYPE_EAR[type] || type;

    if (!await confirmAction(
        'คอลัมน์ งาน ทีม และแชทไม่เปลี่ยน ข้อมูลเฉพาะของประเภทเดิม (ถ้ามี) จะถูกซ่อนไว้ ไม่ลบ เปลี่ยนกลับเมื่อไหร่ก็กลับมาครบ',
        'เปลี่ยนเป็น' + ear,
        'เปลี่ยนเป็น "' + ear + '"',
        'primary'
    )) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId, {
            method: 'PUT',
            body: JSON.stringify({ type })
        });
        toast('เปลี่ยนประเภทแล้ว');
        await selectProject(activeProjectId);
        await refreshProjectList();
    } catch (err) {
        toast(err.message || 'เปลี่ยนประเภทไม่สำเร็จ', 'danger');
    }
}

/** วาดแผงตั้งค่าทั้งสามหัวข้อและคืนแท็บที่จำไว้ */
function renderSettingsPanel() {
    if (!activeProjectData || !activeProjectData.project) return;
    renderColumnsSettings();
    renderFieldsSettings();
    renderTypeSettings();
    applyProjectTab();
}
