/* =====================================================
   notes.js — Notes list + Block editor
===================================================== */

/* =====================================================
   LIST PAGE
===================================================== */
let currentTagId   = 0;
let currentSearch  = '';

if (document.getElementById('notesGrid')) {
    document.addEventListener('DOMContentLoaded', function () {
        loadNotes();
    });
}

async function loadNotes() {
    const grid = document.getElementById('notesGrid');
    if (!grid) return;

    grid.innerHTML = '<div class="empty-state"><div class="spinner"></div></div>';

    try {
        const params = new URLSearchParams();
        if (currentSearch) params.set('search', currentSearch);
        if (currentTagId)  params.set('tag', currentTagId);

        const data = await apiFetch(BASE_URL + '/api/notes?' + params.toString());
        renderNotesList(data.notes || []);
    } catch {
        grid.innerHTML = '<div class="empty-state"><span class="text-muted">โหลดไม่สำเร็จ</span></div>';
    }
}

function renderNotesList(notes) {
    const grid = document.getElementById('notesGrid');
    if (!grid) return;

    if (!notes.length) {
        grid.innerHTML = '<div class="empty-state"><div class="empty-state-title">ยังไม่มีโน้ต</div><div class="empty-state-text">กดปุ่ม "โน้ตใหม่" เพื่อเริ่มสร้าง</div></div>';
        return;
    }

    grid.innerHTML = notes.map(n => {
        const tagsArr = n.tags_list ? n.tags_list.split(',') : [];
        const tagsHtml = tagsArr.length 
            ? `<div class="note-card-tags">${tagsArr.map(t => `<span class="note-card-tag">${escHtml(t)}</span>`).join('')}</div>` 
            : '';
            
        const isPinned = !!n.pinned;
        const pinTitle = isPinned ? 'เลิกปักหมุด' : 'ปักหมุด';
        const bookmarkFill = isPinned ? 'currentColor' : 'none';

        return `
            <div class="note-card ${isPinned ? 'pinned' : ''}" onclick="window.location='${BASE_URL}/notes/${n.id}'">
                <div class="note-card-actions">
                    <button class="note-card-btn ${isPinned ? 'active' : ''}" onclick="event.stopPropagation();togglePin(${n.id}, ${n.pinned})" title="${pinTitle}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="${bookmarkFill}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                    </button>
                    <button class="note-card-btn danger" onclick="event.stopPropagation();deleteNote(${n.id})" title="ลบ">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                    </button>
                </div>
                <div class="note-card-title">${escHtml(n.title)} ${n.is_encrypted ? '<span class="note-lock-icon" title="โน้ตเข้ารหัส" style="display: inline-flex; align-items: center; color: var(--color-muted);"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-left: 4px; opacity: 0.8;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></span>' : ''}</div>
                ${n.preview && !n.is_encrypted ? `<div class="note-card-preview">${escHtml(n.preview.substring(0, 100))}</div>` : ''}
                <div class="note-card-footer">
                    <span class="note-card-date">${formatDate(n.updated_at ? n.updated_at.split(' ')[0] : '')}</span>
                    ${tagsHtml}
                </div>
            </div>
        `;
    }).join('');
}

const searchNotes = debounce(function(val) {
    currentSearch = val;
    loadNotes();
}, 400);

function filterByTag(tagId, el) {
    currentTagId = tagId;
    document.querySelectorAll('#tagList .tag').forEach(t => t.classList.remove('active'));
    if (el) el.classList.add('active');
    loadNotes();
}

function openCreateNote(encrypted) {
    document.getElementById('createNoteEncrypted').value = encrypted ? '1' : '0';
    document.getElementById('createNoteModalTitle').textContent = encrypted ? 'โน้ตเข้ารหัส' : 'สร้างโน้ตใหม่';
    document.getElementById('createNoteTitle').value = '';
    document.getElementById('createNotePw').value = '';
    document.getElementById('createNotePwGroup').style.display = encrypted ? 'block' : 'none';
    openModal('createNoteModal');
}

async function submitCreateNote() {
    const title     = document.getElementById('createNoteTitle').value.trim();
    const encrypted = document.getElementById('createNoteEncrypted').value === '1';
    const password  = document.getElementById('createNotePw').value;

    if (encrypted && !password) { toast('กรุณากรอกรหัสผ่าน', 'danger'); return; }

    try {
        const data = await apiFetch(BASE_URL + '/api/notes', {
            method: 'POST',
            body: JSON.stringify({ title: title || 'ไม่มีชื่อ', is_encrypted: encrypted, password })
        });
        closeModal('createNoteModal');
        window.location.href = BASE_URL + '/notes/' + data.note.id;
    } catch (err) {
        toast(err.message || 'สร้างไม่สำเร็จ', 'danger');
    }
}

async function togglePin(id, isPinned) {
    await apiFetch(BASE_URL + '/api/notes/' + id, {
        method: 'PUT',
        body: JSON.stringify({ pinned: isPinned ? 0 : 1 })
    });
    loadNotes();
}

async function deleteNote(id) {
    if (!await confirmAction('ต้องการลบโน้ตนี้?', 'ลบ')) return;
    await apiFetch(BASE_URL + '/api/notes/' + id, { method: 'DELETE' });
    loadNotes();
    toast('ลบแล้ว');
}

/* =====================================================
   EDITOR PAGE
===================================================== */
let noteId      = null;
let noteEncrypt = false;
let notePass    = '';
let noteTags    = [];
let blocks      = [];

if (document.getElementById('noteEditor')) {
    document.addEventListener('DOMContentLoaded', function () {
        const el = document.getElementById('noteEditor');
        noteId      = parseInt(el.dataset.noteId);
        noteEncrypt = el.dataset.encrypted === '1';
        noteTags    = (window.NOTE_TAGS || []).map(t => t.name);

        if (!noteEncrypt) {
            loadBlocks();
        }

        // Init Sortable for blocks
        const container = document.getElementById('blocksContainer');
        if (container && typeof Sortable !== 'undefined') {
            Sortable.create(container, {
                animation: 150,
                handle: '.block-drag-handle',
                ghostClass: 'sortable-ghost',
                delay: 120, // Smooth touch delay to avoid scroll locking
                delayOnTouchOnly: true, // Maintain instant dragging on desktop
                touchStartThreshold: 7, // Tolerates tiny finger tremors before starting drag
                onEnd: saveBlockOrder
            });
        }

        // Add block toggle
        document.getElementById('addBlockBtn').addEventListener('click', function() {
            const menu = document.getElementById('blockTypeMenu');
            menu.style.display = menu.style.display === 'none' ? 'flex' : 'none';
        });
    });
}

async function loadBlocks() {
    try {
        const data = await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks');
        blocks = data.blocks || [];
        renderBlocks();
    } catch {}
}

async function unlockNote() {
    notePass = document.getElementById('notePassword').value;
    if (!notePass) { toast('กรุณากรอกรหัสผ่าน', 'danger'); return; }

    try {
        const data = await apiFetch(BASE_URL + '/api/notes/' + noteId + '/verify', {
            method: 'POST',
            body: JSON.stringify({ password: notePass })
        });
        blocks = data.blocks || [];
        document.getElementById('encryptedPrompt').style.display = 'none';
        document.getElementById('editorBody').style.display = 'block';
        renderBlocks();
    } catch {
        toast('รหัสผ่านไม่ถูกต้อง', 'danger');
    }
}

function renderBlocks() {
    const container = document.getElementById('blocksContainer');
    if (!container) return;

    container.innerHTML = '';
    blocks.forEach(b => container.appendChild(createBlockEl(b)));
}

function createBlockEl(block) {
    const div = document.createElement('div');
    div.className = 'block-item';
    div.dataset.id   = block.id;
    div.dataset.type = block.type;

    const handle = document.createElement('span');
    handle.className = 'block-drag-handle';
    handle.innerHTML = `<svg width="12" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.35;"><circle cx="9" cy="5" r="1.2"></circle><circle cx="9" cy="12" r="1.2"></circle><circle cx="9" cy="19" r="1.2"></circle><circle cx="15" cy="5" r="1.2"></circle><circle cx="15" cy="12" r="1.2"></circle><circle cx="15" cy="19" r="1.2"></circle></svg>`;

    const content = document.createElement('div');
    content.className = 'block-content';
    content.appendChild(renderBlockContent(block));

    const controls = document.createElement('div');
    controls.className = 'block-controls';

    const typeSelect = document.createElement('select');
    typeSelect.className = 'block-type-select';
    typeSelect.innerHTML = '<option value="text">ข้อความ</option><option value="link">ลิงก์</option><option value="checklist">Checklist</option>';
    typeSelect.value = block.type;
    typeSelect.onchange = function() {
        block.type = this.value;
        div.dataset.type = this.value;
        content.innerHTML = '';
        content.appendChild(renderBlockContent({ ...block, content: '', type: this.value }));
    };

    const delBtn = document.createElement('button');
    delBtn.className = 'block-ctrl-btn danger';
    delBtn.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>`;
    delBtn.title = 'ลบบล็อก';
    delBtn.onclick = () => deleteBlock(block.id, div);

    controls.appendChild(typeSelect);
    controls.appendChild(delBtn);

    div.appendChild(handle);
    div.appendChild(content);
    div.appendChild(controls);
    return div;
}

function renderBlockContent(block) {
    const wrap = document.createElement('div');

    if (block.type === 'text') {
        const editWrap = document.createElement('div');
        editWrap.className = 'block-text-edit-wrap';
        editWrap.style.display = block.content ? 'none' : 'block';

        const ta = document.createElement('textarea');
        ta.className = 'block-textarea';
        ta.value = block.content || '';
        ta.rows = 1;
        ta.placeholder = 'พิมพ์ข้อความ...';
        autoResize(ta);

        editWrap.appendChild(ta);

        const previewWrap = document.createElement('div');
        previewWrap.className = 'block-text-preview';
        previewWrap.style.display = block.content ? 'block' : 'none';
        
        function updatePreview() {
            previewWrap.textContent = block.content || '';
        }
        
        updatePreview();

        ta.oninput = function() {
            autoResize(this);
            block.content = this.value;
            debouncedSaveBlock(block.id, 'text', this.value);
        };

        ta.onblur = function() {
            if (this.value.trim()) {
                updatePreview();
                editWrap.style.display = 'none';
                previewWrap.style.display = 'block';
            }
        };

        previewWrap.onclick = function() {
            previewWrap.style.display = 'none';
            editWrap.style.display = 'block';
            ta.focus();
            autoResize(ta);
        };

        wrap.appendChild(editWrap);
        wrap.appendChild(previewWrap);

    } else if (block.type === 'link') {
        let linkData = {};
        try { linkData = JSON.parse(block.content) || {}; } catch {}

        const editWrap = document.createElement('div');
        editWrap.className = 'block-link-wrap';
        editWrap.style.display = linkData.url ? 'none' : 'flex';
        editWrap.style.flexDirection = 'column';
        editWrap.style.gap = 'var(--space-2)';

        const urlInput = document.createElement('input');
        urlInput.className = 'block-link-url';
        urlInput.type = 'url';
        urlInput.placeholder = 'URL (เช่น https://example.com)...';
        urlInput.value = linkData.url || '';

        const labelInput = document.createElement('input');
        labelInput.className = 'block-link-label';
        labelInput.type = 'text';
        labelInput.placeholder = 'ชื่อลิงก์ (ไม่บังคับ)';
        labelInput.value = linkData.label || '';

        const saveBtn = document.createElement('button');
        saveBtn.className = 'btn btn-primary btn-sm';
        saveBtn.style.alignSelf = 'flex-start';
        saveBtn.style.fontSize = '0.75rem';
        saveBtn.style.padding = '4px 12px';
        saveBtn.style.marginTop = '4px';
        saveBtn.textContent = 'เสร็จสิ้น';

        editWrap.appendChild(urlInput);
        editWrap.appendChild(labelInput);
        editWrap.appendChild(saveBtn);

        const previewWrap = document.createElement('div');
        previewWrap.className = 'block-link-preview-wrap';
        previewWrap.style.display = linkData.url ? 'block' : 'none';

        function updatePreview() {
            previewWrap.innerHTML = '';
            if (!linkData.url) return;

            let targetUrl = linkData.url.trim();
            if (!/^https?:\/\//i.test(targetUrl)) {
                targetUrl = 'https://' + targetUrl;
            }

            const linkDisplay = document.createElement('a');
            linkDisplay.className = 'block-link-display';
            linkDisplay.href = targetUrl;
            linkDisplay.target = '_blank';
            linkDisplay.rel = 'noopener noreferrer';
            
            linkDisplay.innerHTML = `
                <span class="block-link-icon" style="margin-right: var(--space-2); display: inline-flex; align-items: center; justify-content: center; color: var(--color-muted);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                </span>
                <div style="display: flex; flex-direction: column; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; text-align: left;">
                    <strong class="link-title" style="font-size: 0.9rem; color: var(--color-text); font-weight: 600;">${escHtml(linkData.label || 'เปิดลิงก์')}</strong>
                    <span class="link-url-sub" style="font-size: 0.75rem; color: var(--color-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; display: block;">${escHtml(linkData.url)}</span>
                </div>
                <span style="color: var(--color-muted); font-size: 0.72rem; display: flex; align-items: center; gap: 4px; padding-left: 8px; flex-shrink: 0; font-weight: 500;">
                    เปิดลิงก์ 
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline></svg>
                </span>
            `;

            const editBtn = document.createElement('button');
            editBtn.className = 'btn btn-ghost btn-sm';
            editBtn.style.marginTop = 'var(--space-2)';
            editBtn.style.fontSize = '0.72rem';
            editBtn.style.padding = '3px 8px';
            editBtn.innerHTML = `
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle; display: inline-block;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4z"></path></svg>
                แก้ไขลิงก์
            `;
            editBtn.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                previewWrap.style.display = 'none';
                editWrap.style.display = 'flex';
                urlInput.focus();
            };

            previewWrap.appendChild(linkDisplay);
            previewWrap.appendChild(editBtn);
        }

        updatePreview();

        function saveLink() {
            const url = urlInput.value.trim();
            const label = labelInput.value.trim();
            linkData = { url, label };
            block.content = JSON.stringify(linkData);
            debouncedSaveBlock(block.id, 'link', block.content);
        }

        urlInput.oninput = saveLink;
        labelInput.oninput = saveLink;

        function commitLink() {
            saveLink();
            const url = urlInput.value.trim();
            if (url) {
                updatePreview();
                editWrap.style.display = 'none';
                previewWrap.style.display = 'block';
            } else {
                toast('กรุณากรอก URL ลิงก์', 'warning');
            }
        }

        saveBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            commitLink();
        };

        urlInput.onkeydown = function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                commitLink();
            }
        };
        labelInput.onkeydown = function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                commitLink();
            }
        };

        wrap.appendChild(editWrap);
        wrap.appendChild(previewWrap);

    } else if (block.type === 'checklist') {
        let items = [];
        try { items = JSON.parse(block.content) || []; } catch {}

        const listDiv = document.createElement('div');
        listDiv.className = 'block-checklist';

        function renderItems() {
            listDiv.innerHTML = '';
            items.forEach((item, i) => {
                const row = document.createElement('div');
                row.className = 'checklist-item-row';

                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = item.checked;
                cb.onchange = function() {
                    items[i].checked = this.checked;
                    inp.classList.toggle('checked-item', this.checked);
                    block.content = JSON.stringify(items);
                    debouncedSaveBlock(block.id, 'checklist', block.content);
                };

                const inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'checklist-item-input' + (item.checked ? ' checked-item' : '');
                inp.value = item.text || '';
                inp.placeholder = 'รายการ...';
                inp.oninput = function() {
                    items[i].text = this.value;
                    block.content = JSON.stringify(items);
                    debouncedSaveBlock(block.id, 'checklist', block.content);
                };
                inp.onkeydown = function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        items.splice(i + 1, 0, { text: '', checked: false });
                        renderItems();
                        const rows = listDiv.querySelectorAll('.checklist-item-input');
                        if (rows[i + 1]) rows[i + 1].focus();
                    }
                };

                const del = document.createElement('button');
                del.className = 'checklist-del';
                del.innerHTML = '&#10005;';
                del.onclick = function() {
                    items.splice(i, 1);
                    renderItems();
                    block.content = JSON.stringify(items);
                    debouncedSaveBlock(block.id, 'checklist', block.content);
                };

                row.appendChild(cb);
                row.appendChild(inp);
                row.appendChild(del);
                listDiv.appendChild(row);
            });

            const addBtn = document.createElement('button');
            addBtn.className = 'add-checklist-item';
            addBtn.textContent = '+ เพิ่มรายการ';
            addBtn.onclick = function() {
                items.push({ text: '', checked: false });
                renderItems();
                const rows = listDiv.querySelectorAll('.checklist-item-input');
                if (rows.length) rows[rows.length - 1].focus();
            };
            listDiv.appendChild(addBtn);
        }

        renderItems();
        wrap.appendChild(listDiv);
    }

    return wrap;
}

async function addBlock(type) {
    document.getElementById('blockTypeMenu').style.display = 'none';
    try {
        const body = { type, content: '' };
        if (noteEncrypt && notePass) body.password = notePass;

        const data = await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks', {
            method: 'POST',
            body: JSON.stringify(body)
        });
        const newBlock = { id: data.id, type, content: '' };
        blocks.push(newBlock);
        const container = document.getElementById('blocksContainer');
        if (container) container.appendChild(createBlockEl(newBlock));
    } catch (err) {
        toast('เพิ่มบล็อกไม่สำเร็จ', 'danger');
    }
}

async function deleteBlock(id, el) {
    if (!await confirmAction('ต้องการลบบล็อกนี้?', 'ลบ')) return;
    await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks/' + id, { method: 'DELETE' });
    blocks = blocks.filter(b => b.id !== id);
    el.remove();
}

const _blockTimers = {};
function debouncedSaveBlock(id, type, content) {
    clearTimeout(_blockTimers[id]);
    _blockTimers[id] = setTimeout(async function() {
        try {
            const body = { type, content };
            if (noteEncrypt && notePass) body.password = notePass;
            await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks/' + id, {
                method: 'PUT',
                body: JSON.stringify(body)
            });
            setSaveStatus('บันทึกแล้ว');
        } catch {
            setSaveStatus('บันทึกไม่สำเร็จ');
        }
    }, 800);
}

const debouncedSaveTitle = debounce(async function() {
    const title = document.getElementById('noteTitle')?.value?.trim();
    if (!title) return;
    try {
        await apiFetch(BASE_URL + '/api/notes/' + noteId, {
            method: 'PUT',
            body: JSON.stringify({ title })
        });
        setSaveStatus('บันทึกแล้ว');
    } catch {}
}, 800);

function setSaveStatus(msg) {
    const el = document.getElementById('saveStatus');
    if (el) { el.textContent = msg; setTimeout(() => { el.textContent = 'บันทึกอัตโนมัติ'; }, 2000); }
}

async function saveBlockOrder() {
    const container = document.getElementById('blocksContainer');
    if (!container) return;
    const items = [];
    container.querySelectorAll('.block-item[data-id]').forEach((el, idx) => {
        items.push({ id: parseInt(el.dataset.id), position: idx });
    });
    await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks/reorder', {
        method: 'POST',
        body: JSON.stringify({ items })
    });
}

/* --- Tags --- */
function handleTagInput(e) {
    if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
        e.preventDefault();
        submitTagInput(e.target);
    }
}

function handleTagOnInput(inputEl) {
    const btn = document.getElementById('tagAddBtn');
    if (!btn) return;
    const val = inputEl.value;
    
    // Show/hide the tiny add button
    if (val.trim().length > 0) {
        btn.style.display = 'inline-flex';
    } else {
        btn.style.display = 'none';
    }

    // Auto-commit on space, comma, semicolon
    if (val.endsWith(' ') || val.endsWith(',') || val.endsWith('，') || val.endsWith(';') || val.endsWith('；')) {
        submitTagInput(inputEl);
    }
}

function submitTagInput(inputEl) {
    const val = inputEl.value.trim().replace(/[,;，；]/g, '');
    if (val && !noteTags.includes(val)) {
        noteTags.push(val);
        renderTagWrap();
        saveTags();
    }
    inputEl.value = '';
    
    const btn = document.getElementById('tagAddBtn');
    if (btn) btn.style.display = 'none';
}

function removeTag(name) {
    noteTags = noteTags.filter(t => t !== name);
    renderTagWrap();
    saveTags();
}

function renderTagWrap() {
    const wrap = document.getElementById('tagWrap');
    if (!wrap) return;
    const existing = wrap.querySelectorAll('.tag');
    existing.forEach(el => el.remove());

    noteTags.forEach(name => {
        const span = document.createElement('span');
        span.className = 'tag active';
        span.dataset.tag = name;
        span.innerHTML = escHtml(name) + ` <button onclick="removeTag('${escHtml(name)}')" style="background:none;border:none;cursor:pointer;margin-left:2px;font-size:0.7rem">&#10005;</button>`;
        wrap.insertBefore(span, document.getElementById('tagInput'));
    });
}

async function saveTags() {
    await apiFetch(BASE_URL + '/api/notes/' + noteId, {
        method: 'PUT',
        body: JSON.stringify({ tags: noteTags })
    });
}

/* --- Helpers --- */
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}

function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
