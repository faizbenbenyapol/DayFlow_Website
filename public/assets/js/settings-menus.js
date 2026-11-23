// =====================================================
// settings-menus.js — Settings: menus, the phone's bottom bar and the Today page
// Loaded after settings.js; classic scripts, so $ and $$ are local here.
// =====================================================
(function () {
    'use strict';
    const $ = (s, c) => (c || document).querySelector(s);
    const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

    // ---------- Manage Menus ----------
    // เมนูจัดเป็นหมวดเหมือนแถบข้าง ลำดับมีความหมายแค่ภายในหมวด แต่ละหมวดจึงเป็น list ของตัวเอง
    // ลำดับที่บันทึกคือทุก list ต่อกัน ชื่อหมวดและหมวดของเมนูส่งไปเป็น layout (REQ-9, 10, 12)
    function initMenus() {
        const root = $('#menuVisibilityList');
        if (!root) return;
        const lists = $$('.menu-order-list', root);
        const listOf = group => lists.find(list => list.dataset.group === group);

        const menuError = message => {
            const line = $('#menuError');
            line.textContent = message;
            line.hidden = message === '';
        };

        $$('.menu-order-item', root).forEach(addRowControls);
        lists.forEach(list => {
            if (window.Sortable) {
                window.Sortable.create(list, { animation: 180, handle: '.menu-drag-handle', ghostClass: 'sortable-ghost', delay: 120, delayOnTouchOnly: true });
            }
        });

        // ลูกศรเลื่อนขึ้นลงในหมวด และช่องเลือกหมวดที่ย้ายแถวไปหมวดอื่นทันที
        root.addEventListener('click', event => {
            const button = event.target.closest('[data-menu-move]');
            if (!button) return;
            const row = button.closest('.menu-order-item');
            const list = row.parentElement;
            const target = button.dataset.menuMove === 'up' ? row.previousElementSibling : row.nextElementSibling;
            if (!target) return;
            if (button.dataset.menuMove === 'up') list.insertBefore(row, target);
            else list.insertBefore(target, row);
            button.focus();
        });
        root.addEventListener('change', event => {
            const select = event.target.closest('.menu-group-select');
            if (!select) return;
            listOf(select.value).append(select.closest('.menu-order-item'));
            syncEmptyGroups();
            select.focus();
        });

        // เปลี่ยนชื่อหมวดแล้วชื่อในช่องเลือกหมวดของทุกแถวเปลี่ยนตาม (ว่าง = ชื่อตั้งต้นใน placeholder)
        root.addEventListener('input', event => {
            const field = event.target.closest('.menu-group-name');
            if (!field) return;
            const shownName = field.value.trim() || field.placeholder;
            $$('.menu-group-select option[value="' + field.dataset.group + '"]', root).forEach(option => { option.textContent = shownName; });
        });

        $('#menuSearch')?.addEventListener('input', event => filterMenus(event.target.value));

        $('#btnSaveMenus')?.addEventListener('click', async () => {
            const visibleMenus = $$('input[name="visible_menus[]"]:checked', root).map(cb => cb.value);
            if (visibleMenus.length < 1) { menuError('ต้องแสดงอย่างน้อย 1 เมนู นอกจากหน้าวันนี้'); return; }
            menuError('');
            // แถบล่างของมือถือเป็นห้าหมวดแล้ว ค่า mobile_tabs สองช่องแบบเก่าจึงไม่ส่ง ปล่อยค่าเดิมไว้
            await saveMenus($('#btnSaveMenus'), { menus: visibleMenus, order: currentOrder(), layout: currentLayout() }, 'บันทึกเมนูแล้ว กำลังโหลดหน้าใหม่');
        });

        $('#btnResetMenus')?.addEventListener('click', async () => {
            const confirmed = await confirmAction('ชื่อหมวด หมวดของเมนู ลำดับ และเมนูที่ซ่อนไว้ จะกลับเป็นค่าเริ่มต้นทั้งหมด', 'คืนค่าเริ่มต้น', 'คืนค่าเริ่มต้นของเมนู');
            if (!confirmed) return;
            const everyMenu = $$('.menu-order-item', root).map(row => row.dataset.menuKey);
            // order ว่างให้เซิร์ฟเวอร์ใช้ลำดับตั้งต้น layout {} ล้างชื่อหมวดและการย้ายหมวด
            await saveMenus($('#btnResetMenus'), { menus: everyMenu, order: [], layout: {} }, 'คืนค่าเริ่มต้นแล้ว กำลังโหลดหน้าใหม่');
        });

        /** เติมที่จับลาก และปุ่มเลื่อนขึ้นลงให้แถวเมนู (ปุ่มมีชื่อเมนูให้โปรแกรมอ่านหน้าจอ) */
        function addRowControls(row) {
            row.setAttribute('draggable', 'true');
            const handle = document.createElement('span');
            handle.className = 'menu-drag-handle';
            handle.textContent = '⋮⋮';
            handle.setAttribute('aria-hidden', 'true');
            row.prepend(handle);

            const name = $('.menu-name', row).textContent;
            const actions = document.createElement('span');
            actions.className = 'menu-order-actions';
            actions.innerHTML = '<button type="button" class="menu-move-btn" data-menu-move="up" aria-label="เลื่อน ' + escHtml(name) + ' ขึ้น">↑</button>'
                + '<button type="button" class="menu-move-btn" data-menu-move="down" aria-label="เลื่อน ' + escHtml(name) + ' ลง">↓</button>';
            row.append(actions);
        }

        /** บอก "ยังไม่มีเมนูในหมวดนี้" ใต้หมวดที่ list ว่าง เรียกหลังย้ายเมนูข้ามหมวด */
        function syncEmptyGroups() {
            lists.forEach(list => {
                list.closest('.menu-group').querySelector('.menu-empty').hidden = list.children.length > 0;
            });
        }

        /**
         * ซ่อนแถวที่ชื่อไม่มีคำค้น และหมวดที่ไม่เหลือแถวที่ตรง (REQ-12) ค้นว่าง = แสดงทั้งหมด
         * @param {string} query ข้อความในช่องค้นหา
         */
        function filterMenus(query) {
            const needle = query.trim().toLocaleLowerCase('th');
            let matches = 0;
            $$('.menu-group', root).forEach(group => {
                const rows = $$('.menu-order-item', group);
                rows.forEach(row => {
                    row.hidden = needle !== '' && !$('.menu-name', row).textContent.toLocaleLowerCase('th').includes(needle);
                });
                const groupMatches = rows.filter(row => !row.hidden).length;
                matches += groupMatches;
                group.hidden = needle !== '' && groupMatches === 0;
            });
            const empty = $('#menuSearchEmpty');
            empty.hidden = needle === '' || matches > 0;
            empty.textContent = 'ไม่พบเมนูชื่อ "' + query.trim() + '"';
        }

        /** ลำดับเมนูทั้งหมด: ทุกหมวดต่อกันตามที่เห็นบนหน้า */
        function currentOrder() {
            return lists.flatMap(list => $$('.menu-order-item', list).map(row => row.dataset.menuKey));
        }

        /** ชื่อหมวดที่พิมพ์ และหมวดที่แต่ละเมนูอยู่ตอนนี้ เซิร์ฟเวอร์ตัดค่าที่ตรงกับค่าตั้งต้นทิ้งเอง */
        function currentLayout() {
            const names = Object.fromEntries($$('.menu-group-name', root).map(field => [field.dataset.group, field.value]));
            const groups = Object.fromEntries($$('.menu-order-item', root).map(row => [row.dataset.menuKey, row.closest('.menu-order-list').dataset.group]));
            return { names, groups };
        }

        /**
         * ส่งค่าเมนูไปบันทึก สำเร็จแล้วโหลดหน้าใหม่ให้แถบเมนูทั้งหน้าเปลี่ยนตาม
         * ไม่สำเร็จ (เช่นชื่อหมวดยาวเกิน) บอก error ใต้รายการ และเปิดปุ่มให้กดใหม่
         */
        async function saveMenus(button, body, doneMessage) {
            button.disabled = true;
            try {
                await apiFetch(BASE_URL + '/api/settings/menus', { method: 'POST', body: JSON.stringify(body) });
                toast(doneMessage);
                setTimeout(() => window.location.reload(), 900);
            } catch (err) {
                menuError(err.message || 'บันทึกเมนูไม่สำเร็จ ลองอีกครั้ง');
                button.disabled = false;
            }
        }
    }

    // ---------- Dashboard Customization ----------
    function initDashboardConfig() {
        const btnReset = $('#btnResetDashboardLayout');
        if (btnReset) {
            btnReset.addEventListener('click', function() {
                confirmAction('ลำดับและการแสดงทุกส่วนของหน้าวันนี้จะกลับเป็นค่าเริ่มต้น', 'คืนค่าเริ่มต้น', 'คืนค่าเริ่มต้นของหน้าวันนี้?').then(async ok => {
                    if (!ok) return;
                    
                    // The server owns the default layout; the page was handed it.
                    const defaults = window.dashboardDefaults || [];
                    
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
            });
        }
        
        const widgetList = $('#dashboardWidgetsList');
        if (widgetList && typeof Sortable !== 'undefined') {
            Sortable.create(widgetList, {
                animation: 120,
                handle: '.drag-handle',
                ghostClass: 'sortable-ghost',
                delay: 120,
                delayOnTouchOnly: true,
            });
        }

        const btnSave = $('#btnSaveDashboardCustomization');
        if (btnSave) {
            btnSave.addEventListener('click', async function() {
                const widgets = [];
                let maxPos = 0;
                
                // The order on screen is the order to save, so a drag is honoured.
                $$('#dashboardWidgetsList [data-widget-key]').forEach(function(rowEl) {
                    const chk = $('input[type="checkbox"]', rowEl);
                    if (chk) {
                        widgets.push({
                            widget_key: rowEl.dataset.widgetKey,
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


    document.addEventListener('DOMContentLoaded', () => {
        initMenus();
        initDashboardConfig();
    });
})();
