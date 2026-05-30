// =====================================================
// settings.js — Settings page
// =====================================================
(function () {
    'use strict';
    const $ = (s, c) => (c || document).querySelector(s);
    const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

    // ---------- Tabs ----------
    function initTabs() {
        const tabs = $$('.settings-tab');
        const panes = $$('.settings-pane');
        tabs.forEach(btn => btn.addEventListener('click', () => {
            const name = btn.dataset.tab;
            tabs.forEach(t => {
                const active = t === btn;
                t.classList.toggle('active', active);
                t.classList.toggle('btn-primary', active);
                t.classList.toggle('btn-ghost', !active);
            });
            panes.forEach(p => p.style.display = p.id === 'tab-' + name ? 'block' : 'none');
            try { localStorage.setItem('settings_last_tab', name); } catch (_) {}
        }));
        // Remember last tab
        try {
            const last = localStorage.getItem('settings_last_tab');
            if (last) {
                const btn = tabs.find(t => t.dataset.tab === last);
                if (btn) btn.click();
            }
        } catch (_) {}
    }

    // ---------- Profile ----------
    async function saveProfile() {
        const body = {
            display_name: $('#profileName').value.trim(),
            email:        $('#profileEmail').value.trim(),
        };
        try {
            await apiFetch(BASE_URL + '/api/settings/profile', { method: 'POST', body: JSON.stringify(body) });
            toast('บันทึกโปรไฟล์แล้ว');
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    // ---------- Theme ----------
    async function setTheme(theme) {
        try {
            await apiFetch(BASE_URL + '/api/settings/theme', { method: 'POST', body: JSON.stringify({ theme }) });
            document.documentElement.setAttribute('data-theme', theme);
            toast('เปลี่ยนธีมแล้ว');
        } catch (err) { toast('บันทึกธีมไม่สำเร็จ', 'danger'); }
    }

    // ---------- Password ----------
    function scorePassword(pw) {
        if (!pw) return { score: 0, label: 'อย่างน้อย 8 ตัวอักษร' };
        let score = 0;
        if (pw.length >= 8)  score++;
        if (pw.length >= 12) score++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
        if (/\d/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        const labels = ['อ่อนมาก', 'อ่อน', 'พอใช้', 'ดี', 'แข็งแรง', 'แข็งแรงมาก'];
        return { score, label: labels[score] || labels[0] };
    }

    function initPasswordForm() {
        // Show/hide toggles
        $$('.pw-toggle').forEach(btn => btn.addEventListener('click', () => {
            const inp = document.getElementById(btn.dataset.target);
            if (!inp) return;
            inp.type = inp.type === 'password' ? 'text' : 'password';
            btn.textContent = inp.type === 'password' ? 'แสดง' : 'ซ่อน';
        }));

        // Strength meter
        const newPw = $('#pwNew'), fill = $('#pwStrengthFill'), txt = $('#pwStrengthText');
        newPw?.addEventListener('input', () => {
            const { score, label } = scorePassword(newPw.value);
            const pct = (score / 5) * 100;
            fill.style.width = pct + '%';
            const colors = ['#c0392b', '#c0392b', '#c07a00', '#c07a00', '#27ae60', '#27ae60'];
            fill.style.background = colors[score] || colors[0];
            txt.textContent = newPw.value ? `ระดับ: ${label}` : 'อย่างน้อย 8 ตัวอักษร';
        });

        // Match hint
        const confirm = $('#pwConfirm'), hint = $('#pwMatchHint');
        const updateMatch = () => {
            if (!confirm.value) { hint.textContent = ''; hint.style.color = ''; return; }
            if (confirm.value === newPw.value) {
                hint.textContent = '✓ รหัสผ่านตรงกัน'; hint.style.color = 'var(--color-success)';
            } else {
                hint.textContent = '✗ รหัสผ่านไม่ตรงกัน'; hint.style.color = 'var(--color-danger)';
            }
        };
        confirm?.addEventListener('input', updateMatch);
        newPw?.addEventListener('input', updateMatch);

        // Submit
        $('#btnChangePassword')?.addEventListener('click', async () => {
            const current = $('#pwCurrent').value;
            const nw = newPw.value;
            const cf = confirm.value;
            if (!current || !nw || !cf) { toast('กรุณากรอกข้อมูลให้ครบ', 'danger'); return; }
            if (nw.length < 8) { toast('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร', 'danger'); return; }
            if (nw !== cf) { toast('รหัสผ่านใหม่ไม่ตรงกัน', 'danger'); return; }
            if (nw === current) { toast('รหัสผ่านใหม่ต้องต่างจากรหัสผ่านปัจจุบัน', 'danger'); return; }

            try {
                await apiFetch(BASE_URL + '/api/settings/password', {
                    method: 'POST',
                    body: JSON.stringify({ current_password: current, new_password: nw, confirm_password: cf })
                });
                $('#pwCurrent').value = ''; newPw.value = ''; confirm.value = '';
                fill.style.width = '0%'; txt.textContent = 'อย่างน้อย 8 ตัวอักษร';
                hint.textContent = '';
                if (window.Swal) Swal.fire({ icon: 'success', title: 'เปลี่ยนรหัสผ่านแล้ว', timer: 1500, showConfirmButton: false });
                else toast('เปลี่ยนรหัสผ่านแล้ว');
            } catch (err) {
                toast(err.message || 'เปลี่ยนรหัสผ่านไม่สำเร็จ', 'danger');
            }
        });
    }

    // ---------- Timezone ----------
    function initTimezone() {
        const sel = $('#timezoneSelect');
        const clock = $('#tzCurrentTime');
        if (!sel || !clock) return;
        const update = () => {
            try {
                clock.textContent = new Date().toLocaleString('th-TH', {
                    timeZone: sel.value, dateStyle: 'full', timeStyle: 'medium'
                });
            } catch (_) { clock.textContent = new Date().toString(); }
        };
        update();
        setInterval(update, 1000);
        sel.addEventListener('change', update);

        $('#btnSaveTimezone')?.addEventListener('click', async () => {
            try {
                await apiFetch(BASE_URL + '/api/settings/timezone', {
                    method: 'POST', body: JSON.stringify({ timezone: sel.value })
                });
                toast('บันทึกเขตเวลาแล้ว');
            } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
        });
    }

    // ---------- Local storage info ----------
    function initLocalStorage() {
        const info = $('#localStorageInfo');
        function refresh() {
            if (!info) return;
            let items = 0, bytes = 0;
            for (let i = 0; i < localStorage.length; i++) {
                const k = localStorage.key(i);
                items++;
                bytes += (k.length + (localStorage.getItem(k) || '').length) * 2;
            }
            info.textContent = `มี ${items} รายการ · ขนาดประมาณ ${(bytes / 1024).toFixed(1)} KB`;
        }
        refresh();
        $('#btnClearLocal')?.addEventListener('click', () => {
            const doClear = () => {
                localStorage.clear();
                refresh();
                toast('ล้างข้อมูลในเบราว์เซอร์แล้ว');
            };
            confirmAction('ล้างข้อมูลในเบราว์เซอร์?', 'ล้าง', 'ล้างข้อมูล').then(ok => { if (ok) doClear(); });
        });
    }

    // ---------- Import data ----------
    function initImportData() {
        const btn = $('#btnImportData');
        const fileInp = $('#importFile');
        if (!btn || !fileInp) return;

        fileInp.addEventListener('change', () => {
            const labelText = $('#importFileNameText');
            if (fileInp.files.length) {
                const file = fileInp.files[0];
                let sizeStr = '';
                if (file.size < 1024) {
                    sizeStr = file.size + ' B';
                } else if (file.size < 1024 * 1024) {
                    sizeStr = (file.size / 1024).toFixed(1) + ' KB';
                } else {
                    sizeStr = (file.size / (1024 * 1024)).toFixed(1) + ' MB';
                }
                if (labelText) {
                    labelText.textContent = `${file.name} (${sizeStr})`;
                }
                btn.disabled = false;
            } else {
                if (labelText) {
                    labelText.textContent = 'คลิกเพื่อเลือกไฟล์ข้อมูลสำรอง (.json)';
                }
                btn.disabled = true;
            }
        });

        btn.addEventListener('click', async () => {
            if (!fileInp.files.length) {
                toast('กรุณาเลือกไฟล์ JSON ที่ต้องการนำเข้า', 'danger');
                return;
            }

            const file = fileInp.files[0];
            const proceed = async () => {
                const fd = new FormData();
                fd.append('file', file);
                fd.append('_csrf', document.querySelector('meta[name="csrf-token"]')?.content ?? '');

                btn.disabled = true;
                toast('กำลังนำเข้าข้อมูล...');

                try {
                    const res = await fetch(BASE_URL + '/api/settings/import', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                        body: fd
                    });

                    if (!res.ok) {
                        let msg = 'นำเข้าข้อมูลไม่สำเร็จ';
                        try { const j = await res.json(); msg = j.error || msg; } catch (_) {}
                        throw new Error(msg);
                    }

                    if (window.Swal) {
                        Swal.fire({
                            icon: 'success',
                            title: 'นำเข้าข้อมูลสำเร็จ',
                            text: 'ระบบได้รีสโตร์ข้อมูลสำรองเรียบร้อยแล้ว กำลังโหลดหน้าใหม่...',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        setTimeout(() => { window.location.reload(); }, 2000);
                    } else {
                        toast('นำเข้าข้อมูลสำเร็จแล้ว');
                        setTimeout(() => { window.location.reload(); }, 1500);
                    }
                } catch (err) {
                    toast(err.message || 'นำเข้าไม่สำเร็จ', 'danger');
                    btn.disabled = false;
                }
            };

            confirmAction(
                'ข้อมูลทั้งหมดในบัญชีปัจจุบันของคุณจะถูกลบและเขียนทับด้วยข้อมูลในไฟล์สำรอง การดำเนินการนี้ไม่สามารถยกเลิกได้',
                'ยืนยันนำเข้าข้อมูล',
                'คำเตือนเรื่องข้อมูลสูญหาย'
            ).then(ok => { if (ok) proceed(); });
        });
    }

    // ---------- Danger: delete account ----------
    function initDangerZone() {
        const confirmInp = $('#delConfirm');
        const passInp    = $('#delPassword');
        const btn        = $('#btnDeleteAccount');
        if (!btn) return;

        function updateBtn() {
            btn.disabled = !(confirmInp.value === 'DELETE' && passInp.value.length >= 1);
        }
        confirmInp.addEventListener('input', updateBtn);
        passInp.addEventListener('input', updateBtn);

        btn.addEventListener('click', async () => {
            const proceed = async () => {
                try {
                    await apiFetch(BASE_URL + '/api/settings/delete', {
                        method: 'POST',
                        body: JSON.stringify({ password: passInp.value, confirm_text: confirmInp.value })
                    });
                    window.location.href = BASE_URL + '/login';
                } catch (err) {
                    toast(err.message || 'ลบบัญชีไม่สำเร็จ', 'danger');
                }
            };
            confirmAction('ข้อมูลทั้งหมดจะถูกลบถาวรและไม่สามารถกู้คืนได้', 'ลบถาวร', 'ยืนยันลบบัญชี?').then(ok => { if (ok) proceed(); });
        });
    }

    // ---------- Categories ----------
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

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
        let loaded = false;
        $$('.settings-tab').forEach(t => t.addEventListener('click', () => {
            if (t.dataset.tab === 'categories' && !loaded) {
                loaded = true;
                loadFinCategories();
                loadExCategories();
                loadNoteTags();
            }
        }));
        // Load immediately if the tab is restored as active
        if ($('#tab-categories')?.style.display !== 'none') {
            loaded = true;
            loadFinCategories();
            loadExCategories();
            loadNoteTags();
        }
    }

    // ---------- Stock API keys ----------
    const STOCK_PROVIDERS = [
        { id: 'finnhub',      label: 'Finnhub',       help: 'ฟรี 60 req/min รองรับ US + SET (.BK)' },
        { id: 'alphavantage', label: 'Alpha Vantage', help: 'ฟรี 25 req/day' },
        { id: 'twelvedata',   label: 'Twelve Data',   help: 'ฟรี 800 req/day' },
    ];

    async function loadStockKeys() {
        const container = $('#stockKeysList');
        if (!container) return;
        try {
            const data = await apiFetch(BASE_URL + '/api/stocks/keys');
            const existing = data.keys || {};
            container.innerHTML = STOCK_PROVIDERS.map(p => {
                const info = existing[p.id] || { set: false, masked: '', updated_at: null };
                const statusClass = info.set ? 'set' : '';
                const statusText = info.set
                    ? ('ตั้งค่าแล้ว · ' + (info.masked || '') + (info.updated_at ? ' · อัปเดต ' + info.updated_at : ''))
                    : 'ยังไม่ได้ตั้ง';
                return `
                <div class="stock-key-row" data-provider="${p.id}">
                    <div>
                        <div class="stock-key-provider">${p.label}</div>
                        <div class="stock-key-status ${statusClass}">${escape(statusText)}</div>
                        <div class="text-xs text-muted" style="margin-top:2px">${escape(p.help)}</div>
                    </div>
                    <input type="password" class="form-control" placeholder="${info.set ? 'กรอกเพื่อเปลี่ยน' : 'API key'}" data-key-input="${p.id}">
                    <div class="stock-key-actions">
                        <button class="btn btn-primary btn-sm" data-key-act="save" data-provider="${p.id}">บันทึก</button>
                        <button class="btn btn-ghost btn-sm" data-key-act="test" data-provider="${p.id}">ทดสอบ</button>
                        ${info.set ? `<button class="btn btn-ghost btn-sm" style="color:var(--color-danger)" data-key-act="delete" data-provider="${p.id}">ลบ</button>` : ''}
                    </div>
                </div>`;
            }).join('');
        } catch (err) {
            container.innerHTML = '<div class="text-sm" style="color:var(--color-danger)">โหลดไม่สำเร็จ: ' + escape(err.message || '') + '</div>';
        }
    }

    function escape(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    async function saveStockKey(provider) {
        const input = document.querySelector(`[data-key-input="${provider}"]`);
        const apiKey = (input?.value || '').trim();
        try {
            const res = await apiFetch(BASE_URL + '/api/stocks/keys', {
                method: 'POST',
                body: JSON.stringify({ provider, api_key: apiKey })
            });
            if (input) input.value = '';
            toast(res.deleted ? 'ลบแล้ว' : 'บันทึกแล้ว');
            await loadStockKeys();
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    async function testStockKey(provider) {
        const input = document.querySelector(`[data-key-input="${provider}"]`);
        const apiKey = (input?.value || '').trim();
        try {
            const res = await apiFetch(BASE_URL + '/api/stocks/keys/test', {
                method: 'POST',
                body: JSON.stringify({ provider, api_key: apiKey })
            });
            toast(res.message || 'Key ใช้งานได้');
        } catch (err) { toast(err.message || 'ทดสอบไม่ผ่าน', 'danger'); }
    }

    async function deleteStockKey(provider) {
        if (!await confirmAction('ลบ API key ของ ' + provider + '?', 'ลบ')) return;
        try {
            await apiFetch(BASE_URL + '/api/stocks/keys/' + encodeURIComponent(provider), { method: 'DELETE' });
            toast('ลบแล้ว');
            await loadStockKeys();
        } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
    }

    // ---------- Stock AI keys ----------
    const STOCK_AI_PROVIDERS = [
        { id: 'gemini',    label: 'Google Gemini (แนะนำ)',  help: 'สมัครใช้งานฟรีที่ Google AI Studio คุ้มค่าและเร็วที่สุด' },
        { id: 'openai',    label: 'OpenAI (ChatGPT)',      help: 'ผู้ให้บริการยอดนิยม เช่น gpt-4o-mini' },
        { id: 'anthropic', label: 'Anthropic Claude',      help: 'ผู้ให้บริการประสิทธิภาพสูง เช่น Claude 3.5 Sonnet' },
        { id: 'kimi',      label: 'Moonshot Kimi AI',      help: 'ผู้ให้บริการโมเดล Kimi AI ยอดนิยม (รองรับ moonshot-v1)' },
        { id: 'openrouter', label: 'OpenRouter.ai',         help: 'เข้าถึง LLM ทุกค่ายด้วย API เดียว เช่น Gemini, Claude, GPT' }
    ];

    async function loadStockAiKeys() {
        const container = $('#stockAiKeysList');
        if (!container) return;
        try {
            const data = await apiFetch(BASE_URL + '/api/ai/keys');
            const existing = data.keys || {};
            container.innerHTML = STOCK_AI_PROVIDERS.map(p => {
                const info = existing[p.id] || { set: false, masked: '', updated_at: null };
                const statusClass = info.set ? 'set' : '';
                const statusText = info.set
                    ? ('ตั้งค่าแล้ว · ' + (info.masked || '') + (info.updated_at ? ' · อัปเดต ' + info.updated_at : ''))
                    : 'ยังไม่ได้ตั้ง';
                
                let borderColor = '#8b5cf6'; // default gemini purple
                if (p.id === 'openai') borderColor = '#10b981'; // openai green
                else if (p.id === 'anthropic') borderColor = '#f97316'; // anthropic orange
                else if (p.id === 'kimi') borderColor = '#06b6d4'; // kimi cyan
                else if (p.id === 'openrouter') borderColor = '#6366f1'; // openrouter indigo

                return `
                <div class="stock-key-row" data-provider="${p.id}" style="border-left-color: ${borderColor}">
                    <div>
                        <div class="stock-key-provider">${p.label}</div>
                        <div class="stock-key-status ${statusClass}">${escape(statusText)}</div>
                        <div class="text-xs text-muted" style="margin-top:2px">${escape(p.help)}</div>
                    </div>
                    <input type="password" class="form-control" placeholder="${info.set ? 'กรอกเพื่อเปลี่ยน' : 'API key'}" data-ai-key-input="${p.id}">
                    <div class="stock-key-actions">
                        <button class="btn btn-primary btn-sm" data-ai-key-act="save" data-provider="${p.id}">บันทึก</button>
                        <button class="btn btn-ghost btn-sm" data-ai-key-act="test" data-provider="${p.id}">ทดสอบ</button>
                        ${info.set ? `<button class="btn btn-ghost btn-sm" style="color:var(--color-danger)" data-ai-key-act="delete" data-provider="${p.id}">ลบ</button>` : ''}
                    </div>
                </div>`;
            }).join('');
        } catch (err) {
            container.innerHTML = '<div class="text-sm" style="color:var(--color-danger)">โหลดไม่สำเร็จ: ' + escape(err.message || '') + '</div>';
        }
    }

    async function saveStockAiKey(provider) {
        const input = document.querySelector(`[data-ai-key-input="${provider}"]`);
        const apiKey = (input?.value || '').trim();
        try {
            const res = await apiFetch(BASE_URL + '/api/ai/keys', {
                method: 'POST',
                body: JSON.stringify({ provider, api_key: apiKey })
            });
            if (input) input.value = '';
            toast(res.deleted ? 'ลบแล้ว' : 'บันทึกแล้ว');
            await loadStockAiKeys();
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    async function testStockAiKey(provider) {
        const input = document.querySelector(`[data-ai-key-input="${provider}"]`);
        const apiKey = (input?.value || '').trim();
        try {
            const res = await apiFetch(BASE_URL + '/api/ai/keys/test', {
                method: 'POST',
                body: JSON.stringify({ provider, api_key: apiKey })
            });
            toast(res.message || 'Key ใช้งานได้');
        } catch (err) { toast(err.message || 'ทดสอบไม่ผ่าน', 'danger'); }
    }

    async function deleteStockAiKey(provider) {
        if (!await confirmAction('ลบ API key ของ ' + provider + '?', 'ลบ')) return;
        try {
            await apiFetch(BASE_URL + '/api/ai/keys/' + encodeURIComponent(provider), { method: 'DELETE' });
            toast('ลบแล้ว');
            await loadStockAiKeys();
        } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
    }

    function initStockKeys() {
        // Delegated click handler for stock price keys
        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-key-act]');
            if (!btn) return;
            if (!btn.closest('#stockKeysList')) return;
            const act = btn.dataset.keyAct;
            const provider = btn.dataset.provider;
            if (act === 'save')   saveStockKey(provider);
            else if (act === 'test')   testStockKey(provider);
            else if (act === 'delete') deleteStockKey(provider);
        });

        // Delegated click handler for AI keys
        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-ai-key-act]');
            if (!btn) return;
            const act = btn.dataset.aiKeyAct;
            const provider = btn.dataset.provider;
            if (act === 'save')   saveStockAiKey(provider);
            else if (act === 'test')   testStockAiKey(provider);
            else if (act === 'delete') deleteStockAiKey(provider);
        });

        let loaded = false;
        $$('.settings-tab').forEach(t => t.addEventListener('click', () => {
            if (t.dataset.tab === 'stock-api' && !loaded) {
                loaded = true;
                loadStockKeys();
                loadStockAiKeys();
            }
        }));
        if ($('#tab-stock-api')?.style.display !== 'none') {
            loaded = true;
            loadStockKeys();
            loadStockAiKeys();
        }
    }

    // ---------- Manage Menus ----------
    function initMenus() {
        $('#btnSaveMenus')?.addEventListener('click', async () => {
            const checkedBoxes = $$('input[name="visible_menus[]"]:checked');
            const visibleMenus = checkedBoxes.map(cb => cb.value);

            try {
                await apiFetch(BASE_URL + '/api/settings/menus', {
                    method: 'POST',
                    body: JSON.stringify({ menus: visibleMenus })
                });
                
                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: 'บันทึกการตั้งค่าเมนูแล้ว',
                        text: 'ระบบกำลังรีโหลดเพื่อนำไปใช้งาน...',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    toast('บันทึกการตั้งค่าเมนูแล้ว');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                }
            } catch (err) {
                toast(err.message || 'บันทึกการตั้งค่าเมนูไม่สำเร็จ', 'danger');
            }
        });
    }

    // ---------- Dashboard Customization ----------
    function initDashboardConfig() {
        const btnReset = $('#btnResetDashboardLayout');
        if (btnReset) {
            btnReset.addEventListener('click', async function() {
                if (!confirm('คุณต้องการรีเซ็ตลำดับการแสดงผลและเปิดวิดเจ็ตทั้งหมดเป็นค่าเริ่มต้นใช่หรือไม่?')) return;
                
                const defaults = [
                    { widget_key: 'tasks',         position: 0, is_visible: 1 },
                    { widget_key: 'calendar',      position: 1, is_visible: 1 },
                    { widget_key: 'finance',       position: 2, is_visible: 1 },
                    { widget_key: 'workout',       position: 3, is_visible: 1 },
                    { widget_key: 'subscriptions', position: 4, is_visible: 1 },
                    { widget_key: 'projects',      position: 5, is_visible: 1 },
                    { widget_key: 'notes',         position: 6, is_visible: 1 },
                    { widget_key: 'stocks',        position: 7, is_visible: 1 }
                ];
                
                try {
                    btnReset.disabled = true;
                    await apiFetch(BASE_URL + '/api/dashboard/layout', {
                        method: 'POST',
                        body: JSON.stringify({ widgets: defaults })
                    });
                    toast('รีเซ็ตการแสดงผลแดชบอร์ดเรียบร้อยแล้ว');
                    setTimeout(() => window.location.reload(), 600);
                } catch (err) {
                    console.error(err);
                    toast('ไม่สามารถรีเซ็ตได้ กรุณาลองใหม่อีกครั้ง', 'danger');
                    btnReset.disabled = false;
                }
            });
        }
        
        const btnSave = $('#btnSaveDashboardCustomization');
        if (btnSave) {
            btnSave.addEventListener('click', async function() {
                const widgets = [];
                let maxPos = 0;
                
                const currentLayout = [...(window.dashboardLayout || [])];
                currentLayout.sort((a, b) => a.position - b.position);
                
                currentLayout.forEach(function(w) {
                    const chk = $('#chk_' + w.widget_key);
                    if (chk) {
                        widgets.push({
                            widget_key: w.widget_key,
                            position: maxPos++,
                            is_visible: chk.checked ? 1 : 0
                        });
                    }
                });
                
                try {
                    btnSave.disabled = true;
                    await apiFetch(BASE_URL + '/api/dashboard/layout', {
                        method: 'POST',
                        body: JSON.stringify({ widgets: widgets })
                    });
                    toast('บันทึกการตั้งค่าแดชบอร์ดเรียบร้อยแล้ว');
                    setTimeout(() => window.location.reload(), 600);
                } catch (err) {
                    console.error(err);
                    toast('ไม่สามารถบันทึกได้ กรุณาลองใหม่อีกครั้ง', 'danger');
                    btnSave.disabled = false;
                }
            });
        }
    }

    // ---------- Init ----------
    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        $('#btnSaveProfile')?.addEventListener('click', saveProfile);
        $$('input[name="theme"]').forEach(r => r.addEventListener('change', () => setTheme(r.value)));
        initPasswordForm();
        initTimezone();
        initLocalStorage();
        initImportData();
        initDangerZone();
        initCategories();
        initStockKeys();
        initMenus();
        initDashboardConfig();
    });
})();
