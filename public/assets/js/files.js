/* =====================================================
   files.js — the file manager

   One list of what is in the open folder, as rows or as tiles. Every row can
   be reached and acted on from the keyboard: the name opens it, the checkbox
   selects it, the "more" button (or a right click) opens the menu.
===================================================== */
'use strict';

let currentParentId  = window.INITIAL_PARENT_ID || null;
let currentFiles     = [];
let currentView      = 'list';
let currentSort      = 'type-name';
let currentCategory  = 'all';
let ctxTarget        = null;
let ctxReturnFocus   = null;
let moveTargetId     = null;
let isBatchMoving    = false;
let shareTargetId    = null;
let nameMode         = 'create';   // 'create' or 'rename'
let renameTargetId   = null;
let selectedFileIds  = [];

const MAX_FILE_BYTES = window.FILES_MAX_BYTES || 20 * 1024 * 1024;

function remembered(key, fallback) {
    try { return localStorage.getItem(key) || fallback; } catch (_) { return fallback; }
}
function remember(key, value) {
    try { localStorage.setItem(key, value); } catch (_) { /* not remembered */ }
}

document.addEventListener('DOMContentLoaded', () => {
    currentView = remembered('files_view', 'list') === 'grid' ? 'grid' : 'list';
    currentSort = remembered('files_sort', 'type-name');
    setView(currentView, false);
    document.getElementById('sortSelect').value = currentSort;

    document.getElementById('btnGridView').addEventListener('click', () => setView('grid'));
    document.getElementById('btnListView').addEventListener('click', () => setView('list'));
    document.getElementById('sortSelect').addEventListener('change', e => {
        currentSort = e.target.value;
        remember('files_sort', currentSort);
        renderFiles();
    });
    document.getElementById('filesSearch').addEventListener('input', renderFiles);
    document.getElementById('btnCreateFolder').addEventListener('click', openCreateFolder);
    document.getElementById('nameForm').addEventListener('submit', e => { e.preventDefault(); submitName(); });

    document.addEventListener('click', e => { if (!e.target.closest('#ctxMenu')) closeCtx(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCtx(true); });

    document.getElementById('btnConfirmMove').addEventListener('click', confirmMove);
    document.getElementById('btnCreateShare').addEventListener('click', createShareLink);
    document.getElementById('btnCopyShareUrl').addEventListener('click', async () => {
        const input = document.getElementById('shareResultUrl');
        try { await navigator.clipboard.writeText(input.value); } catch (_) { input.select(); document.execCommand('copy'); }
        toast('คัดลอกลิงก์แล้ว');
    });
    document.getElementById('btnHideShare').addEventListener('click', () => { document.getElementById('shareResultBar').hidden = true; });

    document.querySelectorAll('#categoryFilters [data-category]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#categoryFilters [data-category]').forEach(b => {
                b.classList.toggle('active', b === btn);
                b.setAttribute('aria-pressed', String(b === btn));
            });
            currentCategory = btn.dataset.category;
            renderFiles();
        });
    });

    document.getElementById('btnBatchDelete').addEventListener('click', deleteFilesBatch);
    document.getElementById('btnBatchMove').addEventListener('click', () => { if (selectedFileIds.length) openMoveDialog(null); });
    document.getElementById('btnBatchClear').addEventListener('click', clearSelection);

    initFullPageDragDrop();
    navigate(currentParentId);
});

/* ── View ── */
function setView(view, save = true) {
    currentView = view;
    if (save) remember('files_view', view);
    document.getElementById('filesGrid').classList.toggle('list-view', view === 'list');
    document.getElementById('btnListView').setAttribute('aria-pressed', String(view === 'list'));
    document.getElementById('btnGridView').setAttribute('aria-pressed', String(view === 'grid'));
}

/* ── Opening a folder ── */
async function navigate(parentId) {
    currentParentId = parentId;
    clearSelection();
    const grid = document.getElementById('filesGrid');
    grid.setAttribute('aria-busy', 'true');
    try {
        const data = await apiFetch(BASE_URL + '/api/files' + (parentId !== null ? '?parent_id=' + parentId : ''));
        currentFiles = data.files || [];
        grid.removeAttribute('aria-busy');
        updateTally(currentFiles);
        renderBreadcrumbs(data.breadcrumbs || []);
        renderFiles();
    } catch {
        grid.removeAttribute('aria-busy');
        document.getElementById('filesTally').textContent = 'โหลดไฟล์ไม่ได้';
        grid.innerHTML = '<div class="alert alert-danger" role="alert">โหลดไฟล์ไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="reloadFiles">ลองอีกครั้ง</button></div>';
    }
}

function reloadFiles() { navigate(currentParentId); }

function renderBreadcrumbs(crumbs) {
    const el = document.getElementById('breadcrumb');
    const item = (label, id, current) => current
        ? '<li aria-current="page">' + escHtml(label) + '</li>'
        : '<li><button type="button" class="crumb" data-navigate="' + id + '">' + escHtml(label) + '</button></li>';

    el.innerHTML = '<ol>' + item('ไฟล์ทั้งหมด', 'root', crumbs.length === 0)
        + crumbs.map((c, i) => item(c.name, c.id, i === crumbs.length - 1)).join('') + '</ol>';
    el.querySelectorAll('[data-navigate]').forEach(btn => {
        btn.addEventListener('click', () => navigate(btn.dataset.navigate === 'root' ? null : parseInt(btn.dataset.navigate, 10)));
    });
}

function updateTally(files) {
    const folders = files.filter(f => f.type === 'folder').length;
    const plain = files.filter(f => f.type !== 'folder');
    const bytes = plain.reduce((sum, f) => sum + (parseInt(f.file_size, 10) || 0), 0);
    document.getElementById('filesTally').textContent = files.length
        ? folders + ' โฟลเดอร์ · ' + plain.length + ' ไฟล์' + (plain.length ? ' · รวม ' + formatBytes(bytes) : '')
        : 'โฟลเดอร์นี้ยังว่างอยู่';
}

/* ── Listing ── */
function sortFiles(files) {
    const s = currentSort;
    return [...files].sort((a, b) => {
        if (s === 'type-name') {
            if (a.type !== b.type) return a.type === 'folder' ? -1 : 1;
            return a.name.localeCompare(b.name, 'th');
        }
        if (s === 'name')      return a.name.localeCompare(b.name, 'th');
        if (s === 'name-desc') return b.name.localeCompare(a.name, 'th');
        if (s === 'size')      return (a.file_size || 0) - (b.file_size || 0);
        if (s === 'size-desc') return (b.file_size || 0) - (a.file_size || 0);
        if (s === 'date')      return new Date(a.created_at) - new Date(b.created_at);
        if (s === 'date-desc') return new Date(b.created_at) - new Date(a.created_at);
        return 0;
    });
}

function getFileCategory(f) {
    if (f.type === 'folder') return 'folder';
    if (!f.mime_type) return 'other';
    const m = f.mime_type.toLowerCase();
    if (m.startsWith('image/')) return 'image';
    if (m.includes('pdf') || m.includes('word') || m.includes('document') || m.includes('excel') || m.includes('spreadsheet') || m.startsWith('text/')) return 'document';
    if (m.startsWith('video/') || m.startsWith('audio/')) return 'media';
    if (m.includes('zip') || m.includes('compressed') || m.includes('tar')) return 'archive';
    return 'other';
}

function getFileObj(id) { return currentFiles.find(f => parseInt(f.id, 10) === id); }

/** What stands in for an icon: a folder mark, or the file's extension. */
function fileBadge(f) {
    if (f.type === 'folder') return '<svg class="icon"><use href="#i-files"/></svg>';
    const dot = f.name.lastIndexOf('.');
    const ext = dot > 0 ? f.name.slice(dot + 1, dot + 5).toUpperCase() : 'FILE';
    return '<span class="file-ext">' + escHtml(ext) + '</span>';
}

function fileRow(f) {
    const id = parseInt(f.id, 10);
    const name = escHtml(f.name);
    const checked = selectedFileIds.includes(id);
    return '<div class="file-item' + (checked ? ' selected' : '') + '" role="listitem" data-file-id="' + id + '" data-file-type="' + f.type + '" draggable="true">'
        + '<label class="file-check"><input type="checkbox" class="file-checkbox"' + (checked ? ' checked' : '') + ' aria-label="เลือก ' + name + '"></label>'
        + '<span class="file-icon" aria-hidden="true">' + fileBadge(f) + '</span>'
        + '<button type="button" class="file-name" data-open title="' + name + '">' + name + '<span class="sr-only">' + (f.type === 'folder' ? ' (โฟลเดอร์)' : '') + '</span></button>'
        + '<span class="file-size">' + (f.type === 'folder' ? '' : (f.file_size ? formatBytes(f.file_size) : '')) + '</span>'
        + '<span class="file-date">' + (f.created_at ? escHtml(formatDate(f.created_at.split(' ')[0])) : '') + '</span>'
        + '<button type="button" class="icon-btn sm file-more" aria-haspopup="menu" aria-label="ตัวเลือก: ' + name + '"><svg class="icon" aria-hidden="true"><use href="#i-all"/></svg></button>'
        + '</div>';
}

function renderFiles() {
    const grid = document.getElementById('filesGrid');
    const search = document.getElementById('filesSearch').value.trim().toLowerCase();
    let list = sortFiles(currentFiles);
    if (currentCategory !== 'all') list = list.filter(f => getFileCategory(f) === currentCategory);
    if (search) list = list.filter(f => f.name.toLowerCase().includes(search));

    grid.setAttribute('role', 'list');
    if (!list.length) {
        const filtered = search || currentCategory !== 'all';
        grid.removeAttribute('role');
        grid.innerHTML = filtered
            ? '<div class="empty-state"><p class="empty-state-text">ไม่พบรายการที่ตรงกับตัวกรอง</p><button type="button" class="btn btn-sm" data-act="clearFileFilters">ล้างตัวกรอง</button></div>'
            : '<div class="empty-state"><p class="empty-state-title">โฟลเดอร์นี้ยังว่างอยู่</p>'
              + '<p class="empty-state-text">กด "อัปโหลด" หรือวางไฟล์ลงในหน้านี้ได้เลย ไฟล์ละไม่เกิน ' + formatBytes(MAX_FILE_BYTES) + '</p>'
              + '<button type="button" class="btn btn-primary" data-click="#fileInput">อัปโหลดไฟล์</button></div>';
        updateBatchToolbar();
        return;
    }

    grid.innerHTML = list.map(fileRow).join('');
    grid.querySelectorAll('.file-item').forEach(wireRow);
    updateBatchToolbar();
}

function clearFileFilters() {
    document.getElementById('filesSearch').value = '';
    document.querySelector('#categoryFilters [data-category="all"]').click();
}

function openItem(f) {
    if (f.type === 'folder') navigate(parseInt(f.id, 10));
    else if (f.mime_type && f.mime_type.startsWith('image/')) openPreview(f);
    else downloadFile(f.id);
}

function wireRow(el) {
    const id = parseInt(el.dataset.fileId, 10);
    const f = getFileObj(id);
    if (!f) return;

    el.querySelector('[data-open]').addEventListener('click', () => openItem(f));
    el.querySelector('.file-checkbox').addEventListener('change', e => toggleSelectFile(id, e.target.checked));
    el.querySelector('.file-more').addEventListener('click', e => { e.stopPropagation(); openCtx(e, f, e.currentTarget); });
    el.addEventListener('contextmenu', e => { e.preventDefault(); openCtx(e, f); });

    if (f.type === 'folder') {
        el.addEventListener('dragover', e => { e.preventDefault(); el.classList.add('drag-target'); });
        el.addEventListener('dragleave', () => el.classList.remove('drag-target'));
        el.addEventListener('drop', e => {
            e.preventDefault();
            e.stopPropagation();
            el.classList.remove('drag-target');
            const dragged = parseInt(e.dataTransfer.getData('file-id'), 10);
            if (dragged && dragged !== id) moveFileTo(dragged, id);
        });
    }
    el.addEventListener('dragstart', e => e.dataTransfer.setData('file-id', id));
}

/* ── Selecting several ── */
function toggleSelectFile(id, checked) {
    const index = selectedFileIds.indexOf(id);
    if (checked && index === -1) selectedFileIds.push(id);
    if (!checked && index !== -1) selectedFileIds.splice(index, 1);
    document.querySelector('.file-item[data-file-id="' + id + '"]')?.classList.toggle('selected', checked);
    updateBatchToolbar();
}

function clearSelection() {
    selectedFileIds = [];
    document.querySelectorAll('.file-item').forEach(el => el.classList.remove('selected'));
    document.querySelectorAll('.file-checkbox').forEach(el => { el.checked = false; });
    updateBatchToolbar();
}

function updateBatchToolbar() {
    const bar = document.getElementById('batchToolbar');
    bar.hidden = selectedFileIds.length === 0;
    document.getElementById('batchCountBadge').textContent = selectedFileIds.length;
}

/* ── The menu ── */
function openCtx(e, f, opener) {
    ctxTarget = f;
    ctxReturnFocus = opener || null;
    const menu = document.getElementById('ctxMenu');
    document.querySelector('#ctxOpen span').textContent = f.type === 'folder' ? 'เปิดโฟลเดอร์' : 'ดาวน์โหลด';
    document.getElementById('ctxPreview').hidden = !(f.type !== 'folder' && f.mime_type && f.mime_type.startsWith('image/'));

    menu.hidden = false;
    const rect = opener ? opener.getBoundingClientRect() : null;
    const wantX = rect ? rect.left : e.clientX;
    const wantY = rect ? rect.bottom : e.clientY;
    menu.style.left = Math.max(8, Math.min(wantX, window.innerWidth - menu.offsetWidth - 8)) + 'px';
    menu.style.top  = Math.max(8, Math.min(wantY, window.innerHeight - menu.offsetHeight - 8)) + 'px';

    const act = fn => () => { closeCtx(); fn(); };
    document.getElementById('ctxOpen').onclick    = act(() => openItem(f));
    document.getElementById('ctxPreview').onclick = act(() => openPreview(f));
    document.getElementById('ctxRename').onclick  = act(() => openRename(f));
    document.getElementById('ctxMove').onclick    = act(() => openMoveDialog(parseInt(f.id, 10)));
    document.getElementById('ctxShare').onclick   = act(() => openShareQuick(parseInt(f.id, 10)));
    document.getElementById('ctxDelete').onclick  = act(() => deleteFile(parseInt(f.id, 10)));

    // Opened from a button, so a keyboard user lands in the menu.
    if (opener) menu.querySelector('.ctx-item:not([hidden])').focus();
}

function closeCtx(returnFocus) {
    const menu = document.getElementById('ctxMenu');
    if (menu.hidden) return;
    menu.hidden = true;
    ctxTarget = null;
    if (returnFocus && ctxReturnFocus) ctxReturnFocus.focus();
}

/* ── Image preview ── */
function openPreview(f) {
    const img = document.getElementById('previewImg');
    img.src = BASE_URL + '/api/files/' + f.id + '/download';
    img.alt = f.name;
    document.getElementById('previewCaption').textContent = f.name;
    openModal('previewModal');
}

/* ── New folder and rename share one dialog ── */
function nameError(message) {
    const line = document.getElementById('nameError');
    line.textContent = message;
    line.hidden = message === '';
}

function openCreateFolder() {
    nameMode = 'create';
    renameTargetId = null;
    document.getElementById('nameModalTitle').textContent = 'โฟลเดอร์ใหม่';
    document.getElementById('nameLabel').textContent = 'ชื่อโฟลเดอร์';
    document.getElementById('nameSubmit').textContent = 'สร้างโฟลเดอร์';
    document.getElementById('nameInput').value = '';
    nameError('');
    openModal('nameModal');
    document.getElementById('nameInput').focus();
}

function openRename(f) {
    nameMode = 'rename';
    renameTargetId = parseInt(f.id, 10);
    document.getElementById('nameModalTitle').textContent = 'เปลี่ยนชื่อ';
    document.getElementById('nameLabel').textContent = 'ชื่อใหม่';
    document.getElementById('nameSubmit').textContent = 'บันทึกชื่อ';
    document.getElementById('nameInput').value = f.name;
    nameError('');
    openModal('nameModal');
    document.getElementById('nameInput').select();
}

async function submitName() {
    const name = document.getElementById('nameInput').value.trim();
    if (!name) { nameError('ใส่ชื่อก่อน'); return; }

    try {
        if (nameMode === 'create') {
            await apiFetch(BASE_URL + '/api/files/folder', { method: 'POST', body: JSON.stringify({ name, parent_id: currentParentId }) });
        } else {
            await apiFetch(BASE_URL + '/api/files/' + renameTargetId + '/rename', { method: 'PUT', body: JSON.stringify({ name }) });
        }
        closeModal('nameModal');
        await navigate(currentParentId);
        toast(nameMode === 'create' ? 'สร้างโฟลเดอร์แล้ว' : 'เปลี่ยนชื่อแล้ว');
    } catch (err) {
        nameError(err.message || 'ไม่สำเร็จ ลองอีกครั้ง');
    }
}

/* ── Move ── */
async function openMoveDialog(fileId) {
    isBatchMoving = fileId === null;
    if (!isBatchMoving) moveTargetId = fileId;

    const confirmBtn = document.getElementById('btnConfirmMove');
    confirmBtn.dataset.targetId = '';
    openModal('moveOverlay');
    const list = document.getElementById('moveFolderList');
    list.innerHTML = '<div class="skel-row"><span class="skel skel-w-52"></span></div>';

    try {
        const data = await apiFetch(BASE_URL + '/api/files/folders');
        // A folder cannot go inside itself, so those are left out of the choices.
        const folders = (data.folders || []).filter(f => isBatchMoving ? !selectedFileIds.includes(parseInt(f.id, 10)) : parseInt(f.id, 10) !== fileId);

        list.innerHTML = '<button type="button" class="move-item active" data-folder-id="" aria-pressed="true">ไฟล์ทั้งหมด (ระดับบนสุด)</button>'
            + buildFolderTree(folders, null, 0);
        list.querySelectorAll('.move-item').forEach(el => {
            el.addEventListener('click', () => {
                list.querySelectorAll('.move-item').forEach(x => { x.classList.toggle('active', x === el); x.setAttribute('aria-pressed', String(x === el)); });
                confirmBtn.dataset.targetId = el.dataset.folderId;
            });
        });
    } catch {
        list.innerHTML = '<p class="form-error">โหลดรายการโฟลเดอร์ไม่สำเร็จ ปิดแล้วลองใหม่</p>';
    }
}

function buildFolderTree(folders, parentId, depth) {
    return folders
        .filter(f => (f.parent_id == null ? null : parseInt(f.parent_id, 10)) === parentId)
        .map(f => '<button type="button" class="move-item" aria-pressed="false" data-folder-id="' + f.id + '" data-depth="' + Math.min(depth, 6) + '">' + escHtml(f.name) + '</button>'
            + buildFolderTree(folders, parseInt(f.id, 10), depth + 1))
        .join('');
}

async function confirmMove() {
    const raw = document.getElementById('btnConfirmMove').dataset.targetId;
    const targetId = raw === '' || raw === undefined ? null : parseInt(raw, 10);
    const ids = isBatchMoving ? selectedFileIds.slice() : (moveTargetId === null ? [] : [moveTargetId]);
    if (!ids.length) return;

    try {
        await Promise.all(ids.map(id => apiFetch(BASE_URL + '/api/files/' + id + '/move', { method: 'PUT', body: JSON.stringify({ parent_id: targetId }) })));
        closeModal('moveOverlay');
        moveTargetId = null;
        await navigate(currentParentId);
        toast('ย้ายแล้ว');
    } catch (err) {
        toast('ย้ายไม่สำเร็จ: ' + (err.message || 'ลองอีกครั้ง'), 'danger');
    }
}

async function moveFileTo(fileId, folderId) {
    try {
        await apiFetch(BASE_URL + '/api/files/' + fileId + '/move', { method: 'PUT', body: JSON.stringify({ parent_id: folderId }) });
        navigate(currentParentId);
        toast('ย้ายแล้ว');
    } catch (err) { toast(err.message || 'ย้ายไม่สำเร็จ', 'danger'); }
}

/* ── Sharing ── */
function shareError(message) {
    const line = document.getElementById('shareError');
    line.textContent = message;
    line.hidden = message === '';
}

function openShareQuick(fileId) {
    shareTargetId = fileId;
    const f = getFileObj(fileId);
    document.getElementById('shareTitle').textContent = 'แชร์: ' + (f ? f.name : 'ไฟล์');
    document.getElementById('sqLabel').value = '';
    document.getElementById('sqPermission').value = 'view';
    document.getElementById('sqExpires').value = '';
    document.getElementById('shareResultBar').hidden = true;
    shareError('');
    openModal('shareQuickOverlay');
}

async function createShareLink() {
    if (!shareTargetId) return;
    try {
        const res = await apiFetch(BASE_URL + '/api/shares', {
            method: 'POST',
            body: JSON.stringify({
                file_id: shareTargetId,
                label: document.getElementById('sqLabel').value.trim(),
                permission: document.getElementById('sqPermission').value,
                expires_at: document.getElementById('sqExpires').value || null,
            })
        });
        closeModal('shareQuickOverlay');
        document.getElementById('shareResultUrl').value = res.link;
        document.getElementById('shareResultBar').hidden = false;
        toast('สร้างลิงก์แชร์แล้ว');
    } catch (err) {
        shareError(err.message || 'สร้างลิงก์ไม่สำเร็จ ลองอีกครั้ง');
    }
}

/* ── Upload ── */
/** Dropping files anywhere on the page uploads them; moving a row inside the page does not count. */
function initFullPageDragDrop() {
    let depth = 0;
    const overlay = document.getElementById('fullDropOverlay');
    const hasFiles = e => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');

    window.addEventListener('dragenter', e => {
        if (!hasFiles(e)) return;
        e.preventDefault();
        if (++depth === 1) overlay.classList.add('active');
    });
    window.addEventListener('dragleave', e => {
        if (!hasFiles(e)) return;
        if (--depth <= 0) { depth = 0; overlay.classList.remove('active'); }
    });
    window.addEventListener('dragover', e => { if (hasFiles(e)) e.preventDefault(); });
    window.addEventListener('drop', e => {
        if (!hasFiles(e)) return;
        e.preventDefault();
        depth = 0;
        overlay.classList.remove('active');
        if (e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
    });
}

async function uploadFiles(fileList) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const queue = document.getElementById('uploadQueue');
    queue.hidden = false;
    queue.innerHTML = '';

    const items = Array.from(fileList).map(file => {
        const row = document.createElement('div');
        row.className = 'upload-queue-item';
        row.innerHTML = '<span class="upload-queue-name">' + escHtml(file.name) + '</span>'
            + '<div class="progress"><div class="progress-bar"></div></div>'
            + '<span class="upload-queue-status">รอคิว</span>';
        queue.appendChild(row);
        return { file, row };
    });

    let anyOk = false;
    for (const { file, row } of items) {
        const bar = row.querySelector('.progress-bar');
        const status = row.querySelector('.upload-queue-status');

        if (file.size > MAX_FILE_BYTES) {
            status.textContent = 'ใหญ่เกิน ' + formatBytes(MAX_FILE_BYTES);
            status.classList.add('error');
            continue;
        }
        status.textContent = 'กำลังอัปโหลด';

        const fd = new FormData();
        fd.append('file', file);
        fd.append('_csrf', csrf);
        if (currentParentId !== null) fd.append('parent_id', currentParentId);

        await new Promise(resolve => {
            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = e => {
                if (e.lengthComputable) bar.style.setProperty('--v', Math.round(e.loaded / e.total * 100) + '%');
            };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    bar.style.setProperty('--v', '100%');
                    status.textContent = 'สำเร็จ';
                    status.classList.add('done');
                    anyOk = true;
                } else {
                    let message = 'ไม่สำเร็จ';
                    try { message = JSON.parse(xhr.responseText).error || message; } catch (_) { /* keep the default */ }
                    status.textContent = message;
                    status.classList.add('error');
                }
                resolve();
            };
            xhr.onerror = () => { status.textContent = 'เชื่อมต่อไม่สำเร็จ'; status.classList.add('error'); resolve(); };
            xhr.open('POST', BASE_URL + '/api/files/upload');
            xhr.setRequestHeader('X-CSRF-Token', csrf);
            xhr.send(fd);
        });
    }

    if (anyOk) { await navigate(currentParentId); toast('อัปโหลดแล้ว'); }
    // A failure stays on screen until the next upload; a clean run clears itself.
    if (!queue.querySelector('.error')) setTimeout(() => { queue.hidden = true; queue.innerHTML = ''; }, 3000);
}

/* ── Delete ── */
async function deleteFile(id) {
    const f = getFileObj(id);
    const what = f ? '"' + f.name + '"' : 'รายการนี้';
    const extra = f && f.type === 'folder' ? ' และทุกอย่างในโฟลเดอร์' : '';
    if (!await confirmAction('ลบ ' + what + extra + ' แล้วกู้คืนไม่ได้', 'ลบ', 'ลบรายการนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/files/' + id, { method: 'DELETE' });
        navigate(currentParentId);
        toast('ลบแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ', 'danger');
    }
}

async function deleteFilesBatch() {
    const count = selectedFileIds.length;
    if (!count) return;
    if (!await confirmAction('ลบ ' + count + ' รายการที่เลือก (รวมของในโฟลเดอร์) แล้วกู้คืนไม่ได้', 'ลบ ' + count + ' รายการ', 'ลบรายการที่เลือก?')) return;
    try {
        await Promise.all(selectedFileIds.map(id => apiFetch(BASE_URL + '/api/files/' + id, { method: 'DELETE' })));
        toast('ลบ ' + count + ' รายการแล้ว');
        navigate(currentParentId);
    } catch (err) {
        toast('ลบไม่สำเร็จบางรายการ: ' + (err.message || 'ลองอีกครั้ง'), 'danger');
        navigate(currentParentId);
    }
}

function downloadFile(id) { window.location.href = BASE_URL + '/api/files/' + id + '/download'; }

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
    if (bytes >= 1048576)    return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024)       return (bytes / 1024).toFixed(0) + ' KB';
    return bytes + ' B';
}
