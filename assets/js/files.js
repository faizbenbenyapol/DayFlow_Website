/* =====================================================
   files.js — File Manager (Upgraded Modern Version)
   ===================================================== */
'use strict';

let currentParentId  = window.INITIAL_PARENT_ID || null;
let currentFiles     = [];
let currentView      = localStorage.getItem('files_view') || 'grid';
let currentSort      = localStorage.getItem('files_sort') || 'type-name';
let ctxTarget        = null;   // { id, type, name, mime_type, file_path }
let moveTargetId     = null;   // file/folder being moved
let shareTargetId    = null;   // file/folder being shared

// Batch selection states
let selectedFileIds  = [];
let currentCategory  = 'all';
let isBatchMoving    = false;

// ---- Init ----
document.addEventListener('DOMContentLoaded', () => {
    setView(currentView, false);
    document.getElementById('sortSelect').value = currentSort;

    document.getElementById('btnGridView').addEventListener('click', () => setView('grid'));
    document.getElementById('btnListView').addEventListener('click', () => setView('list'));
    document.getElementById('sortSelect').addEventListener('change', e => { 
        currentSort = e.target.value; 
        localStorage.setItem('files_sort', currentSort); 
        renderFiles(currentFiles); 
    });
    document.getElementById('filesSearch').addEventListener('input', e => renderFiles(currentFiles, e.target.value));
    document.getElementById('btnCreateFolder').addEventListener('click', createFolder);

    // Hide context menu on click outside
    document.addEventListener('click', e => {
        if (!e.target.closest('#ctxMenu')) closeCtx();
    });

    // Move confirm
    document.getElementById('btnConfirmMove').addEventListener('click', confirmMove);

    // Share create
    document.getElementById('btnCreateShare').addEventListener('click', createShareLink);
    document.getElementById('btnCopyShareUrl').addEventListener('click', () => {
        const inp = document.getElementById('shareResultUrl');
        inp.select();
        document.execCommand('copy');
        toast('คัดลอกลิงก์เรียบร้อยแล้ว');
    });

    // Category filter chips click listeners
    document.querySelectorAll('.filter-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentCategory = btn.dataset.category;
            renderFiles(currentFiles, document.getElementById('filesSearch').value);
        });
    });

    // Batch actions buttons
    document.getElementById('btnBatchDelete').addEventListener('click', deleteFilesBatch);
    document.getElementById('btnBatchMove').addEventListener('click', openBatchMoveDialog);
    document.getElementById('btnBatchClear').addEventListener('click', clearSelection);

    // Initialize full page drag drop overlay
    initFullPageDragDrop();

    navigate(currentParentId);
});

// ---- View toggle ----
function setView(v, save = true) {
    currentView = v;
    if (save) localStorage.setItem('files_view', v);
    const grid = document.getElementById('filesGrid');
    grid.classList.toggle('list-view', v === 'list');
    document.getElementById('btnGridView').classList.toggle('btn-primary', v === 'grid');
    document.getElementById('btnGridView').classList.toggle('btn-ghost',   v !== 'grid');
    document.getElementById('btnListView').classList.toggle('btn-primary', v === 'list');
    document.getElementById('btnListView').classList.toggle('btn-ghost',   v !== 'list');
}

// ---- Navigate ----
async function navigate(parentId) {
    currentParentId = parentId;
    clearSelection();
    const url = BASE_URL + '/api/files' + (parentId !== null ? '?parent_id=' + parentId : '');
    const grid = document.getElementById('filesGrid');
    grid.innerHTML = '<div class="files-loading"><div class="spinner"></div></div>';
    try {
        const data = await apiFetch(url);
        currentFiles = data.files || [];
        updateStorageStats(currentFiles);
        renderBreadcrumbs(data.breadcrumbs || []);
        renderFiles(currentFiles);
    } catch {
        toast('โหลดไฟล์ไม่สำเร็จ', 'danger');
    }
}

// ---- Breadcrumbs ----
function renderBreadcrumbs(crumbs) {
    const el = document.getElementById('breadcrumb');
    let html = '<span class="breadcrumb-item' + (crumbs.length === 0 ? ' active' : '') + '" data-navigate="root">' +
               '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg> หน้าหลัก</span>';
    crumbs.forEach((c, i) => {
        html += '<span class="breadcrumb-sep">/</span>';
        const isLast = i === crumbs.length - 1;
        html += `<span class="breadcrumb-item${isLast ? ' active' : ''}" ${!isLast ? `data-navigate="${c.id}"` : ''}>${escHtml(c.name)}</span>`;
    });
    el.innerHTML = html;
    el.querySelectorAll('[data-navigate]').forEach(s => {
        s.addEventListener('click', () => {
            const v = s.dataset.navigate;
            navigate(v === 'root' ? null : parseInt(v));
        });
    });
}

// ---- Sort ----
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

// ---- File Category Resolver ----
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

// ---- Update Storage statistics widget ----
function updateStorageStats(files) {
    let foldersCount = 0;
    let filesCount = 0;
    let totalSizeBytes = 0;
    
    // Category sums
    const catSums = {
        image: { count: 0, size: 0, label: 'รูปภาพ' },
        document: { count: 0, size: 0, label: 'เอกสาร' },
        media: { count: 0, size: 0, label: 'สื่อมีเดีย' },
        archive: { count: 0, size: 0, label: 'ไฟล์บีบอัด' }
    };
    
    files.forEach(f => {
        if (f.type === 'folder') {
            foldersCount++;
        } else {
            filesCount++;
            const bytes = parseInt(f.file_size) || 0;
            totalSizeBytes += bytes;
            
            const cat = getFileCategory(f);
            if (catSums[cat]) {
                catSums[cat].count++;
                catSums[cat].size += bytes;
            }
        }
    });
    
    document.getElementById('folderCountText').textContent = foldersCount + ' โฟลเดอร์';
    document.getElementById('fileCountText').textContent = filesCount + ' ไฟล์';
    
    const sizeStr = formatBytes(totalSizeBytes) || '0 B';
    const limitBytes = 100 * 1024 * 1024; // 100 MB reference limit
    const percent = Math.min(100, Math.round((totalSizeBytes / limitBytes) * 100));
    
    document.getElementById('storageUsageText').textContent = `ใช้ไป ${sizeStr} จาก 100 MB`;
    document.getElementById('storageProgressBar').style.width = percent + '%';
    
    const badgeContainer = document.getElementById('storageQuickStats');
    badgeContainer.innerHTML = '';
    
    Object.keys(catSums).forEach(key => {
        const item = catSums[key];
        if (item.count > 0) {
            const badge = document.createElement('div');
            badge.className = 'quick-stat-badge';
            
            let iconSvg = '';
            if (key === 'image') iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
            else if (key === 'document') iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
            else if (key === 'media') iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>';
            else if (key === 'archive') iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>';
            
            badge.innerHTML = `${iconSvg} <span>${item.label}: ${item.count} (${formatBytes(item.size)})</span>`;
            badgeContainer.appendChild(badge);
        }
    });
}

// ---- Render ----
function renderFiles(files, search = '') {
    const grid = document.getElementById('filesGrid');
    let list = sortFiles(files);
    
    // Category filter
    if (currentCategory !== 'all') {
        list = list.filter(f => getFileCategory(f) === currentCategory);
    }

    if (search.trim()) {
        const q = search.trim().toLowerCase();
        list = list.filter(f => f.name.toLowerCase().includes(q));
    }

    if (!list.length) {
        grid.innerHTML = `<div class="files-empty">` +
            `<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>` +
            `<span>${search ? 'ไม่พบไฟล์ที่ค้นหา' : 'โฟลเดอร์นี้ไม่มีไฟล์สำหรับหมวดหมู่ที่เลือก'}</span>` +
            `</div>`;
        return;
    }

    grid.innerHTML = list.map(f => fileCard(f)).join('');

    grid.querySelectorAll('[data-file-id]').forEach(el => {
        const id   = parseInt(el.dataset.fileId);
        const type = el.dataset.fileType;

        // Double click actions
        el.addEventListener('dblclick', () => {
            if (type === 'folder') {
                navigate(id);
            } else {
                const f = getFileObj(id);
                if (f && f.mime_type && f.mime_type.startsWith('image/')) {
                    openPreview(f);
                } else {
                    downloadFile(id);
                }
            }
        });

        // Single click to select
        el.addEventListener('click', e => {
            if (e.target.closest('.file-more-btn') || e.target.closest('.file-checkbox-wrap')) {
                return;
            }
            const chk = el.querySelector('.file-checkbox');
            if (chk) {
                toggleSelectFile(id, !chk.checked);
            }
        });

        // Checkbox change
        el.querySelector('.file-checkbox')?.addEventListener('change', e => {
            toggleSelectFile(id, e.target.checked);
        });

        el.addEventListener('contextmenu', e => { e.preventDefault(); openCtx(e, getFileObj(id)); });
        el.querySelector('.file-more-btn')?.addEventListener('click', e => { e.stopPropagation(); openCtx(e, getFileObj(id)); });

        // Folder drop zone
        if (type === 'folder') {
            el.addEventListener('dragover', e => { e.preventDefault(); el.classList.add('drag-target'); });
            el.addEventListener('dragleave', () => el.classList.remove('drag-target'));
            el.addEventListener('drop', e => {
                e.preventDefault();
                el.classList.remove('drag-target');
                const draggedId = parseInt(e.dataTransfer.getData('file-id'));
                if (draggedId && draggedId !== id) moveFileTo(draggedId, id);
            });
        }

        // Draggable
        el.setAttribute('draggable', 'true');
        el.addEventListener('dragstart', e => e.dataTransfer.setData('file-id', id));
    });

    updateBatchToolbar();
}

function getFileObj(id) { return currentFiles.find(f => parseInt(f.id) === id); }

function fileCard(f) {
    const icon    = getFileIconSvg(f.type, f.mime_type);
    const sizeStr = f.file_size ? formatBytes(f.file_size) : '';
    const dateStr = f.created_at ? new Date(f.created_at).toLocaleDateString('th-TH') : '';
    const isChecked = selectedFileIds.includes(parseInt(f.id));

    return `<div class="file-item ${isChecked ? 'selected' : ''}" data-file-id="${f.id}" data-file-type="${f.type}" title="${escHtml(f.name)}">
        <div class="file-checkbox-wrap">
            <input type="checkbox" class="file-checkbox" data-chk-id="${f.id}" ${isChecked ? 'checked' : ''}>
        </div>
        <button class="file-more-btn" title="ตัวเลือก" aria-label="ตัวเลือก">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
        </button>
        <div class="file-icon">${icon}</div>
        <div class="file-name">${escHtml(f.name)}</div>
        <div class="file-meta">
            ${sizeStr ? `<span>${sizeStr}</span>` : ''}
            ${dateStr ? `<span>${dateStr}</span>` : ''}
        </div>
    </div>`;
}

// ---- SVG Icon Set ----
function getFileIconSvg(type, mime) {
    const s = (path, color='currentColor') =>
        `<svg viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg">${path}</svg>`;

    if (type === 'folder')
        return s('<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>', '#f59e0b');

    if (!mime) return s('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>');

    if (mime.startsWith('image/'))
        return s('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>', '#10b981');

    if (mime.startsWith('video/'))
        return s('<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>', '#6366f1');

    if (mime.startsWith('audio/'))
        return s('<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>', '#ec4899');

    if (mime.includes('pdf'))
        return s('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>', '#ef4444');

    if (mime.includes('word') || mime.includes('document'))
        return s('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="11" y2="17"/>', '#2563eb');

    if (mime.includes('excel') || mime.includes('spreadsheet'))
        return s('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/>', '#16a34a');

    if (mime.includes('zip') || mime.includes('compressed') || mime.includes('x-tar'))
        return s('<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>', '#d97706');

    if (mime.includes('text/'))
        return s('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/><line x1="9" y1="9" x2="15" y2="9"/>');

    return s('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>');
}

// ---- Multi-Select handlers ----
function toggleSelectFile(id, checked) {
    id = parseInt(id);
    const idx = selectedFileIds.indexOf(id);
    if (checked) {
        if (idx === -1) selectedFileIds.push(id);
    } else {
        if (idx !== -1) selectedFileIds.splice(idx, 1);
    }

    const el = document.querySelector(`.file-item[data-file-id="${id}"]`);
    if (el) {
        el.classList.toggle('selected', checked);
        const chk = el.querySelector('.file-checkbox');
        if (chk) chk.checked = checked;
    }

    updateBatchToolbar();
}

function clearSelection() {
    selectedFileIds = [];
    updateBatchToolbar();
    document.querySelectorAll('.file-item').forEach(el => el.classList.remove('selected'));
    document.querySelectorAll('.file-checkbox').forEach(el => el.checked = false);
}

function updateBatchToolbar() {
    const tb = document.getElementById('batchToolbar');
    const badge = document.getElementById('batchCountBadge');
    const grid = document.getElementById('filesGrid');

    if (selectedFileIds.length > 0) {
        tb.classList.add('active');
        badge.textContent = selectedFileIds.length;
        grid.classList.add('multi-select-active');
    } else {
        tb.classList.remove('active');
        grid.classList.remove('multi-select-active');
    }
}

// ---- Context menu ----
function openCtx(e, f) {
    if (!f) return;
    ctxTarget = f;
    const menu = document.getElementById('ctxMenu');
    document.querySelector('#ctxOpen span').textContent = f.type === 'folder' ? 'เปิดโฟลเดอร์' : 'ดาวน์โหลด';
    document.getElementById('ctxPreview').style.display = (f.type === 'file' && f.mime_type && f.mime_type.startsWith('image/')) ? '' : 'none';

    menu.style.display = 'block';
    const x = Math.min(e.clientX, window.innerWidth - menu.offsetWidth - 8);
    const y = Math.min(e.clientY, window.innerHeight - menu.offsetHeight - 8);
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';

    document.getElementById('ctxOpen').onclick   = () => { closeCtx(); f.type === 'folder' ? navigate(f.id) : downloadFile(f.id); };
    document.getElementById('ctxPreview').onclick = () => { closeCtx(); openPreview(f); };
    document.getElementById('ctxRename').onclick  = () => { closeCtx(); renameFile(f.id, f.name); };
    document.getElementById('ctxMove').onclick    = () => { closeCtx(); openMoveDialog(f.id); };
    document.getElementById('ctxShare').onclick   = () => { closeCtx(); openShareQuick(f.id); };
    document.getElementById('ctxDelete').onclick  = () => { closeCtx(); deleteFile(f.id); };
}
function closeCtx() { document.getElementById('ctxMenu').style.display = 'none'; ctxTarget = null; }

// ---- Image preview ----
function openPreview(f) {
    document.getElementById('previewImg').src = BASE_URL + '/api/files/' + f.id + '/download';
    document.getElementById('previewCaption').textContent = f.name;
    document.getElementById('previewOverlay').style.display = 'flex';
}
function closePreview() { document.getElementById('previewOverlay').style.display = 'none'; document.getElementById('previewImg').src = ''; }

// ---- Move dialog ----
async function openMoveDialog(fileId) {
    if (fileId !== null) {
        moveTargetId = fileId;
        isBatchMoving = false;
    } else {
        isBatchMoving = true;
    }

    document.getElementById('moveOverlay').style.display = 'flex';
    const list = document.getElementById('moveFolderList');
    list.innerHTML = '<div class="text-muted text-sm">กำลังโหลด...</div>';
    try {
        const data = await apiFetch(BASE_URL + '/api/files/folders');
        let folders = data.folders || [];
        
        if (isBatchMoving) {
            // Remove all selected folders from move destination options to prevent recursive loops
            folders = folders.filter(f => !selectedFileIds.includes(parseInt(f.id)));
        } else {
            folders = folders.filter(f => parseInt(f.id) !== fileId);
        }

        let html = buildFolderTree(folders, null, 0);
        list.innerHTML = html || '<div class="text-muted text-sm">ไม่มีโฟลเดอร์อื่น</div>';
        list.querySelectorAll('.move-folder-item').forEach(el => {
            el.addEventListener('click', () => {
                list.querySelectorAll('.move-folder-item').forEach(x => x.classList.remove('active'));
                el.classList.add('active');
                document.getElementById('btnConfirmMove').dataset.targetId = el.dataset.folderId;
            });
        });
        
        // Root option
        const rootEl = document.createElement('div');
        rootEl.className = 'move-folder-item';
        rootEl.dataset.folderId = '';
        rootEl.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg> หน้าหลัก (Root)`;
        rootEl.addEventListener('click', () => {
            list.querySelectorAll('.move-folder-item').forEach(x => x.classList.remove('active'));
            rootEl.classList.add('active');
            document.getElementById('btnConfirmMove').dataset.targetId = '';
        });
        list.prepend(rootEl);
    } catch { list.innerHTML = '<div class="text-muted text-sm">โหลดไม่สำเร็จ</div>'; }
}

function buildFolderTree(folders, parentId, depth) {
    const icon = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>`;
    return folders
        .filter(f => (f.parent_id == null ? null : parseInt(f.parent_id)) === parentId)
        .map(f => {
            const pad = depth * 16;
            const children = buildFolderTree(folders, parseInt(f.id), depth + 1);
            return `<div class="move-folder-item" data-folder-id="${f.id}" style="padding-left:${12 + pad}px">${icon} ${escHtml(f.name)}</div>${children}`;
        }).join('');
}

function closeMoveDialog() { document.getElementById('moveOverlay').style.display = 'none'; moveTargetId = null; isBatchMoving = false; }

async function confirmMove() {
    const btn = document.getElementById('btnConfirmMove');
    const rawTarget = btn.dataset.targetId;
    const targetId = (rawTarget === '' || rawTarget === undefined) ? null : parseInt(rawTarget);

    if (isBatchMoving) {
        if (selectedFileIds.length === 0) return;
        toast('กำลังย้ายข้อมูลทั้งหมด...');
        try {
            await Promise.all(selectedFileIds.map(id => {
                return apiFetch(BASE_URL + '/api/files/' + id + '/move', {
                    method: 'PUT',
                    body: JSON.stringify({ parent_id: targetId })
                });
            }));
            closeMoveDialog();
            clearSelection();
            navigate(currentParentId);
            toast('ย้ายข้อมูลเรียบร้อยแล้ว');
        } catch (err) {
            toast('ย้ายข้อมูลบางรายการไม่สำเร็จ: ' + err.message, 'danger');
        }
    } else {
        if (moveTargetId === null) return;
        try {
            await apiFetch(BASE_URL + '/api/files/' + moveTargetId + '/move', {
                method: 'PUT',
                body: JSON.stringify({ parent_id: targetId })
            });
            closeMoveDialog();
            navigate(currentParentId);
            toast('ย้ายข้อมูลเรียบร้อยแล้ว');
        } catch (err) { toast(err.message || 'ย้ายไม่สำเร็จ', 'danger'); }
    }
}

// ---- Share quick (from file context menu) ----
function openShareQuick(fileId) {
    shareTargetId = fileId;
    const f = getFileObj(fileId);
    const titleEl = document.querySelector('#shareQuickOverlay .modal-title');
    if (titleEl && f) titleEl.textContent = 'แชร์: ' + f.name;
    document.getElementById('sqLabel').value = '';
    document.getElementById('sqPermission').value = 'view';
    document.getElementById('sqExpires').value = '';
    document.getElementById('shareResultBar').style.display = 'none';
    document.getElementById('shareQuickOverlay').style.display = 'flex';
}
function closeShareQuick() { document.getElementById('shareQuickOverlay').style.display = 'none'; shareTargetId = null; }

async function createShareLink() {
    if (!shareTargetId) return;
    const label      = document.getElementById('sqLabel').value.trim();
    const permission = document.getElementById('sqPermission').value;
    const expires    = document.getElementById('sqExpires').value;
    try {
        const res = await apiFetch(BASE_URL + '/api/shares', {
            method: 'POST',
            body: JSON.stringify({ file_id: shareTargetId, label, permission, expires_at: expires || null })
        });
        closeShareQuick();
        const bar = document.getElementById('shareResultBar');
        document.getElementById('shareResultUrl').value = res.link;
        bar.style.display = 'block';
        toast('สร้างลิงก์แชร์แล้ว');
    } catch (err) { toast(err.message || 'สร้างไม่สำเร็จ', 'danger'); }
}

// ---- Upload ----
function handleDragOver(e) { e.preventDefault(); document.getElementById('uploadZone').classList.add('drag-over'); }
function handleDragLeave() { document.getElementById('uploadZone').classList.remove('drag-over'); }
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('uploadZone').classList.remove('drag-over');
    if (e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
}

// Fullpage drag-and-drop listener system
function initFullPageDragDrop() {
    let dragCounter = 0;
    const overlay = document.getElementById('fullDropOverlay');

    window.addEventListener('dragenter', e => {
        e.preventDefault();
        dragCounter++;
        if (dragCounter === 1) {
            overlay.classList.add('active');
        }
    });

    window.addEventListener('dragleave', e => {
        e.preventDefault();
        dragCounter--;
        if (dragCounter === 0) {
            overlay.classList.remove('active');
        }
    });

    window.addEventListener('dragover', e => {
        e.preventDefault();
    });

    window.addEventListener('drop', e => {
        e.preventDefault();
        dragCounter = 0;
        overlay.classList.remove('active');
        if (e.dataTransfer.files.length) {
            uploadFiles(e.dataTransfer.files);
        }
    });
}

async function uploadFiles(fileList) {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrf = csrfMeta ? csrfMeta.content : '';
    const queue = document.getElementById('uploadQueue');
    queue.style.display = 'flex';
    queue.innerHTML = '';

    const items = Array.from(fileList).map(file => {
        const itemEl = document.createElement('div');
        itemEl.className = 'upload-queue-item';
        itemEl.innerHTML = `<span class="upload-queue-name">${escHtml(file.name)}</span>
            <div class="upload-queue-bar-wrap"><div class="upload-queue-bar"></div></div>
            <span class="upload-queue-status">รอ...</span>`;
        queue.appendChild(itemEl);
        return { file, el: itemEl };
    });

    let anyOk = false;
    for (const { file, el } of items) {
        const bar    = el.querySelector('.upload-queue-bar');
        const status = el.querySelector('.upload-queue-status');
        status.textContent = 'กำลังอัปโหลด';

        const fd = new FormData();
        fd.append('file', file);
        fd.append('_csrf', csrf);
        if (currentParentId !== null) fd.append('parent_id', currentParentId);

        await new Promise(resolve => {
            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = e => {
                if (e.lengthComputable) bar.style.width = Math.round(e.loaded / e.total * 100) + '%';
            };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    bar.style.width = '100%';
                    status.textContent = 'สำเร็จ';
                    status.classList.add('done');
                    anyOk = true;
                } else {
                    let msg = 'ล้มเหลว';
                    try { msg = JSON.parse(xhr.responseText).error || msg; } catch {}
                    status.textContent = msg;
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

    if (anyOk) { await navigate(currentParentId); toast('อัปโหลดไฟล์เสร็จสมบูรณ์'); }
    setTimeout(() => { queue.style.display = 'none'; queue.innerHTML = ''; }, 3000);
}

// ---- Create folder ----
async function createFolder() {
    const { value: name } = await Swal.fire({
        title: 'สร้างโฟลเดอร์ใหม่',
        input: 'text',
        inputLabel: 'ชื่อโฟลเดอร์',
        inputPlaceholder: 'กรอกชื่อโฟลเดอร์ของคุณ',
        showCancelButton: true,
        confirmButtonText: 'สร้างโฟลเดอร์',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#1d1d1f',
        cancelButtonColor: '#6b7280',
        inputValidator: v => !v || !v.trim() ? 'กรุณากรอกชื่อโฟลเดอร์' : null,
    });
    if (!name || !name.trim()) return;
    try {
        await apiFetch(BASE_URL + '/api/files/folder', {
            method: 'POST',
            body: JSON.stringify({ name: name.trim(), parent_id: currentParentId })
        });
        navigate(currentParentId);
        toast('สร้างโฟลเดอร์เรียบร้อยแล้ว');
    } catch (err) { toast(err.message || 'สร้างไม่สำเร็จ', 'danger'); }
}

// ---- Rename ----
async function renameFile(id, oldName) {
    const { value: newName } = await Swal.fire({
        title: 'เปลี่ยนชื่อ',
        input: 'text',
        inputValue: oldName,
        showCancelButton: true,
        confirmButtonText: 'บันทึกชื่อใหม่',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#1d1d1f',
        cancelButtonColor: '#6b7280',
        inputValidator: v => !v || !v.trim() ? 'กรุณากรอกชื่อ' : null,
    });
    if (!newName || !newName.trim() || newName === oldName) return;
    await apiFetch(BASE_URL + '/api/files/' + id + '/rename', { method: 'PUT', body: JSON.stringify({ name: newName.trim() }) });
    navigate(currentParentId);
    toast('เปลี่ยนชื่อไฟล์เรียบร้อยแล้ว');
}

// ---- Delete ----
async function deleteFile(id) {
    const result = await Swal.fire({
        title: 'ยืนยันการลบ',
        text: 'คุณต้องการลบไฟล์หรือโฟลเดอร์นี้ใช่หรือไม่? การลบนี้จะไม่สามารถกู้คืนข้อมูลกลับมาได้',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบข้อมูล',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#e23636',
        cancelButtonColor: '#6b7280'
    });

    if (!result.isConfirmed) return;

    try {
        await apiFetch(BASE_URL + '/api/files/' + id, { method: 'DELETE' });
        navigate(currentParentId);
        toast('ลบข้อมูลเรียบร้อยแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ', 'danger');
    }
}

// ---- Batch Actions operations ----
async function deleteFilesBatch() {
    if (selectedFileIds.length === 0) return;
    const count = selectedFileIds.length;

    const result = await Swal.fire({
        title: 'ยืนยันการลบลบกลุ่มรายการ',
        text: `คุณต้องการลบไฟล์และโฟลเดอร์ที่เลือกทั้งหมด ${count} รายการใช่หรือไม่? การลบนี้จะไม่สามารถกู้คืนข้อมูลกลับมาได้`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบกลุ่มรายการ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#e23636',
        cancelButtonColor: '#6b7280'
    });

    if (!result.isConfirmed) return;

    toast('กำลังลบข้อมูลกลุ่มรายการ...');
    try {
        await Promise.all(selectedFileIds.map(id => {
            return apiFetch(BASE_URL + '/api/files/' + id, { method: 'DELETE' });
        }));
        
        toast(`ลบข้อมูลเรียบร้อยแล้วทั้งหมด ${count} รายการ`);
        clearSelection();
        navigate(currentParentId);
    } catch (err) {
        toast('ลบข้อมูลบางรายการไม่สำเร็จ: ' + err.message, 'danger');
    }
}

function openBatchMoveDialog() {
    if (selectedFileIds.length === 0) return;
    openMoveDialog(null);
}

// ---- Download ----
function downloadFile(id) { window.location.href = BASE_URL + '/api/files/' + id + '/download'; }

// ---- Move (from drag-and-drop) ----
async function moveFileTo(fileId, folderId) {
    try {
        await apiFetch(BASE_URL + '/api/files/' + fileId + '/move', {
            method: 'PUT',
            body: JSON.stringify({ parent_id: folderId })
        });
        navigate(currentParentId);
        toast('ย้ายตำแหน่งข้อมูลเรียบร้อยแล้ว');
    } catch (err) { toast(err.message || 'ย้ายไม่สำเร็จ', 'danger'); }
}

// ---- Helpers ----
function formatBytes(bytes) {
    if (!bytes) return '0 B';
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
    if (bytes >= 1048576)    return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024)       return (bytes / 1024).toFixed(0) + ' KB';
    return bytes + ' B';
}

function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
