// =====================================================
// settings-menus.js — Settings: menu order and dashboard widgets
// Loaded after settings.js; classic scripts, so $ and $$ are local here.
// =====================================================
(function () {
    'use strict';
    const $ = (s, c) => (c || document).querySelector(s);
    const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

    // ---------- Manage Menus ----------
    function initMenus() {
        const list = $('#menuVisibilityList');
        if (!list) return;

        const rows = $$('input[name="visible_menus[]"]', list).map(input => {
            const row = input.closest('label');
            if (!row) return null;
            row.classList.add('menu-order-item');
            row.dataset.menuKey = input.value;
            row.setAttribute('draggable', 'true');
            if (!row.querySelector('.menu-drag-handle')) {
                const handle = document.createElement('span');
                handle.className = 'menu-drag-handle';
                handle.textContent = '⋮⋮';
                handle.setAttribute('aria-hidden', 'true');
                row.prepend(handle);
            }
            if (!row.querySelector('[data-menu-move="up"]')) {
                const actions = document.createElement('span');
                actions.className = 'menu-order-actions';
                actions.innerHTML = '<button type="button" class="menu-move-btn" data-menu-move="up" aria-label="เลื่อนเมนูขึ้น">↑</button><button type="button" class="menu-move-btn" data-menu-move="down" aria-label="เลื่อนเมนูลง">↓</button>';
                row.append(actions);
            }
            return row;
        }).filter(Boolean);

        try {
            const savedOrder = JSON.parse(list.dataset.menuOrder || '[]');
            const rank = new Map(savedOrder.map((key, index) => [key, index]));
            rows.sort((a, b) => (rank.get(a.dataset.menuKey) ?? 999) - (rank.get(b.dataset.menuKey) ?? 999));
            rows.forEach(row => list.append(row));
        } catch (_) {}

        if (window.Sortable && !list.dataset.sortableReady) {
            window.Sortable.create(list, {
                animation: 180,
                handle: '.menu-drag-handle',
                ghostClass: 'sortable-ghost'
            });
            list.dataset.sortableReady = '1';
        }

        list.addEventListener('click', event => {
            const button = event.target.closest('[data-menu-move]');
            if (!button) return;
            const row = button.closest('.menu-order-item');
            if (!row) return;
            const target = button.dataset.menuMove === 'up' ? row.previousElementSibling : row.nextElementSibling;
            if (!target) return;
            if (button.dataset.menuMove === 'up') list.insertBefore(row, target);
            else list.insertBefore(target, row);
        });

        $('#btnSaveMenus')?.addEventListener('click', async () => {
            const saveButton = $('#btnSaveMenus');
            const checkedBoxes = $$('input[name="visible_menus[]"]:checked');
            const visibleMenus = checkedBoxes.map(cb => cb.value);
            const order = $$('.menu-order-item', list).map(row => row.dataset.menuKey);

            try {
                if (saveButton) {
                    saveButton.disabled = true;
                    saveButton.dataset.originalText = saveButton.textContent;
                    saveButton.textContent = 'กำลังบันทึก...';
                }
                await apiFetch(BASE_URL + '/api/settings/menus', {
                    method: 'POST',
                    body: JSON.stringify({ menus: visibleMenus, order })
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
            finally {
                if (saveButton) {
                    saveButton.disabled = false;
                    saveButton.textContent = saveButton.dataset.originalText || 'บันทึกการตั้งค่าเมนู';
                }
            }
        });
    }

    // ---------- Dashboard Customization ----------
    function initDashboardConfig() {
        const btnReset = $('#btnResetDashboardLayout');
        if (btnReset) {
            btnReset.addEventListener('click', function() {
                confirmAction('คุณต้องการรีเซ็ตลำดับการแสดงผลและเปิดวิดเจ็ตทั้งหมดเป็นค่าเริ่มต้นใช่หรือไม่?', 'รีเซ็ต', 'ยืนยันรีเซ็ต').then(async ok => {
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
