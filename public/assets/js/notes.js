/* =====================================================
   notes.js — the notes list and the block editor

   The list is one script with the editor because both are "the notes" page
   family; each half starts only when its own markup is on the page.
===================================================== */

/* =====================================================
   LIST PAGE
===================================================== */
let currentTagId  = 0;
let currentSearch = '';

if (document.getElementById('notesGrid')) {
    document.addEventListener('DOMContentLoaded', loadNotes);
}

async function loadNotes() {
    const grid = document.getElementById('notesGrid');
    if (!grid) return;

    try {
        const params = new URLSearchParams();
        if (currentSearch) params.set('search', currentSearch);
        if (currentTagId)  params.set('tag', currentTagId);

        const data = await apiFetch(BASE_URL + '/api/notes?' + params.toString());
        grid.removeAttribute('aria-busy');
        renderNotesList(data.notes || []);
    } catch {
        grid.removeAttribute('aria-busy');
        document.getElementById('noteTally').textContent = 'โหลดโน้ตไม่ได้';
        grid.innerHTML = '<div class="alert alert-danger" role="alert">โหลดโน้ตไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="loadNotes">ลองอีกครั้ง</button></div>';
    }
}

function noteRow(n) {
    const title = escHtml(n.title);
    const tags = n.tags_list ? n.tags_list.split(',') : [];
    const pinned = !!Number(n.pinned);
    const date = formatDate(n.updated_at ? n.updated_at.split(' ')[0] : '');

    return '<li class="ruled-row note-row">'
        + '<span class="grow">'
        + '<a class="title" href="' + BASE_URL + '/notes/' + n.id + '">' + title
        + (Number(n.is_encrypted) ? '<span class="tag note-lock"><svg class="icon" aria-hidden="true"><use href="#i-lock"/></svg>เข้ารหัส</span>' : '') + '</a>'
        + (n.preview && !Number(n.is_encrypted) ? '<span class="meta">' + escHtml(n.preview.substring(0, 100)) + '</span>' : '')
        + (tags.length ? '<span class="note-rowtags">' + tags.map(t => '<span class="tag">' + escHtml(t) + '</span>').join('') + '</span>' : '')
        + '</span>'
        + '<span class="side">' + escHtml(date) + '</span>'
        + '<button type="button" class="icon-btn sm note-pin" data-act="togglePin" data-args="[' + n.id + ',' + (pinned ? 1 : 0) + ']" aria-pressed="' + pinned + '" aria-label="' + (pinned ? 'เลิกปักหมุด: ' : 'ปักหมุด: ') + title + '"><svg class="icon" aria-hidden="true"><use href="#i-pin"/></svg></button>'
        + '<button type="button" class="icon-btn sm" data-act="deleteNote" data-args="[' + n.id + ']" aria-label="ลบโน้ต: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
        + '</li>';
}

function renderNotesList(notes) {
    const grid = document.getElementById('notesGrid');
    const filtered = currentSearch || currentTagId;

    document.getElementById('noteTally').textContent = notes.length
        ? notes.length + ' โน้ต' + (filtered ? ' ที่ตรงกับตัวกรอง' : '')
        : (filtered ? 'ไม่พบโน้ตที่ตรงกับตัวกรอง' : 'ยังไม่มีโน้ต');

    if (!notes.length) {
        grid.innerHTML = filtered
            ? '<div class="empty-state"><p class="empty-state-text">ไม่พบโน้ตที่ตรงกับตัวกรอง</p><button type="button" class="btn btn-sm" data-act="clearNoteFilters">ล้างตัวกรอง</button></div>'
            : '<div class="empty-state"><p class="empty-state-title">ยังไม่มีโน้ต</p>'
              + '<p class="empty-state-text">จดความคิด รายการ หรือลิงก์ไว้ที่นี่ โน้ตหนึ่งอันประกอบด้วยข้อความ ลิงก์ และเช็กลิสต์ และเข้ารหัสด้วยรหัสผ่านได้</p>'
              + '<button type="button" class="btn btn-primary" data-act="openCreateNote" data-args="[false]">สร้างโน้ตแรก</button></div>';
        return;
    }

    const pinned = notes.filter(n => Number(n.pinned));
    const rest = notes.filter(n => !Number(n.pinned));
    const section = (label, list) => list.length
        ? '<section class="sec" aria-label="' + label + '"><div class="sec-head"><h2>' + label + '<span class="count">' + list.length + '</span></h2></div>'
          + '<ul class="ruled-list">' + list.map(noteRow).join('') + '</ul></section>'
        : '';

    // A heading only helps when there are two groups to tell apart.
    grid.innerHTML = pinned.length
        ? section('ปักหมุด', pinned) + section('โน้ตอื่น', rest)
        : '<ul class="ruled-list note-list">' + rest.map(noteRow).join('') + '</ul>';
}

// Exported below: a top-level const is not a window property, and the
// declarative actions in the markup resolve their names through window.
const searchNotes = debounce(function (value) {
    currentSearch = value;
    loadNotes();
}, 400);
window.searchNotes = searchNotes;

function filterByTag(tagId, el) {
    currentTagId = tagId;
    document.querySelectorAll('#tagList .tag').forEach(t => {
        t.classList.toggle('active', t === el);
        t.setAttribute('aria-pressed', String(t === el));
    });
    loadNotes();
}

function clearNoteFilters() {
    currentTagId = 0;
    currentSearch = '';
    document.getElementById('noteSearch').value = '';
    const all = document.querySelector('#tagList [data-tag-id="0"]');
    if (all) filterByTag(0, all); else loadNotes();
}

function createNoteError(message) {
    const line = document.getElementById('createNoteError');
    line.textContent = message;
    line.hidden = message === '';
}

function openCreateNote(encrypted) {
    document.getElementById('createNoteEncrypted').value = encrypted ? '1' : '0';
    document.getElementById('createNoteModalTitle').textContent = encrypted ? 'โน้ตเข้ารหัส' : 'โน้ตใหม่';
    document.getElementById('createNoteTitle').value = '';
    document.getElementById('createNotePw').value = '';
    document.getElementById('createNotePwGroup').hidden = !encrypted;
    createNoteError('');
    openModal('createNoteModal');
    document.getElementById('createNoteTitle').focus();
}

async function submitCreateNote() {
    const title     = document.getElementById('createNoteTitle').value.trim();
    const encrypted = document.getElementById('createNoteEncrypted').value === '1';
    const password  = document.getElementById('createNotePw').value;

    if (encrypted && !password) { createNoteError('ใส่รหัสผ่านของโน้ตก่อน'); document.getElementById('createNotePw').focus(); return; }

    try {
        const data = await apiFetch(BASE_URL + '/api/notes', {
            method: 'POST',
            body: JSON.stringify({ title: title || 'ไม่มีชื่อ', is_encrypted: encrypted, password })
        });
        closeModal('createNoteModal');
        window.location.href = BASE_URL + '/notes/' + data.note.id;
    } catch (err) {
        createNoteError(err.message || 'สร้างโน้ตไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function togglePin(id, isPinned) {
    try {
        await apiFetch(BASE_URL + '/api/notes/' + id, { method: 'PUT', body: JSON.stringify({ pinned: isPinned ? 0 : 1 }) });
        loadNotes();
    } catch (err) {
        toast(err.message || 'ปักหมุดไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

async function deleteNote(id) {
    if (!await confirmAction('ลบโน้ตนี้แล้วกู้คืนไม่ได้ เนื้อหาในโน้ตจะหายทั้งหมด', 'ลบโน้ต', 'ลบโน้ตนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/notes/' + id, { method: 'DELETE' });
        toast('ลบโน้ตแล้ว');
        loadNotes();
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

/* =====================================================
   EDITOR PAGE

   Every edit is saved a moment after the last keystroke. What is still waiting
   (a keystroke less than a second old) is sent as the page is left, so closing
   the tab does not lose it.
===================================================== */
let noteId      = null;
let noteEncrypt = false;
let notePass    = '';
let noteTags    = [];
let blocks      = [];

const SAVE_DELAY = 800;
const pendingBlocks = new Map();   // block id -> { type, content }
const blockTimers = {};
let pendingTitle = null;
let titleTimer = null;
let statusTimer = null;

if (document.getElementById('noteEditor')) {
    document.addEventListener('DOMContentLoaded', function () {
        const el = document.getElementById('noteEditor');
        noteId      = parseInt(el.dataset.noteId, 10);
        noteEncrypt = el.dataset.encrypted === '1';
        noteTags    = (window.NOTE_TAGS || []).map(t => t.name);

        renderTagList();
        autoResize(document.getElementById('noteTitle'));
        wireTagInput();

        if (!noteEncrypt) loadBlocks();
        else document.getElementById('notePassword').focus();

        const container = document.getElementById('blocksContainer');
        if (container && typeof Sortable !== 'undefined') {
            Sortable.create(container, {
                animation: 150,
                handle: '.block-grip',
                ghostClass: 'sortable-ghost',
                delay: 120,                // a short hold, so scrolling by touch does not start a drag
                delayOnTouchOnly: true,
                touchStartThreshold: 7,
                onEnd: saveBlockOrder
            });
        }

        window.addEventListener('pagehide', flushAll);
        document.addEventListener('visibilitychange', () => { if (document.hidden) flushAll(); });
    });
}

/* ── Saving ── */
function setSaveStatus(message, state) {
    const el = document.getElementById('saveStatus');
    if (!el) return;
    clearTimeout(statusTimer);
    el.textContent = message;
    el.classList.toggle('error', state === 'error');
    // A failure stays until the next save; success settles back to the quiet text.
    if (state === 'ok') statusTimer = setTimeout(() => { el.textContent = 'บันทึกอัตโนมัติ'; }, 2000);
}

function queueBlockSave(id, type, content) {
    pendingBlocks.set(id, { type, content });
    setSaveStatus('กำลังบันทึก…');
    clearTimeout(blockTimers[id]);
    blockTimers[id] = setTimeout(() => flushBlock(id), SAVE_DELAY);
}

async function flushBlock(id, leaving) {
    const item = pendingBlocks.get(id);
    if (!item) return;
    pendingBlocks.delete(id);
    clearTimeout(blockTimers[id]);

    const body = { type: item.type, content: item.content };
    if (noteEncrypt && notePass) body.password = notePass;
    try {
        await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks/' + id, {
            method: 'PUT',
            body: JSON.stringify(body),
            keepalive: leaving === true,
        });
        setSaveStatus('บันทึกแล้ว', 'ok');
    } catch {
        // Keep it for the next change, unless something newer is already queued.
        if (!pendingBlocks.has(id)) pendingBlocks.set(id, item);
        setSaveStatus('บันทึกไม่สำเร็จ แก้ไขอีกครั้งเพื่อลองบันทึกใหม่', 'error');
    }
}

function queueTitleSave() {
    const title = document.getElementById('noteTitle').value.trim();
    if (!title) return;                  // an empty title is not saved over the old one
    pendingTitle = title;
    setSaveStatus('กำลังบันทึก…');
    clearTimeout(titleTimer);
    titleTimer = setTimeout(flushTitle, SAVE_DELAY);
}

async function flushTitle(leaving) {
    if (pendingTitle === null) return;
    const title = pendingTitle;
    pendingTitle = null;
    clearTimeout(titleTimer);
    try {
        await apiFetch(BASE_URL + '/api/notes/' + noteId, { method: 'PUT', body: JSON.stringify({ title }), keepalive: leaving === true });
        setSaveStatus('บันทึกแล้ว', 'ok');
    } catch {
        if (pendingTitle === null) pendingTitle = title;
        setSaveStatus('บันทึกไม่สำเร็จ แก้ไขอีกครั้งเพื่อลองบันทึกใหม่', 'error');
    }
}

function flushAll() {
    for (const id of Array.from(pendingBlocks.keys())) flushBlock(id, true);
    flushTitle(true);
}

/* ── Loading and unlocking ── */
async function loadBlocks() {
    const container = document.getElementById('blocksContainer');
    try {
        const data = await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks');
        blocks = data.blocks || [];
        container.removeAttribute('aria-busy');
        renderBlocks();
    } catch {
        container.removeAttribute('aria-busy');
        container.innerHTML = '<div class="alert alert-danger" role="alert">โหลดเนื้อหาโน้ตไม่สำเร็จ '
            + '<button type="button" class="btn btn-sm" data-act="loadBlocks">ลองอีกครั้ง</button></div>';
    }
}

function unlockError(message) {
    const line = document.getElementById('unlockError');
    line.textContent = message;
    line.hidden = message === '';
}

async function unlockNote() {
    notePass = document.getElementById('notePassword').value;
    if (!notePass) { unlockError('ใส่รหัสผ่านของโน้ต'); return; }

    try {
        const data = await apiFetch(BASE_URL + '/api/notes/' + noteId + '/verify', {
            method: 'POST',
            body: JSON.stringify({ password: notePass })
        });
        blocks = data.blocks || [];
        document.getElementById('encryptedPrompt').hidden = true;
        document.getElementById('editorBody').hidden = false;
        document.getElementById('blocksContainer').removeAttribute('aria-busy');
        renderBlocks();
        autoResize(document.getElementById('noteTitle'));
    } catch (err) {
        notePass = '';
        unlockError(err.message || 'รหัสผ่านไม่ถูกต้อง');
        document.getElementById('notePassword').select();
    }
}

/* ── Blocks ── */
function renderBlocks() {
    const container = document.getElementById('blocksContainer');
    container.innerHTML = '';
    if (!blocks.length) {
        container.innerHTML = '<p class="blocks-empty">โน้ตนี้ยังว่างอยู่ เพิ่มข้อความ ลิงก์ หรือเช็กลิสต์ด้านล่าง</p>';
        return;
    }
    blocks.forEach(b => container.appendChild(createBlockEl(b)));
}

const BLOCK_LABEL = { text: 'ข้อความ', link: 'ลิงก์', checklist: 'เช็กลิสต์' };

function iconButton(icon, label, handler, extraClass) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'icon-btn sm' + (extraClass ? ' ' + extraClass : '');
    btn.setAttribute('aria-label', label);
    btn.title = label;
    btn.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#' + icon + '"/></svg>';
    btn.addEventListener('click', handler);
    return btn;
}

function createBlockEl(block) {
    const div = document.createElement('div');
    div.className = 'block';
    div.dataset.id   = block.id;
    div.dataset.type = block.type;

    const grip = document.createElement('span');
    grip.className = 'block-grip';
    grip.setAttribute('aria-hidden', 'true');
    grip.innerHTML = '<svg class="icon"><use href="#i-grip"/></svg>';

    const body = document.createElement('div');
    body.className = 'block-body';
    body.appendChild(renderBlockContent(block));

    const tools = document.createElement('div');
    tools.className = 'block-tools';

    const typeSelect = document.createElement('select');
    typeSelect.className = 'block-type';
    typeSelect.setAttribute('aria-label', 'ชนิดของบล็อก');
    typeSelect.innerHTML = Object.entries(BLOCK_LABEL).map(([v, l]) => '<option value="' + v + '">' + l + '</option>').join('');
    typeSelect.value = block.type;
    typeSelect.addEventListener('change', async function () {
        const next = this.value;
        if (block.content && !await confirmAction('เปลี่ยนชนิดแล้วเนื้อหาของบล็อกนี้จะถูกล้าง', 'เปลี่ยนชนิด', 'เปลี่ยนชนิดบล็อก?')) {
            this.value = block.type;
            return;
        }
        block.type = next;
        block.content = '';
        div.dataset.type = next;
        body.innerHTML = '';
        body.appendChild(renderBlockContent(block));
        queueBlockSave(block.id, next, '');
    });

    tools.appendChild(iconButton('i-up', 'ย้ายบล็อกขึ้น', () => moveBlock(div, -1)));
    tools.appendChild(iconButton('i-down', 'ย้ายบล็อกลง', () => moveBlock(div, 1)));
    tools.appendChild(typeSelect);
    tools.appendChild(iconButton('i-trash', 'ลบบล็อก', () => deleteBlock(block.id, div)));

    div.appendChild(grip);
    div.appendChild(body);
    div.appendChild(tools);
    return div;
}

function field(tag, className, label, attrs) {
    const el = document.createElement(tag);
    el.className = className;
    el.setAttribute('aria-label', label);
    Object.entries(attrs || {}).forEach(([k, v]) => { el[k] = v; });
    return el;
}

function renderBlockContent(block) {
    const wrap = document.createElement('div');
    wrap.className = 'block-' + block.type;

    if (block.type === 'text') {
        const ta = field('textarea', 'block-textarea', 'ข้อความ', { rows: 1, placeholder: 'พิมพ์ข้อความ', value: block.content || '' });
        ta.addEventListener('input', function () {
            autoResize(this);
            block.content = this.value;
            queueBlockSave(block.id, 'text', this.value);
        });
        wrap.appendChild(ta);
        // Sized once it is in the page and has a width to wrap to.
        requestAnimationFrame(() => autoResize(ta));

    } else if (block.type === 'link') {
        let link = {};
        try { link = JSON.parse(block.content) || {}; } catch { /* a link block starts empty */ }

        const urlInput = field('input', 'block-link-url', 'ที่อยู่เว็บ (URL)', { type: 'url', placeholder: 'https://example.com', value: link.url || '' });
        const labelInput = field('input', 'block-link-label', 'ชื่อลิงก์', { type: 'text', placeholder: 'ชื่อลิงก์ (ไม่ใส่ก็ได้)', value: link.label || '' });
        const open = document.createElement('a');
        open.className = 'btn btn-sm block-link-open';
        open.target = '_blank';
        open.rel = 'noopener noreferrer';
        open.textContent = 'เปิดลิงก์';

        const sync = () => {
            const url = urlInput.value.trim();
            open.hidden = url === '';
            open.href = /^https?:\/\//i.test(url) ? url : 'https://' + url;
        };
        const save = () => {
            sync();
            block.content = JSON.stringify({ url: urlInput.value.trim(), label: labelInput.value.trim() });
            queueBlockSave(block.id, 'link', block.content);
        };
        urlInput.addEventListener('input', save);
        labelInput.addEventListener('input', save);
        sync();

        wrap.appendChild(urlInput);
        wrap.appendChild(labelInput);
        wrap.appendChild(open);

    } else if (block.type === 'checklist') {
        let items = [];
        try { items = JSON.parse(block.content) || []; } catch { /* a checklist starts empty */ }

        const list = document.createElement('div');
        list.className = 'checklist';

        const commit = () => {
            block.content = JSON.stringify(items);
            queueBlockSave(block.id, 'checklist', block.content);
        };

        const focusRow = index => {
            const rows = list.querySelectorAll('.checklist-input');
            if (rows[index]) rows[index].focus();
        };

        const draw = () => {
            list.innerHTML = '';
            items.forEach((item, i) => {
                const row = document.createElement('div');
                row.className = 'checklist-row';

                const cb = field('input', 'checklist-box', 'ทำแล้ว', { type: 'checkbox', checked: !!item.checked });
                const text = field('input', 'checklist-input' + (item.checked ? ' done' : ''), 'รายการที่ ' + (i + 1), { type: 'text', placeholder: 'รายการ', value: item.text || '' });

                cb.addEventListener('change', function () {
                    items[i].checked = this.checked;
                    text.classList.toggle('done', this.checked);
                    commit();
                });
                text.addEventListener('input', function () { items[i].text = this.value; commit(); });
                text.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        items.splice(i + 1, 0, { text: '', checked: false });
                        draw();
                        focusRow(i + 1);
                        commit();
                    } else if (e.key === 'Backspace' && this.value === '' && items.length > 1) {
                        e.preventDefault();
                        items.splice(i, 1);
                        draw();
                        focusRow(Math.max(0, i - 1));
                        commit();
                    }
                });

                row.appendChild(cb);
                row.appendChild(text);
                row.appendChild(iconButton('i-close', 'ลบรายการที่ ' + (i + 1), () => {
                    items.splice(i, 1);
                    draw();
                    commit();
                }));
                list.appendChild(row);
            });

            const add = document.createElement('button');
            add.type = 'button';
            add.className = 'btn btn-link checklist-add';
            add.textContent = 'เพิ่มรายการ';
            add.addEventListener('click', () => {
                items.push({ text: '', checked: false });
                draw();
                focusRow(items.length - 1);
            });
            list.appendChild(add);
        };

        draw();
        wrap.appendChild(list);
    }

    return wrap;
}

async function addBlock(type) {
    try {
        const body = { type, content: '' };
        if (noteEncrypt && notePass) body.password = notePass;

        const data = await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks', { method: 'POST', body: JSON.stringify(body) });
        const created = { id: data.id, type, content: '' };
        blocks.push(created);

        const container = document.getElementById('blocksContainer');
        container.querySelector('.blocks-empty')?.remove();
        const el = createBlockEl(created);
        container.appendChild(el);
        el.querySelector('textarea, input[type="url"], .checklist-input')?.focus();
    } catch (err) {
        toast(err.message || 'เพิ่มบล็อกไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

async function deleteBlock(id, el) {
    if (!await confirmAction('ลบบล็อกนี้แล้วกู้คืนไม่ได้', 'ลบบล็อก', 'ลบบล็อกนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks/' + id, { method: 'DELETE' });
        pendingBlocks.delete(id);
        clearTimeout(blockTimers[id]);
        blocks = blocks.filter(b => b.id !== id);
        el.remove();
        if (!blocks.length) renderBlocks();
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

function moveBlock(el, step) {
    const target = step < 0 ? el.previousElementSibling : el.nextElementSibling;
    if (!target || !target.classList.contains('block')) return;
    if (step < 0) target.before(el); else target.after(el);
    saveBlockOrder();
}

async function saveBlockOrder() {
    const items = [];
    document.querySelectorAll('#blocksContainer .block[data-id]').forEach((el, idx) => {
        items.push({ id: parseInt(el.dataset.id, 10), position: idx });
    });
    try {
        await apiFetch(BASE_URL + '/api/notes/' + noteId + '/blocks/reorder', { method: 'POST', body: JSON.stringify({ items }) });
        setSaveStatus('บันทึกลำดับแล้ว', 'ok');
    } catch {
        setSaveStatus('บันทึกลำดับไม่สำเร็จ', 'error');
    }
}

/* ── Tags ── */
function wireTagInput() {
    const input = document.getElementById('tagInput');
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
            e.preventDefault();
            submitTagInput(input);
        } else if (e.key === 'Backspace' && input.value === '' && noteTags.length) {
            removeTag(noteTags[noteTags.length - 1]);
        }
    });
    // A space or a comma typed or pasted at the end also closes a tag.
    input.addEventListener('input', () => {
        if (/[\s,;，；]$/.test(input.value)) submitTagInput(input);
    });
    input.addEventListener('blur', () => submitTagInput(input));
}

function submitTagInput(input) {
    const value = input.value.replace(/[,;，；]/g, '').trim();
    input.value = '';
    if (value && !noteTags.includes(value)) {
        noteTags.push(value);
        renderTagList();
        saveTags();
    }
}

function removeTag(name) {
    noteTags = noteTags.filter(t => t !== name);
    renderTagList();
    saveTags();
}

function renderTagList() {
    document.getElementById('noteTagList').innerHTML = noteTags.map(name =>
        '<li class="tag active">' + escHtml(name)
        + '<button type="button" class="tag-remove" data-act="removeTag" data-args="' + escHtml(JSON.stringify([name])) + '" aria-label="ลบแท็ก ' + escHtml(name) + '">&times;</button></li>'
    ).join('');
}

async function saveTags() {
    try {
        await apiFetch(BASE_URL + '/api/notes/' + noteId, { method: 'PUT', body: JSON.stringify({ tags: noteTags }) });
        setSaveStatus('บันทึกแล้ว', 'ok');
    } catch {
        setSaveStatus('บันทึกแท็กไม่สำเร็จ', 'error');
    }
}

/* ── Helpers ── */
// Sizing to the text is the one inline style: it follows what was typed.
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}

function autoResizeAndSave(el) {
    autoResize(el);
    queueTitleSave();
}
