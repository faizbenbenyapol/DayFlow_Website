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

/* ── Members ── */
function openInviteMemberModal() {
    if (activeProjectId) loadProjectMembers(true);
}

async function loadProjectMembers(shouldOpenModal = true) {
    if (!activeProjectId) return;
    try {
        const data = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/members');
        projectMembers = data.members || [];
        document.getElementById('memberCountBadge').textContent = projectMembers.length;
        if (!shouldOpenModal) return;

        const isOwner = !!(activeProjectData && activeProjectData.project.is_owner);
        document.getElementById('inviteMemberForm').hidden = !isOwner;
        formErrorLine('inviteError', '');

        const shareSection = document.getElementById('publicShareSection');
        shareSection.hidden = !isOwner;
        if (isOwner) loadProjectShareSettings();

        const ROLE = { Owner: 'เจ้าของ', Editor: 'แก้ไขได้', Viewer: 'ดูอย่างเดียว' };
        document.getElementById('projectMembersList').innerHTML = projectMembers.map(m => {
            const isMe = CURRENT_USER_ID === parseInt(m.id, 10);
            const name = escHtml(m.display_name || m.username);
            const canRemove = isOwner && !isMe && m.role !== 'Owner';
            const canLeave = !isOwner && isMe && m.role !== 'Owner';
            return '<li class="ruled-row member-item"><span class="grow"><span class="title">' + name + (isMe ? ' (คุณ)' : '') + '</span>'
                + '<span class="meta">' + escHtml(m.email) + '</span></span>'
                + '<span class="badge badge-gray">' + escHtml(ROLE[m.role] || m.role) + '</span>'
                + (canRemove ? '<button type="button" class="icon-btn sm" data-act="removeMember" data-args="[' + m.id + ']" aria-label="นำ ' + name + ' ออกจากทีม"><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></button>' : '')
                + (canLeave ? '<button type="button" class="btn btn-sm btn-danger" data-act="removeMember" data-args="[' + m.id + ']">ออกจากโปรเจค</button>' : '')
                + '</li>';
        }).join('');

        openModal('inviteMemberModal');
    } catch {
        toast('โหลดรายชื่อสมาชิกไม่สำเร็จ', 'danger');
    }
}

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
        await loadProjectMembers(true);
        await selectProject(activeProjectId);
    } catch (err) {
        formErrorLine('inviteError', err.message || 'เชิญไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function removeMember(userId) {
    if (!activeProjectId) return;
    const isMe = CURRENT_USER_ID === userId;
    if (!await confirmAction(
        isMe ? 'คุณจะไม่เห็นโปรเจคนี้อีก จนกว่าเจ้าของจะเชิญกลับ' : 'คนนี้จะไม่เห็นโปรเจคนี้อีก',
        isMe ? 'ออกจากโปรเจค' : 'นำออกจากทีม',
        isMe ? 'ออกจากโปรเจคนี้?' : 'นำสมาชิกออกจากทีม?'
    )) return;

    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/members/' + userId, { method: 'DELETE' });
        toast(isMe ? 'ออกจากโปรเจคแล้ว' : 'นำออกจากทีมแล้ว');
        if (isMe) {
            closeModal('inviteMemberModal');
            await loadProjects();
        } else {
            await loadProjectMembers(true);
            await selectProject(activeProjectId);
        }
    } catch (err) {
        toast(err.message || 'ทำไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
