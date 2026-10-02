// =====================================================
// settings-menus.js — Settings: menus, the phone's bottom bar and the Today page
// Loaded after settings.js; classic scripts, so $ and $$ are local here.
// =====================================================
(function () {
    'use strict';
    const $ = (s, c) => (c || document).querySelector(s);
    const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

    // ---------- Manage Menus ----------
    // The menus are grouped like the rail. Order only means something inside a
    // group, so each group is its own list; the saved order is the lists in turn.
    function initMenus() {
        const root = $('#menuVisibilityList');
        if (!root) return;
        const lists = $$('.menu-order-list', root);
        const labels = window.menuLabels || {};

        const menuError = message => {
            const line = $('#menuError');
            line.textContent = message;
            line.hidden = message === '';
        };

        lists.forEach(list => {
            $$('.menu-order-item', list).forEach(row => {
                row.setAttribute('draggable', 'true');
                const handle = document.createElement('span');
                handle.className = 'menu-drag-handle';
                handle.textContent = '⋮⋮';
                handle.setAttribute('aria-hidden', 'true');
                row.prepend(handle);

                const name = row.querySelector('span:not(.menu-drag-handle)').textContent;
                const actions = document.createElement('span');
                actions.className = 'menu-order-actions';
                actions.innerHTML = '<button type="button" class="menu-move-btn" data-menu-move="up" aria-label="เลื่อน ' + name + ' ขึ้น">↑</button>'
                    + '<button type="button" class="menu-move-btn" data-menu-move="down" aria-label="เลื่อน ' + name + ' ลง">↓</button>';
                row.append(actions);
            });

            if (window.Sortable) {
                window.Sortable.create(list, { animation: 180, handle: '.menu-drag-handle', ghostClass: 'sortable-ghost', delay: 120, delayOnTouchOnly: true });
            }

            list.addEventListener('click', event => {
                const button = event.target.closest('[data-menu-move]');
                if (!button) return;
                const row = button.closest('.menu-order-item');
                const target = button.dataset.menuMove === 'up' ? row.previousElementSibling : row.nextElementSibling;
                if (!target) return;
                if (button.dataset.menuMove === 'up') list.insertBefore(row, target);
                else list.insertBefore(target, row);
                button.focus();
            });
        });

        // The two places in the phone's bottom bar: any menu that is still shown.
        const selects = [$('#mobileTab1'), $('#mobileTab2')];
        const shown = () => $$('input[name="visible_menus[]"]:checked', root).map(cb => cb.value);

        function fillSelects(prefer) {
            const keys = shown();
            selects.forEach((select, i) => {
                const wanted = prefer ? prefer[i] : (select.value || select.dataset.current);
                select.innerHTML = keys.map(k => '<option value="' + k + '">' + (labels[k] || k) + '</option>').join('');
                if (keys.includes(wanted)) select.value = wanted;
            });
            // The same menu cannot hold both places.
            if (selects[0].value === selects[1].value) {
                const other = keys.find(k => k !== selects[0].value);
                if (other) selects[1].value = other;
            }
        }
        fillSelects();
        $$('input[name="visible_menus[]"]', root).forEach(cb => cb.addEventListener('change', () => fillSelects()));
        selects.forEach((select, i) => select.addEventListener('change', () => {
            const other = selects[1 - i];
            if (other.value === select.value) {
                const swapTo = shown().find(k => k !== select.value);
                if (swapTo) other.value = swapTo;
            }
        }));

        $('#btnSaveMenus')?.addEventListener('click', async () => {
            const saveButton = $('#btnSaveMenus');
            const visibleMenus = shown();
            const order = lists.flatMap(list => $$('.menu-order-item', list).map(row => row.dataset.menuKey));

            if (visibleMenus.length < 2) { menuError('ต้องแสดงอย่างน้อย 2 เมนู เพื่อให้แถบล่างบนมือถือมีที่ว่างครบ'); return; }
            menuError('');

            saveButton.disabled = true;
            try {
                await apiFetch(BASE_URL + '/api/settings/menus', {
                    method: 'POST',
                    body: JSON.stringify({ menus: visibleMenus, order, mobile_tabs: [selects[0].value, selects[1].value] })
                });
                toast('บันทึกเมนูแล้ว กำลังโหลดหน้าใหม่');
                setTimeout(() => window.location.reload(), 900);
            } catch (err) {
                menuError(err.message || 'บันทึกเมนูไม่สำเร็จ ลองอีกครั้ง');
                saveButton.disabled = false;
            }
        });
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
