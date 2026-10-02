// =====================================================
// settings-categories.js — Settings: finance, note and exercise categories
// Loaded after settings.js; classic scripts, so $ and $$ are local here.
// =====================================================
(function () {
    'use strict';
    const $ = (s, c) => (c || document).querySelector(s);
    const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

    // ---------- Categories ----------
    function renderFinCategories(cats) {
        const income  = $('#finCatListIncome');
        const expense = $('#finCatListExpense');
        if (!income || !expense) return;

        const row = c => `
            <li class="cat-item" data-id="${c.id}" data-type="${c.type}">
                <span class="cat-name">${escHtml(c.name)}</span>
                <span class="cat-actions">
                    <button class="btn btn-ghost btn-sm" data-act="edit">แก้ไข</button>
                    <button class="btn btn-link btn-sm" data-act="del">ลบ</button>
                </span>
            </li>`;

        const incList  = cats.filter(c => c.type === 'income');
        const expList  = cats.filter(c => c.type === 'expense');
        income.innerHTML  = incList.length  ? incList.map(row).join('')  : '<li class="cat-empty text-muted">ยังไม่มีหมวดหมู่</li>';
        expense.innerHTML = expList.length  ? expList.map(row).join('')  : '<li class="cat-empty text-muted">ยังไม่มีหมวดหมู่</li>';
    }

    async function loadFinCategories() {
        try {
            const data = await apiFetch(BASE_URL + '/api/finance/categories');
            renderFinCategories(data.categories || []);
        } catch { toast('โหลดหมวดหมู่การเงินไม่สำเร็จ', 'danger'); }
    }

    async function addFinCategory() {
        const name = $('#finCatNewName').value.trim();
        const type = $('#finCatNewType').value;
        if (!name) return;
        try {
            await apiFetch(BASE_URL + '/api/finance/categories', {
                method: 'POST',
                body: JSON.stringify({ name, type })
            });
            $('#finCatNewName').value = '';
            toast('เพิ่มแล้ว');
            loadFinCategories();
        } catch (err) { toast(err.message || 'เพิ่มไม่สำเร็จ', 'danger'); }
    }

    async function editFinCategory(li) {
        const id   = li.dataset.id;
        const type = li.dataset.type;
        const oldName = $('.cat-name', li).textContent;
        const { value: newName } = await Swal.fire({
            title: 'แก้ไขหมวดหมู่',
            input: 'text',
            inputValue: oldName,
            showCancelButton: true,
            confirmButtonText: 'บันทึก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#e05c4b',
            cancelButtonColor: '#6b7280',
            inputValidator: v => (!v || !v.trim()) ? 'กรุณากรอกชื่อ' : undefined,
        });
        if (!newName || newName.trim() === oldName) return;
        try {
            await apiFetch(BASE_URL + '/api/finance/categories/' + id, {
                method: 'PUT',
                body: JSON.stringify({ name: newName.trim(), type })
            });
            toast('บันทึกแล้ว');
            loadFinCategories();
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    async function delFinCategory(li) {
        const id = li.dataset.id;
        const name = $('.cat-name', li).textContent;
        if (!await confirmAction(`ลบ "${name}"? รายการที่ใช้หมวดหมู่นี้จะกลายเป็น "ไม่ระบุ"`, 'ลบ')) return;
        try {
            await apiFetch(BASE_URL + '/api/finance/categories/' + id, { method: 'DELETE' });
            toast('ลบแล้ว');
            loadFinCategories();
        } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
    }

    function renderNoteTags(tags) {
        const ul = $('#noteTagList');
        if (!ul) return;
        if (!tags.length) {
            ul.innerHTML = '<li class="cat-empty text-muted">ยังไม่มีแท็ก</li>';
            return;
        }
        ul.innerHTML = tags.map(t => `
            <li class="cat-item" data-id="${t.id}">
                <span class="cat-name">${escHtml(t.name)}</span>
                <span class="cat-count text-xs text-muted">${t.note_count || 0} โน้ต</span>
                <span class="cat-actions">
                    <button class="btn btn-ghost btn-sm" data-act="edit">แก้ไข</button>
                    <button class="btn btn-link btn-sm" data-act="del">ลบ</button>
                </span>
            </li>`).join('');
    }

    async function loadNoteTags() {
        try {
            const data = await apiFetch(BASE_URL + '/api/notes/tags');
            renderNoteTags(data.tags || []);
        } catch { toast('โหลดแท็กไม่สำเร็จ', 'danger'); }
    }

    async function addNoteTag() {
        const name = $('#noteTagNewName').value.trim();
        if (!name) return;
        try {
            await apiFetch(BASE_URL + '/api/notes/tags', {
                method: 'POST',
                body: JSON.stringify({ name })
            });
            $('#noteTagNewName').value = '';
            toast('เพิ่มแล้ว');
            loadNoteTags();
        } catch (err) { toast(err.message || 'เพิ่มไม่สำเร็จ', 'danger'); }
    }

    async function editNoteTag(li) {
        const id = li.dataset.id;
        const oldName = $('.cat-name', li).textContent;
        const { value: newName } = await Swal.fire({
            title: 'แก้ไขแท็ก',
            input: 'text',
            inputValue: oldName,
            showCancelButton: true,
            confirmButtonText: 'บันทึก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#e05c4b',
            cancelButtonColor: '#6b7280',
            inputValidator: v => (!v || !v.trim()) ? 'กรุณากรอกชื่อ' : undefined,
        });
        if (!newName || newName.trim() === oldName) return;
        try {
            await apiFetch(BASE_URL + '/api/notes/tags/' + id, {
                method: 'PUT',
                body: JSON.stringify({ name: newName.trim() })
            });
            toast('บันทึกแล้ว');
            loadNoteTags();
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    async function delNoteTag(li) {
        const id = li.dataset.id;
        const name = $('.cat-name', li).textContent;
        if (!await confirmAction(`ลบแท็ก "${name}"? โน้ตที่ใช้แท็กนี้จะไม่ถูกลบ`, 'ลบ')) return;
        try {
            await apiFetch(BASE_URL + '/api/notes/tags/' + id, { method: 'DELETE' });
            toast('ลบแล้ว');
            loadNoteTags();
        } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
    }

    function renderExCategories(cats) {
        const ul = $('#exCatList');
        if (!ul) return;
        if (!cats.length) {
            ul.innerHTML = '<li class="cat-empty text-muted">ยังไม่มีหมวดหมู่</li>';
            return;
        }
        ul.innerHTML = cats.map(c => `
            <li class="cat-item" data-id="${c.id}">
                <span class="cat-name">${escHtml(c.name)}</span>
                <span class="cat-actions">
                    <button class="btn btn-ghost btn-sm" data-act="edit">แก้ไข</button>
                    <button class="btn btn-link btn-sm" data-act="del">ลบ</button>
                </span>
            </li>`).join('');
    }

    async function loadExCategories() {
        try {
            const data = await apiFetch(BASE_URL + '/api/exercise/categories');
            renderExCategories(data.categories || []);
        } catch { toast('โหลดหมวดหมู่การออกกำลังกายไม่สำเร็จ', 'danger'); }
    }

    async function addExCategory() {
        const name = $('#exCatNewName').value.trim();
        if (!name) return;
        try {
            await apiFetch(BASE_URL + '/api/exercise/categories', {
                method: 'POST',
                body: JSON.stringify({ name })
            });
            $('#exCatNewName').value = '';
            toast('เพิ่มแล้ว');
            loadExCategories();
        } catch (err) { toast(err.message || 'เพิ่มไม่สำเร็จ', 'danger'); }
    }

    async function editExCategory(li) {
        const id = li.dataset.id;
        const oldName = $('.cat-name', li).textContent;
        const { value: newName } = await Swal.fire({
            title: 'แก้ไขหมวดหมู่',
            input: 'text',
            inputValue: oldName,
            showCancelButton: true,
            confirmButtonText: 'บันทึก',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#e05c4b',
            cancelButtonColor: '#6b7280',
            inputValidator: v => (!v || !v.trim()) ? 'กรุณากรอกชื่อ' : undefined,
        });
        if (!newName || newName.trim() === oldName) return;
        try {
            await apiFetch(BASE_URL + '/api/exercise/categories/' + id, {
                method: 'PUT',
                body: JSON.stringify({ name: newName.trim() })
            });
            toast('บันทึกแล้ว');
            loadExCategories();
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    async function delExCategory(li) {
        const id = li.dataset.id;
        const name = $('.cat-name', li).textContent;
        if (!await confirmAction(`ลบหมวดหมู่ "${name}"?`, 'ลบ')) return;
        try {
            await apiFetch(BASE_URL + '/api/exercise/categories/' + id, { method: 'DELETE' });
            toast('ลบแล้ว');
            loadExCategories();
        } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
    }

    function initCategories() {
        $('#finCatForm')?.addEventListener('submit', e => {
            e.preventDefault();
            addFinCategory();
        });
        $('#exCatForm')?.addEventListener('submit', e => {
            e.preventDefault();
            addExCategory();
        });
        $('#noteTagForm')?.addEventListener('submit', e => {
            e.preventDefault();
            addNoteTag();
        });

        // Delegated handlers for edit/del
        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-act]');
            if (!btn) return;
            const li = btn.closest('.cat-item');
            if (!li) return;
            const isFinance = !!li.closest('#finCatListIncome, #finCatListExpense');
            const isExercise = !!li.closest('#exCatList');
            const act = btn.dataset.act;
            if (isFinance) {
                if (act === 'edit') editFinCategory(li);
                else if (act === 'del') delFinCategory(li);
            } else if (isExercise) {
                if (act === 'edit') editExCategory(li);
                else if (act === 'del') delExCategory(li);
            } else {
                if (act === 'edit') editNoteTag(li);
                else if (act === 'del') delNoteTag(li);
            }
        });

        // Load when tab activated
        onSettingsTab('categories', () => {
            loadFinCategories();
            loadExCategories();
            loadNoteTags();
        });
    }


    document.addEventListener('DOMContentLoaded', () => {
        initCategories();
    });
})();
