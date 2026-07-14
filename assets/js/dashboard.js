/* =====================================================
   dashboard.js — Upgraded Premium JS controller
===================================================== */

document.addEventListener('DOMContentLoaded', async function () {
    // Last update timestamp helper
    const updateLastTimestamp = () => {
        const lastUpdateEl = document.getElementById('headerLastUpdate');
        if (lastUpdateEl) {
            const now = new Date();
            lastUpdateEl.textContent = now.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
        }
    };

    // Initial Load
    try {
        const data = await apiFetch(BASE_URL + '/api/dashboard/summary');
        renderStatStrip(data);
        renderTasksWidget(data.tasks);
        renderCalendarWidget(data.calendar);
        renderFinanceWidget(data.finance);
        renderWorkoutWidget(data.workout);
        renderSubscriptionsWidget(data.subscriptions);
        renderProjectsWidget(data.projects);
        renderNotesWidget(data.notes);
        renderStocksWidget(data.stocks);
        renderTransferWidget(data.transfer);
        updateLastTimestamp();
        showDashboardWarnings(data);
    } catch (err) {
        console.error('Dashboard load error:', err);
        showDashboardError('โหลดข้อมูล Dashboard ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    }

    // Live Clock in Dashboard Header
    const clockEl = document.getElementById('headerLiveClock');
    if (clockEl) {
        const updateClock = () => {
            const now = new Date();
            clockEl.textContent = now.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        };
        updateClock();
        setInterval(updateClock, 1000);
    }

    // Refresh Button click handler
    const refreshBtn = document.getElementById('btnRefreshDashboard');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', async function () {
            if (refreshBtn.classList.contains('loading-spin')) return;
            refreshBtn.classList.add('loading-spin');
            try {
                const data = await apiFetch(BASE_URL + '/api/dashboard/summary');
                renderStatStrip(data);
                renderTasksWidget(data.tasks);
                renderCalendarWidget(data.calendar);
                renderFinanceWidget(data.finance);
                renderWorkoutWidget(data.workout);
                renderSubscriptionsWidget(data.subscriptions);
                renderProjectsWidget(data.projects);
                renderNotesWidget(data.notes);
                renderStocksWidget(data.stocks);
                renderTransferWidget(data.transfer);
                updateLastTimestamp();
                showDashboardWarnings(data);
            } catch (err) {
                console.error('Manual refresh error:', err);
                showDashboardError('รีเฟรชข้อมูลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
            } finally {
                setTimeout(() => refreshBtn.classList.remove('loading-spin'), 800);
            }
        });
    }

    // Sortable setup
    const grid = document.getElementById('dashboardGrid');
    if (grid && typeof Sortable !== 'undefined') {
        Sortable.create(grid, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            delay: 120, // Smooth touch delay to avoid scroll locking
            delayOnTouchOnly: true, // Maintain instant dragging on desktop
            touchStartThreshold: 7, // Tolerates tiny finger tremors before starting drag
            onEnd: saveLayout
        });
    }
});

function showDashboardWarnings(data) {
    const warnings = data?.meta?.warnings || [];
    if (warnings.length && typeof toast === 'function') {
        toast('บางโมดูลยังโหลดข้อมูลไม่ได้: ' + warnings.join(', '), 'warning');
    }
}

function showDashboardError(message) {
    if (typeof toast === 'function') {
        toast(message, 'error');
    }
}

function saveLayout() {
    const grid = document.getElementById('dashboardGrid');
    if (!grid) return;
    const widgets = [];
    
    // First, map the positions of visible ones
    grid.querySelectorAll('.widget').forEach(function (el, idx) {
        widgets.push({ widget_key: el.dataset.widget, position: idx, is_visible: 1 });
    });
    
    // Include hidden widgets to preserve their is_visible = 0 status
    if (window.dashboardLayout) {
        let maxPos = widgets.length;
        window.dashboardLayout.forEach(function (w) {
            const alreadyAdded = widgets.some(x => x.widget_key === w.widget_key);
            if (!alreadyAdded) {
                widgets.push({ widget_key: w.widget_key, position: maxPos++, is_visible: 0 });
            }
        });
    }
    
    apiFetch(BASE_URL + '/api/dashboard/layout', {
        method: 'POST',
        body: JSON.stringify({ widgets: widgets })
    }).catch(console.error);
}

/* ── Stat strip ── */
function renderStatStrip(data) {
    function set(valId, lblId, val, cls, lbl) {
        const v = document.getElementById(valId);
        const l = document.getElementById(lblId);
        if (v) { v.textContent = val; v.className = 'dash-strip-val' + (cls ? ' ' + cls : ''); }
        if (l && lbl) l.textContent = lbl;
    }

    // Tasks
    const over  = data.tasks?.overdue || 0;
    const items = data.tasks?.items?.length || 0;
    if (over > 0)
        set('ds-tasks-val', 'ds-tasks-lbl', over + ' รายการ', 'danger', 'เกินกำหนด');
    else
        set('ds-tasks-val', 'ds-tasks-lbl', items > 0 ? items + ' รายการ' : 'เรียบร้อย', items === 0 ? 'success' : '', 'งานใกล้ครบกำหนด');

    // Balance
    const bal = data.finance?.balance || 0;
    set('ds-balance-val', 'ds-balance-lbl', formatMoney(bal) + ' บาท', bal < 0 ? 'danger' : bal > 0 ? 'success' : '');

    // Last workout
    const last = data.workout?.last_session;
    if (last)
        set('ds-workout-val', 'ds-workout-lbl', last.type, '', formatDate(last.workout_date));
    else
        set('ds-workout-val', 'ds-workout-lbl', '—', '', 'ยังไม่มีบันทึก');

    // Next subscription
    const next = (data.subscriptions?.upcoming || [])[0];
    if (next) {
        const days = daysUntil(next.next_due_date);
        const cls  = days <= 0 ? 'danger' : days <= 3 ? 'warning' : '';
        const txt  = days === 0 ? 'วันนี้' : days < 0 ? 'เกิน ' + Math.abs(days) + ' วัน' : 'อีก ' + days + ' วัน';
        set('ds-subs-val', 'ds-subs-lbl', txt, cls, next.name);
    } else {
        set('ds-subs-val', 'ds-subs-lbl', '—', '', 'ไม่มีรายการใกล้ถึง');
    }

    // Dynamic greeting banner subtitle calculation
    const taskCount = over + items;
    const eventCount = data.calendar?.today_events?.length || 0;
    let welcomeTxt = '';
    
    if (taskCount > 0 && eventCount > 0) {
        welcomeTxt = `วันนี้คุณมีงานด่วน/ใกล้ครบกำหนด ${taskCount} รายการ และกำหนดการอีก ${eventCount} รายการ`;
    } else if (taskCount > 0) {
        welcomeTxt = `วันนี้คุณมีงานด่วน/ใกล้ครบกำหนด ${taskCount} รายการ มาสะสางกันเถอะ`;
    } else if (eventCount > 0) {
        welcomeTxt = `วันนี้คุณมีกำหนดการสำคัญ ${eventCount} รายการ เตรียมตัวให้พร้อมล่ะ!`;
    } else {
        welcomeTxt = `วันนี้ยังไม่มีกิจกรรมเร่งด่วน พักผ่อนและเรียนรู้สิ่งใหม่ ๆ ได้เต็มที่ครับ`;
    }
    
    const subEl = document.getElementById('dashWelcomeSubtitle');
    if (subEl) {
        const daysOfWeek = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
        const now = new Date();
        const displayDate = "วัน" + daysOfWeek[now.getDay()] + "ที่ " + formatDate(now.toISOString().slice(0, 10));
        subEl.textContent = `${displayDate} · ${welcomeTxt}`;
    }
}

/* ── Tasks Widget ── */
const QUADRANT_COLOR = { '1':'#f43f5e', '2':'#3b82f6', '3':'#f59e0b', '4':'#94a3b8' };

function renderTasksWidget(data) {
    const el = document.getElementById('widget-tasks');
    if (!el) return;

    if (!data || !data.items || data.items.length === 0) {
        el.innerHTML = renderEmpty('ไม่มีงานใกล้ครบกำหนด', '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>');
        return;
    }

    let html = '';

    if (data.overdue > 0) {
        html += '<div class="widget-alert-row">'
            + '<span class="widget-alert-badge danger">เกินกำหนด ' + data.overdue + ' รายการ</span>'
            + '</div>';
    }

    data.items.forEach(function (task) {
        const days = daysUntil(task.due_date);
        let badge = '';
        if (days !== null) {
            if      (days < 0)  badge = '<span class="wdg-badge danger">เกิน ' + Math.abs(days) + ' วัน</span>';
            else if (days === 0) badge = '<span class="wdg-badge warning">วันนี้</span>';
            else if (days <= 2)  badge = '<span class="wdg-badge warning">อีก ' + days + ' วัน</span>';
            else                 badge = '<span class="wdg-badge muted">อีก ' + days + ' วัน</span>';
        }

        const qColor = QUADRANT_COLOR[task.quadrant] || 'var(--color-border-2)';

        html += '<div class="widget-task-item" onclick="window.location.href=\'' + BASE_URL + '/tasks\'" style="cursor:pointer;">'
            + '<span class="widget-task-dot" style="background:' + qColor + '"></span>'
            + '<span class="widget-task-title">' + escHtml(task.title) + '</span>'
            + badge
            + '</div>';
    });

    el.innerHTML = html;
}

/* ── Calendar Widget ── */
function renderCalendarWidget(data) {
    const el = document.getElementById('widget-calendar');
    if (!el) return;

    if (!data || !data.today_events || data.today_events.length === 0) {
        el.innerHTML = renderEmpty('ไม่มีกำหนดการวันนี้', '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>');
        return;
    }

    let html = '';
    data.today_events.forEach(function (ev) {
        let timeStr = ev.is_all_day ? 'ทั้งวัน' : new Date(ev.start_datetime).toTimeString().slice(0, 5);
        const dot   = ev.color || '#6366f1';
        html += '<div class="widget-event-item" onclick="window.location.href=\'' + BASE_URL + '/planner\'" style="cursor:pointer;">'
            + '<span class="widget-event-dot" style="background:' + escHtml(dot) + '"></span>'
            + '<span class="widget-event-time">' + escHtml(timeStr) + '</span>'
            + '<span class="widget-event-title" style="color:var(--color-text); font-weight:500;">' + escHtml(ev.title) + '</span>'
            + '</div>';
    });

    el.innerHTML = html;
}

/* ── Finance Widget ── */
function renderFinanceWidget(data) {
    const el = document.getElementById('widget-finance');
    if (!el) return;

    const income  = data ? data.income  : 0;
    const expense = data ? data.expense : 0;
    const balance = data ? data.balance : 0;

    const pct    = income > 0 ? Math.min(100, Math.round(expense / income * 100)) : (expense > 0 ? 100 : 0);
    const barCls = pct >= 90 ? 'danger' : pct >= 70 ? 'warning' : 'ok';

    el.innerHTML =
        '<div class="dash-finance-row">'
        +   '<div class="dash-finance-item">'
        +       '<div class="dash-finance-label">รายรับ</div>'
        +       '<div class="dash-finance-amount income" style="color:var(--color-success); font-weight:700;">' + formatMoney(income) + '</div>'
        +   '</div>'
        +   '<div class="dash-finance-item">'
        +       '<div class="dash-finance-label">รายจ่าย</div>'
        +       '<div class="dash-finance-amount expense" style="color:var(--color-danger); font-weight:700;">' + formatMoney(expense) + '</div>'
        +   '</div>'
        + '</div>'
        + '<div class="dash-finance-bar-wrap" style="height:6px; background:var(--color-surface-2); border-radius:99px; overflow:hidden; margin:12px 0;">'
        +   '<div class="dash-finance-bar ' + barCls + '" style="height:100%; border-radius:99px; transition:width 0.6s ease; width:' + pct + '%"></div>'
        + '</div>'
        + '<div class="dash-finance-balance" style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--color-border); padding-top:8px;">'
        +   '<span class="dash-finance-balance-label" style="font-size:0.8rem; color:var(--color-muted);">คงเหลือเดือนนี้</span>'
        +   '<span class="dash-finance-balance-val ' + (balance < 0 ? 'negative' : 'positive') + '" style="font-weight:700; color:' + (balance >= 0 ? 'var(--color-success)' : 'var(--color-danger)') + ';">'
        +       (balance >= 0 ? '+' : '') + formatMoney(balance) + ' บาท'
        +   '</span>'
        + '</div>';
}

/* ── Workout Widget ── */
function renderWorkoutWidget(data) {
    const el = document.getElementById('widget-workout');
    if (!el) return;

    const session = data && data.last_session ? data.last_session : null;
    if (!session) {
        el.innerHTML = renderEmpty('ยังไม่มีการบันทึก', '<path d="M18 8h.01"/><path d="M6 8h.01"/><path d="M2 12h20"/><path d="M12 2v20"/>');
        return;
    }

    const days = daysUntil(session.workout_date);
    let daysAgo = '';
    if (days !== null) {
        const d = Math.abs(days);
        daysAgo = d === 0 ? 'วันนี้' : d + ' วันที่แล้ว';
    }

    let details = '';
    if (session.duration_min) details += '<span>⏱️ ' + session.duration_min + ' นาที</span>';
    if (session.sets && session.reps) details += '<span>🔁 ' + session.sets + ' x ' + session.reps + '</span>';
    if (session.weight_kg)           details += '<span>🏋️ ' + session.weight_kg + ' กก.</span>';

    el.innerHTML =
        '<div class="widget-workout-card" onclick="window.location.href=\'' + BASE_URL + '/exercise\'" style="cursor:pointer; display:flex; flex-direction:column; gap:8px;">'
        +   '<div class="widget-workout-top" style="display:flex; justify-content:space-between; align-items:center;">'
        +       '<span class="workout-type-badge" data-wtype="' + escHtml(session.type) + '" style="font-size:0.75rem; font-weight:700; background:rgba(34,197,94,0.12); color:#16a34a; padding:4px 10px; border-radius:8px;">' + escHtml(session.type) + '</span>'
        +       '<span class="widget-workout-ago" style="font-size:0.75rem; color:var(--color-muted);">' + daysAgo + '</span>'
        +   '</div>'
        +   '<div class="widget-workout-date" style="font-size:0.82rem; color:var(--color-muted);">' + formatDate(session.workout_date) + '</div>'
        +   (details ? '<div class="widget-workout-details" style="display:flex; gap:6px; flex-wrap:wrap; margin-top:4px;">' + details + '</div>' : '')
        + '</div>';
}

/* ── Subscriptions Widget ── */
function renderSubscriptionsWidget(data) {
    const el = document.getElementById('widget-subscriptions');
    if (!el) return;

    if (!data || !data.upcoming || data.upcoming.length === 0) {
        el.innerHTML = renderEmpty('ไม่มีรายการใกล้ถึงในอีก 7 วัน', '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>');
        return;
    }

    let html = '';
    data.upcoming.forEach(function (sub) {
        const days = daysUntil(sub.next_due_date);
        let badgeCls = 'muted';
        if      (days <= 0) badgeCls = 'danger';
        else if (days <= 3) badgeCls = 'warning';

        const daysLabel = days === 0 ? 'วันนี้' : days < 0 ? 'เกิน ' + Math.abs(days) + ' วัน' : 'อีก ' + days + ' วัน';
        const amtLabel  = sub.amount > 0 ? formatMoney(sub.amount) + ' บาท' : '';

        html += '<div class="widget-sub-item" onclick="window.location.href=\'' + BASE_URL + '/subscriptions\'" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--color-border);">'
            + '<div class="widget-sub-info" style="display:flex; flex-direction:column; gap:2px;">'
            +   '<span class="widget-sub-name" style="font-weight:600; color:var(--color-text);">' + escHtml(sub.name) + '</span>'
            +   (amtLabel ? '<span class="widget-sub-amount" style="font-size:0.72rem; color:var(--color-muted);">' + amtLabel + '</span>' : '')
            + '</div>'
            + '<span class="wdg-badge ' + badgeCls + '">' + daysLabel + '</span>'
            + '</div>';
    });

    el.innerHTML = html;
}

/* ── Projects Widget ── */
function renderProjectsWidget(data) {
    const el = document.getElementById('widget-projects');
    if (!el) return;

    if (!data || !data.items || data.items.length === 0) {
        el.innerHTML = renderEmpty('ไม่มีโครงการที่กำลังดำเนินการ', '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M9 3v18"/>');
        return;
    }

    let html = '';
    data.items.forEach(function (proj) {
        const total = parseInt(proj.total_tasks) || 0;
        const done  = parseInt(proj.completed_tasks) || 0;
        const pct   = total > 0 ? Math.round((done / total) * 100) : 0;
        
        let metaHtml = '';
        if (proj.due_date) {
            metaHtml += '<span>📅 เดดไลน์: ' + formatDate(proj.due_date) + '</span>';
        }
        metaHtml += '<span>🎯 งานเสร็จ: ' + done + '/' + total + '</span>';

        const badgeClasses = {
            'Planning': 'muted',
            'In Progress': 'warning',
            'Review': 'warning',
            'Completed': 'success'
        };
        const statusCls = badgeClasses[proj.status] || 'muted';

        html += '<div class="widget-project-item" onclick="window.location.href=\'' + BASE_URL + '/projects\'" style="cursor:pointer; padding:12px 0; border-bottom:1px solid var(--color-border); display:flex; flex-direction:column; gap:8px;">'
            +   '  <div class="widget-project-top" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">'
            +   '    <span class="widget-project-name" style="font-weight:600; color:var(--color-text);">' + escHtml(proj.name) + '</span>'
            +   '    <span class="wdg-badge ' + statusCls + '">' + escHtml(proj.status) + '</span>'
            +   '  </div>'
            +   '  <div class="widget-project-progress-wrap" style="display:flex; align-items:center; gap:12px;">'
            +   '    <div class="widget-project-progress-bar" style="flex:1; height:5px; background:var(--color-surface-2); border-radius:99px; overflow:hidden;">'
            +   '      <div class="widget-project-progress-fill" style="height:100%; background:#06b6d4; border-radius:99px; transition:width 0.6s cubic-bezier(0.16, 1, 0.3, 1); width:' + pct + '%"></div>'
            +   '    </div>'
            +   '    <span class="widget-project-pct" style="font-size:0.72rem; font-weight:600; color:var(--color-muted); min-width:28px; text-align:right;">' + pct + '%</span>'
            +   '  </div>'
            +   '  <div class="widget-project-meta" style="display:flex; justify-content:space-between; font-size:0.72rem; color:var(--color-muted);">'
            +       metaHtml
            +   '  </div>'
            +   '</div>';
    });

    el.innerHTML = html;
}

/* ── Notes Widget ── */
function renderNotesWidget(data) {
    const el = document.getElementById('widget-notes');
    if (!el) return;

    if (!data || !data.items || data.items.length === 0) {
        el.innerHTML = renderEmpty('ไม่มีโน้ตบันทึกไว้ล่าสุด', '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>');
        return;
    }

    let html = '';
    data.items.forEach(function (note) {
        let tagHtml = '';
        if (note.tags_list) {
            const list = note.tags_list.split(',');
            list.slice(0, 3).forEach(function (t) {
                tagHtml += '<span class="widget-note-tag-chip">' + escHtml(t) + '</span>';
            });
        }

        const previewText = note.is_encrypted ? 'เนื้อหานี้ได้รับการเข้ารหัสความปลอดภัย' : (note.preview || 'ไม่มีเนื้อหาหลัก');
        const pinDotHtml = note.pinned ? '<span class="widget-note-pinned-dot"></span>' : '';

        html += '<div class="widget-note-item" onclick="window.location.href=\'' + BASE_URL + '/notes\'" style="cursor:pointer; display:flex; flex-direction:column; gap:4px; padding:10px 0; border-bottom:1px solid var(--color-border);">'
            +   '  <div class="widget-note-header" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">'
            +   '    <span class="widget-note-title" style="font-weight:600; color:var(--color-text); display:flex; align-items:center; gap:6px;">'
            +          pinDotHtml
            +          escHtml(note.title)
            +   '    </span>'
            +   '    <span class="widget-note-time" style="font-size:0.72rem; color:var(--color-muted-2);">' + formatRelativeTime(note.updated_at) + '</span>'
            +   '  </div>'
            +   '  <div class="widget-note-preview" style="font-size:0.78rem; color:var(--color-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + escHtml(previewText) + '</div>'
            +      (tagHtml ? '<div class="widget-note-tags" style="display:flex; gap:4px; margin-top:2px;">' + tagHtml + '</div>' : '')
            +   '</div>';
    });

    el.innerHTML = html;
}

/* ── Stocks Widget ── */
function renderStocksWidget(data) {
    const el = document.getElementById('widget-stocks');
    if (!el) return;

    if (!data || !data.items || data.items.length === 0) {
        el.innerHTML = renderEmpty('ยังไม่มีความเคลื่อนไหวในพอร์ตหรือรายการสนใจ', '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>');
        return;
    }

    let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';
    data.items.forEach(function (stk, idx) {
        const change = parseFloat(stk.day_change_pct);
        const hasChange = change !== null && !isNaN(change);
        let chgText = '0.00%';
        let badgeCls = 'muted';
        
        if (hasChange) {
            if (change > 0) {
                chgText = '+' + change.toFixed(2) + '%';
                badgeCls = 'positive';
            } else if (change < 0) {
                chgText = change.toFixed(2) + '%';
                badgeCls = 'negative';
            }
        }

        const formattedPrice = stk.last_price !== null ? parseFloat(stk.last_price).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—';
        const symbol = stk.currency === 'THB' ? '฿' : '$';
        const canvasId = 'sparkline-' + idx;

        html += '<div class="widget-stock-item" onclick="window.location.href=\'' + BASE_URL + '/stocks\'" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--color-border);">'
            +   '  <div class="widget-stock-left" style="min-width:70px;">'
            +   '    <span class="widget-stock-ticker" style="font-weight:700; font-size:0.95rem; color:var(--color-text);">' + escHtml(stk.ticker) + '</span>'
            +   '    <span class="widget-stock-market" style="font-size:0.68rem; color:var(--color-muted-2); text-transform:uppercase; display:block;">' + escHtml(stk.market) + '</span>'
            +   '  </div>'
            +   '  <div class="stock-sparkline-wrap" style="flex:1; display:flex; justify-content:center;">'
            +   '    <canvas class="stock-sparkline" id="' + canvasId + '" width="70" height="24" data-change="' + change + '"></canvas>'
            +   '  </div>'
            +   '  <div class="widget-stock-right" style="display:flex; align-items:center; gap:10px; text-align:right;">'
            +   '    <span class="widget-stock-price" style="font-weight:600; font-size:0.9rem; color:var(--color-text);">' + symbol + formattedPrice + '</span>'
            +   '    <span class="widget-stock-change-badge ' + badgeCls + '">' + chgText + '</span>'
            +   '  </div>'
            +   '</div>';
    });
    html += '</div>';
    el.innerHTML = html;

    // Draw sparklines on the canvas items
    data.items.forEach(function (stk, idx) {
        const change = parseFloat(stk.day_change_pct) || 0;
        drawSparkline('sparkline-' + idx, change);
    });
}

/* ── Live Sparkline Canvas Drawing ── */
function drawSparkline(canvasId, change) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    
    const width = canvas.width;
    const height = canvas.height;
    
    // Clear canvas
    ctx.clearRect(0, 0, width, height);
    
    // Define color based on change
    let color = '#64748b'; // neutral slate
    let gradColor = 'rgba(100, 116, 139, 0.05)';
    if (change > 0) {
        color = '#22c55e'; // success green
        gradColor = 'rgba(34, 197, 94, 0.08)';
    } else if (change < 0) {
        color = '#ef4444'; // danger red
        gradColor = 'rgba(239, 68, 68, 0.08)';
    }
    
    // Generate pseudo-random points biased by change
    const points = [];
    const steps = 6;
    const segment = width / (steps - 1);
    
    // Fixed seed based on canvasId string sum to make the graph consistent per ticker
    let seed = 0;
    for (let i = 0; i < canvasId.length; i++) {
        seed += canvasId.charCodeAt(i);
    }
    
    function seededRandom(max, min) {
        seed = (seed * 9301 + 49297) % 233280;
        const rnd = seed / 233280;
        return min + rnd * (max - min);
    }
    
    // Baseline starts at center
    points.push({ x: 0, y: height * 0.5 });
    
    // Generate walking path
    for (let i = 1; i < steps - 1; i++) {
        let yOffset = seededRandom(-height * 0.25, height * 0.25);
        // Add a slight trend bias
        if (change > 0) yOffset -= (i / steps) * height * 0.15;
        if (change < 0) yOffset += (i / steps) * height * 0.15;
        
        let y = height * 0.5 + yOffset;
        y = Math.max(height * 0.1, Math.min(height * 0.9, y));
        points.push({ x: i * segment, y: y });
    }
    
    // End point based on actual change direction
    let finalY = height * 0.5;
    if (change > 0) finalY = height * 0.25 - seededRandom(0, height * 0.15);
    if (change < 0) finalY = height * 0.75 + seededRandom(0, height * 0.15);
    finalY = Math.max(height * 0.1, Math.min(height * 0.9, finalY));
    points.push({ x: width, y: finalY });
    
    // Draw line
    ctx.beginPath();
    ctx.strokeStyle = color;
    ctx.lineWidth = 1.8;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    
    ctx.moveTo(points[0].x, points[0].y);
    for (let i = 1; i < points.length; i++) {
        // Smooth curve drawing using bezier controls
        const xc = (points[i - 1].x + points[i].x) / 2;
        const yc = (points[i - 1].y + points[i].y) / 2;
        ctx.quadraticCurveTo(points[i - 1].x, points[i - 1].y, xc, yc);
    }
    ctx.lineTo(points[points.length - 1].x, points[points.length - 1].y);
    ctx.stroke();
    
    // Draw fill gradient below sparkline
    ctx.lineTo(width, height);
    ctx.lineTo(0, height);
    ctx.closePath();
    
    const grad = ctx.createLinearGradient(0, 0, 0, height);
    grad.addColorStop(0, gradColor);
    grad.addColorStop(1, 'transparent');
    ctx.fillStyle = grad;
    ctx.fill();
}

/* ── Helpers ── */
function renderEmpty(msg, iconSvg) {
    let svgHtml = '';
    if (iconSvg) {
        svgHtml = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:6px; opacity:0.3">' + iconSvg + '</svg>';
    }
    return '<div class="widget-empty"><div class="widget-empty-content">' + svgHtml + '<span>' + msg + '</span></div></div>';
}

function escHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatRelativeTime(mysqlDate) {
    if (!mysqlDate) return '';
    const date = new Date(mysqlDate.replace(/-/g, '/'));
    const now  = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'เมื่อครู่';
    if (diff < 3600) return Math.floor(diff / 60) + ' นาทีที่แล้ว';
    if (diff < 86400) return Math.floor(diff / 3600) + ' ชั่วโมงที่แล้ว';
    
    // Fallback to formatted date
    return date.toLocaleDateString('th-TH', { month: 'short', day: 'numeric' });
}

/* ── Transfer Widget ── */
function renderTransferWidget(data) {
    const el = document.getElementById('widget-transfer');
    if (!el) return;

    if (!data || !data.items || data.items.length === 0) {
        el.innerHTML = renderEmpty('ยังไม่มีประวัติการส่ง', '<path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9l20-7z"/>');
        return;
    }

    function formatSize(bytes) {
        bytes = Number(bytes);
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    }

    let html = '';
    data.items.forEach(function (t) {
        const files = JSON.parse(t.files_json || '[]');
        const isExpired = t.is_expired;
        const sizeStr = formatSize(t.total_size);
        
        // Copy helper
        const copyText = BASE_URL + '/transfer?code=' + t.code;

        html += '<div class="widget-transfer-item" style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--color-border);">'
            +   '  <div class="widget-transfer-left" onclick="window.location.href=\'' + BASE_URL + '/transfer\'" style="cursor:pointer; display:flex; flex-direction:column; gap:2px; overflow:hidden; flex:1;">'
            +   '    <span class="widget-transfer-code" style="font-weight:600; font-size:0.95rem; color:var(--color-text);">' + escHtml(t.code) + '</span>'
            +   '    <span class="widget-transfer-meta" style="font-size:0.75rem; color:var(--color-muted); white-space:nowrap; text-overflow:ellipsis; overflow:hidden;">' + files.length + ' ไฟล์ · ' + sizeStr + ' · ดาวน์โหลด ' + t.download_count + ' ครั้ง</span>'
            +   '  </div>'
            +   '  <div class="widget-transfer-right" style="flex-shrink:0; display:flex; align-items:center; gap:8px;">'
            +   '    <button class="btn-copy-code" onclick="event.stopPropagation(); navigator.clipboard.writeText(\'' + copyText + '\'); toast(\'คัดลอกลิงก์รับไฟล์แล้ว\', \'success\');">คัดลอกลิงก์</button>'
            +        (isExpired ? '<span class="wdg-badge danger">หมดอายุ</span>' : '<span class="wdg-badge success">ใช้งานได้</span>')
            +   '  </div>'
            +   '</div>';
    });

    el.innerHTML = html;
}
