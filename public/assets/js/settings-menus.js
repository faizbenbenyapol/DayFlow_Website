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
                    
                    const defaults = [
                        { widget_key: 'tasks',         position: 0, is_visible: 1 },
                        { widget_key: 'calendar',      position: 1, is_visible: 1 },
                        { widget_key: 'finance',       position: 2, is_visible: 1 },
                        { widget_key: 'workout',       position: 3, is_visible: 1 },
                        { widget_key: 'subscriptions', position: 4, is_visible: 1 },
                        { widget_key: 'projects',      position: 5, is_visible: 1 },
                        { widget_key: 'notes',         position: 6, is_visible: 1 },
                        { widget_key: 'stocks',        position: 7, is_visible: 1 },
                        { widget_key: 'transfer',      position: 8, is_visible: 1 }
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


    document.addEventListener('DOMContentLoaded', () => {
        initMenus();
        initDashboardConfig();
    });
})();
