/* =====================================================
   projects-files.js — ไฟล์แนบในแผงรายละเอียดงาน (P0b slice 5, D-4)
   แนบจากเครื่อง (ลงคลังของคนแนบในโฟลเดอร์ของโปรเจค) หรือเลือกไฟล์ที่มีในคลังอยู่แล้ว
===================================================== */

let filesTaskId = 0;

/** ไอคอนตัวอักษรของชนิดไฟล์ เช่น PDF, CSV, IMG */
function fileBadge(name, mime) {
    if (String(mime || '').startsWith('image/')) return 'IMG';
    const ext = String(name || '').split('.').pop();
    return ext && ext.length <= 4 ? ext.toUpperCase() : 'FILE';
}

/** เปิดแผงงาน: โหลดไฟล์แนบ ซ่อนทั้งส่วนสำหรับแขกลิงก์แชร์ ซ่อนปุ่มแนบสำหรับผู้ดูอย่างเดียว */
async function loadTaskFiles(taskId) {
    filesTaskId = taskId;
    const section = document.getElementById('taskFiles');
    section.hidden = document.body.classList.contains('share-body');
    if (section.hidden) return;
    document.getElementById('taskFileActions').hidden = isViewer();
    closeTaskFilePicker();
    await refreshTaskFiles();
}

/** วาดรายการไฟล์แนบใหม่จากเซิร์ฟเวอร์ */
async function refreshTaskFiles() {
    const list = document.getElementById('taskFileList');
    try {
        const data = await apiFetch(BASE_URL + '/api/projects/tasks/' + filesTaskId + '/files');
        const items = data.items || [];
        const role = typeof callerRole === 'function' ? callerRole() : '';
        document.getElementById('taskFileCount').textContent = items.length ? String(items.length) : '';
        list.innerHTML = items.length ? items.map(f => {
            const canRemove = Number(f.added_by) === CURRENT_USER_ID || role === 'Owner' || role === 'Admin';
            const url = BASE_URL + '/api/projects/tasks/' + filesTaskId + '/files/' + Number(f.file_id);
            return '<div class="file-row"><span class="file-ico" aria-hidden="true">' + escHtml(fileBadge(f.name, f.mime_type)) + '</span>'
                + '<span class="file-main"><a href="' + escHtml(url) + '" download>' + escHtml(f.name) + '</a>'
                + '<span class="file-sub">' + escHtml(f.added_by_name) + ' · ' + escHtml(formatBytesJs(Number(f.file_size || 0))) + ' · ' + escHtml(historyWhen(f.created_at)) + '</span></span>'
                + (canRemove ? '<button type="button" class="btn btn-link btn-sm" data-act="unlinkTaskFile" data-args="[' + Number(f.file_id) + ']">ถอด</button>' : '')
                + '</div>';
        }).join('') : '<p class="proj-note">ยังไม่มีไฟล์แนบ</p>';
    } catch (err) {
        if (err.status === 403) document.getElementById('taskFiles').hidden = true;
        else list.innerHTML = '<p class="proj-note">โหลดไฟล์แนบไม่ได้</p>';
    }
}

/** ขนาดไฟล์อ่านง่าย */
function formatBytesJs(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

/** แนบไฟล์จากเครื่อง ทีละไฟล์ตามลำดับ แจ้งผลรวม */
async function uploadTaskFiles() {
    const input = document.getElementById('taskFileInput');
    const files = [...input.files];
    input.value = '';
    let done = 0;
    for (const file of files) {
        const form = new FormData();
        form.append('file', file);
        try {
            await apiFetch(BASE_URL + '/api/projects/tasks/' + filesTaskId + '/files', { method: 'POST', body: form });
            done++;
        } catch (err) {
            toast('"' + file.name + '": ' + (err.message || 'แนบไม่สำเร็จ'), 'danger');
        }
    }
    if (done) toast('แนบแล้ว ' + done + ' ไฟล์');
    await refreshTaskFiles();
}

/** ถอดไฟล์ออกจากงาน (ไฟล์ในคลังของคนแนบยังอยู่) */
async function unlinkTaskFile(fileId) {
    if (!await confirmAction('ถอดไฟล์นี้ออกจากงาน? ไฟล์ยังอยู่ในคลังไฟล์ของคนแนบ', 'ถอดไฟล์', 'ถอดไฟล์แนบ', 'primary')) return;
    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + filesTaskId + '/files/' + fileId, { method: 'DELETE', body: '{}' });
        await refreshTaskFiles();
    } catch (err) {
        toast(err.message || 'ถอดไม่สำเร็จ', 'danger');
    }
}

/* ---------------- เลือกจากคลังไฟล์ของตัวเอง ---------------- */

/** เปิดตัวเลือกที่โฟลเดอร์บนสุดของคลัง */
function openTaskFilePicker() {
    document.getElementById('taskFilePicker').hidden = false;
    browseTaskFilePicker(null);
}

/** ปิดตัวเลือก */
function closeTaskFilePicker() {
    document.getElementById('taskFilePicker').hidden = true;
}

/**
 * แสดงของในโฟลเดอร์หนึ่ง: โฟลเดอร์กดเข้าได้ ไฟล์กดแล้วแนบ
 * @param {number|null} parentId โฟลเดอร์ (null = บนสุด)
 */
async function browseTaskFilePicker(parentId) {
    const list = document.getElementById('taskFilePickerList');
    list.innerHTML = '<p class="proj-note">กำลังโหลด…</p>';
    try {
        const data = await apiFetch(BASE_URL + '/api/files' + (parentId ? '?parent_id=' + parentId : ''));
        const crumbs = data.breadcrumbs || [];
        document.getElementById('taskFilePickerPath').innerHTML =
            '<button type="button" class="btn btn-link btn-sm" data-act="browseTaskFilePicker" data-args="[null]">คลังไฟล์</button>'
            + crumbs.map(c => ' › <button type="button" class="btn btn-link btn-sm" data-act="browseTaskFilePicker" data-args="[' + Number(c.id) + ']">' + escHtml(c.name) + '</button>').join('');
        // โฟลเดอร์ขึ้นก่อนไฟล์ ให้เดินเข้าโฟลเดอร์ได้โดยไม่ต้องเลื่อนผ่านไฟล์
        const rows = (data.files || []).sort((a, b) => (b.type === 'folder') - (a.type === 'folder'));
        list.innerHTML = rows.length ? rows.map(f => f.type === 'folder'
            ? '<button type="button" class="picker-row" data-act="browseTaskFilePicker" data-args="[' + Number(f.id) + ']">📁 ' + escHtml(f.name) + '</button>'
            : '<button type="button" class="picker-row" data-act="linkTaskFile" data-args="[' + Number(f.id) + ']">' + escHtml(fileBadge(f.name, f.mime_type)) + ' · ' + escHtml(f.name) + '</button>'
        ).join('') : '<p class="proj-note">โฟลเดอร์นี้ว่าง</p>';
    } catch (err) {
        list.innerHTML = '<p class="proj-note">' + escHtml(err.message || 'เปิดคลังไฟล์ไม่ได้') + '</p>';
    }
}

/** แนบไฟล์ที่เลือกจากคลัง */
async function linkTaskFile(fileId) {
    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + filesTaskId + '/files/link', { method: 'POST', body: JSON.stringify({ file_id: fileId }) });
        toast('แนบแล้ว');
        closeTaskFilePicker();
        await refreshTaskFiles();
    } catch (err) {
        toast(err.message || 'แนบไม่สำเร็จ', 'danger');
    }
}
