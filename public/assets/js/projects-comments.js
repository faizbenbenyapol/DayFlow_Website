/* =====================================================
   projects-comments.js — ความเห็นในแผงรายละเอียดงาน และตัวเลือก @กล่าวถึง (P0b slice 4)
   แผงที่เปิดอยู่ดึงความเห็นใหม่ทุก 30 วินาที (REQ-P5: ขึ้นภายใน 1 นาที) ปิดแผงแล้วหยุด
===================================================== */

const COMMENT_POLL_MS = 30000;
let commentTaskId = 0;
let lastCommentId = 0;
let commentTimer = null;
let mentionMatches = [];
let mentionIndex = 0;

/** เปิดแผงงาน: ล้างรายการเดิม โหลดความเห็นทั้งหมด แล้วเริ่มดึงของใหม่ทุก 30 วินาที */
async function loadTaskComments(taskId) {
    stopCommentPolling();
    commentTaskId = taskId;
    lastCommentId = 0;
    const section = document.getElementById('taskComments');
    // แขกจากลิงก์แชร์ไม่เห็นความเห็น (D-5) ผู้ดูอย่างเดียวอ่านได้ เขียนไม่ได้
    section.hidden = document.body.classList.contains('share-body');
    if (section.hidden) return;
    document.getElementById('taskCommentComposer').hidden = isViewer();
    document.getElementById('taskCommentList').innerHTML = '';
    document.getElementById('taskCommentInput').value = '';
    hideMentionPop();
    await pollTaskComments();
    commentTimer = setInterval(pollTaskComments, COMMENT_POLL_MS);
}

/** หยุดดึงความเห็น (ปิดแผง หรือเปลี่ยนงาน) */
function stopCommentPolling() {
    if (commentTimer) clearInterval(commentTimer);
    commentTimer = null;
}

/** ดึงเฉพาะความเห็นที่ใหม่กว่าตัวล่าสุดที่มีแล้วมาต่อท้าย ข้ามเมื่อแท็บไม่ได้เปิดอยู่ */
async function pollTaskComments() {
    if (!commentTaskId || (lastCommentId > 0 && document.visibilityState !== 'visible')) return;
    const taskId = commentTaskId;
    try {
        const data = await apiFetch(BASE_URL + '/api/projects/tasks/' + taskId + '/comments?after=' + lastCommentId);
        if (taskId !== commentTaskId) return; // ผู้ใช้เปลี่ยนไปงานอื่นระหว่างรอ
        const list = document.getElementById('taskCommentList');
        (data.items || []).forEach(c => {
            list.insertAdjacentHTML('beforeend', commentHtml(c));
            lastCommentId = Math.max(lastCommentId, Number(c.id));
        });
        updateCommentCount();
    } catch (err) {
        if (err.status === 403) document.getElementById('taskComments').hidden = true;
    }
}

/** จำนวนความเห็นบนหัวข้อ และข้อความเมื่อยังไม่มี */
function updateCommentCount() {
    const count = document.querySelectorAll('#taskCommentList .comment').length;
    document.getElementById('taskCommentCount').textContent = count ? String(count) : '';
    const list = document.getElementById('taskCommentList');
    const empty = list.querySelector('.comment-empty');
    if (!count && !empty) list.innerHTML = '<p class="comment-empty proj-note">ยังไม่มีความเห็น</p>';
    if (count && empty) empty.remove();
}

/**
 * HTML ของความเห็นหนึ่งรายการ: escape ทุกอย่างก่อน แล้วจึงเน้น @username ของคนในทีม
 * @param {object} c id, user_id, body, created_at, author_name
 */
function commentHtml(c) {
    const usernames = new Set((projectMembers || []).map(m => m.username));
    const body = escHtml(c.body).replace(/@([A-Za-z0-9_]{3,30})/g, (all, name) =>
        usernames.has(name) ? '<span class="mention">' + all + '</span>' : all).replace(/\n/g, '<br>');
    const role = typeof callerRole === 'function' ? callerRole() : '';
    const canDelete = Number(c.user_id) === CURRENT_USER_ID || role === 'Owner' || role === 'Admin';
    return '<div class="comment" data-comment-id="' + Number(c.id) + '">'
        + '<span class="avatar" aria-hidden="true">' + escHtml(String(c.author_name || '?').charAt(0)) + '</span>'
        + '<div><div class="comment-who">' + escHtml(c.author_name) + '<time>' + escHtml(historyWhen(c.created_at)) + '</time>'
        + (canDelete ? '<button type="button" class="btn btn-link btn-sm" data-act="deleteTaskComment" data-args="[' + Number(c.id) + ']">ลบ</button>' : '')
        + '</div><p>' + body + '</p></div></div>';
}

/** ส่งความเห็น แล้วดึงของใหม่ทันที (รวมของตัวเอง) */
async function sendTaskComment() {
    const input = document.getElementById('taskCommentInput');
    const body = input.value.trim();
    if (!body) { input.focus(); return; }
    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + commentTaskId + '/comments', { method: 'POST', body: JSON.stringify({ body }) });
        input.value = '';
        hideMentionPop();
        await pollTaskComments();
    } catch (err) {
        toast(err.message || 'ส่งความเห็นไม่สำเร็จ', 'error');
    }
}

/** ลบความเห็น (ยืนยันก่อน) */
async function deleteTaskComment(id) {
    if (!await confirmAction('ลบความเห็นนี้? กู้คืนไม่ได้', 'ลบความเห็น')) return;
    try {
        await apiFetch(BASE_URL + '/api/projects/comments/' + id, { method: 'DELETE', body: '{}' });
        document.querySelector('#taskCommentList [data-comment-id="' + Number(id) + '"]')?.remove();
        updateCommentCount();
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ', 'error');
    }
}

/* ---------------- ตัวเลือก @กล่าวถึง ---------------- */

/** พิมพ์ในช่องความเห็น: ถ้ามี @คำ อยู่หน้าเคอร์เซอร์ ให้แสดงคนในทีมที่ชื่อตรง */
function onCommentInput() {
    const input = document.getElementById('taskCommentInput');
    const before = input.value.slice(0, input.selectionStart);
    const m = before.match(/(^|\s)@([A-Za-z0-9_฀-๿]*)$/);
    if (!m) { hideMentionPop(); return; }
    const q = m[2].toLowerCase();
    mentionMatches = (projectMembers || []).filter(p => Number(p.id) !== CURRENT_USER_ID
        && (String(p.username).toLowerCase().startsWith(q) || String(p.display_name || '').toLowerCase().includes(q))).slice(0, 6);
    if (!mentionMatches.length) { hideMentionPop(); return; }
    mentionIndex = 0;
    renderMentionPop();
}

/** วาดรายการคนให้เลือก แถวที่เลือกอยู่มี aria-selected */
function renderMentionPop() {
    const pop = document.getElementById('mentionPop');
    pop.innerHTML = mentionMatches.map((p, i) =>
        '<div role="option" id="mention-' + i + '" aria-selected="' + (i === mentionIndex) + '" data-act="pickMention" data-args="[' + i + ']">'
        + escHtml(p.display_name || p.username) + ' <span class="text-muted">@' + escHtml(p.username) + '</span></div>').join('');
    pop.hidden = false;
    document.getElementById('taskCommentInput').setAttribute('aria-activedescendant', 'mention-' + mentionIndex);
}

/** ซ่อนตัวเลือกคน */
function hideMentionPop() {
    const pop = document.getElementById('mentionPop');
    if (pop) pop.hidden = true;
    mentionMatches = [];
    document.getElementById('taskCommentInput')?.removeAttribute('aria-activedescendant');
}

/** เลือกคน: แทน @คำที่พิมพ์ค้างด้วย @username แล้วเว้นวรรค */
function pickMention(i) {
    const person = mentionMatches[i];
    if (!person) return;
    const input = document.getElementById('taskCommentInput');
    const caret = input.selectionStart;
    const before = input.value.slice(0, caret).replace(/@[A-Za-z0-9_฀-๿]*$/, '@' + person.username + ' ');
    input.value = before + input.value.slice(caret);
    input.focus();
    input.setSelectionRange(before.length, before.length);
    hideMentionPop();
}

// แป้นพิมพ์ในช่องความเห็น: ลูกศรเลื่อนตัวเลือก Enter/Tab เลือก Esc ปิด Ctrl+Enter ส่ง
document.addEventListener('keydown', e => {
    if (e.target.id !== 'taskCommentInput') return;
    const popOpen = !document.getElementById('mentionPop').hidden;
    if (popOpen && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
        e.preventDefault();
        mentionIndex = (mentionIndex + (e.key === 'ArrowDown' ? 1 : mentionMatches.length - 1)) % mentionMatches.length;
        renderMentionPop();
    } else if (popOpen && (e.key === 'Enter' || e.key === 'Tab')) {
        e.preventDefault();
        pickMention(mentionIndex);
    } else if (popOpen && e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation(); // ปิดแค่ตัวเลือก ไม่ปิดทั้งแผง
        hideMentionPop();
    } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        sendTaskComment();
    }
}, true);
