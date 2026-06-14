/* =====================================================
   dashboard.js — Upgraded Premium JS controller
===================================================== */

document.addEventListener('DOMContentLoaded', async function () {
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
    } catch (err) {
        console.error('Dashboard load error:', err);
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

        html += '<div class="widget-task-item">'
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
        html += '<div class="widget-event-item">'
            + '<span class="widget-event-dot" style="background:' + escHtml(dot) + '"></span>'
            + '<span class="widget-event-time">' + escHtml(timeStr) + '</span>'
            + '<span class="widget-event-title">' + escHtml(ev.title) + '</span>'
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
        +       '<div class="dash-finance-amount income">' + formatMoney(income) + '</div>'
        +   '</div>'
        +   '<div class="dash-finance-item">'
        +       '<div class="dash-finance-label">รายจ่าย</div>'
        +       '<div class="dash-finance-amount expense">' + formatMoney(expense) + '</div>'
        +   '</div>'
        + '</div>'
        + '<div class="dash-finance-bar-wrap">'
        +   '<div class="dash-finance-bar ' + barCls + '" style="width:' + pct + '%"></div>'
        + '</div>'
        + '<div class="dash-finance-balance">'
        +   '<span class="dash-finance-balance-label">คงเหลือ</span>'
        +   '<span class="dash-finance-balance-val ' + (balance < 0 ? 'negative' : 'positive') + '">'
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
    if (session.duration_min) details += '<span>' + session.duration_min + ' นาที</span>';
    if (session.sets && session.reps) details += '<span>' + session.sets + ' x ' + session.reps + '</span>';
    if (session.weight_kg)           details += '<span>' + session.weight_kg + ' กก.</span>';

    el.innerHTML =
        '<div class="widget-workout-card">'
        +   '<div class="widget-workout-top">'
        +       '<span class="workout-type-badge" data-wtype="' + escHtml(session.type) + '">' + escHtml(session.type) + '</span>'
        +       '<span class="widget-workout-ago">' + daysAgo + '</span>'
        +   '</div>'
        +   '<div class="widget-workout-date">' + formatDate(session.workout_date) + '</div>'
        +   (details ? '<div class="widget-workout-details">' + details + '</div>' : '')
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

        html += '<div class="widget-sub-item">'
            + '<div class="widget-sub-info">'
            +   '<span class="widget-sub-name">' + escHtml(sub.name) + '</span>'
            +   (amtLabel ? '<span class="widget-sub-amount">' + amtLabel + '</span>' : '')
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
            metaHtml += '<span>เดดไลน์: ' + formatDate(proj.due_date) + '</span>';
        }
        metaHtml += '<span>งาน: ' + done + '/' + total + '</span>';

        const badgeClasses = {
            'Planning': 'muted',
            'In Progress': 'warning',
            'Review': 'warning',
            'Completed': 'success'
        };
        const statusCls = badgeClasses[proj.status] || 'muted';

        html += '<div class="widget-project-item">'
            +   '  <div class="widget-project-top">'
            +   '    <span class="widget-project-name">' + escHtml(proj.name) + '</span>'
            +   '    <span class="wdg-badge ' + statusCls + '">' + escHtml(proj.status) + '</span>'
            +   '  </div>'
            +   '  <div class="widget-project-progress-wrap">'
            +   '    <div class="widget-project-progress-bar">'
            +   '      <div class="widget-project-progress-fill" style="width:' + pct + '%"></div>'
            +   '    </div>'
            +   '    <span class="widget-project-pct">' + pct + '%</span>'
            +   '  </div>'
            +   '  <div class="widget-project-meta">'
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

        html += '<div class="widget-note-item" onclick="window.location.href=\'' + BASE_URL + '/notes\'">'
            +   '  <div class="widget-note-header">'
            +   '    <span class="widget-note-title">'
            +          pinDotHtml
            +          escHtml(note.title)
            +   '    </span>'
            +   '    <span class="widget-note-time">' + formatRelativeTime(note.updated_at) + '</span>'
            +   '  </div>'
            +   '  <div class="widget-note-preview">' + escHtml(previewText) + '</div>'
            +      (tagHtml ? '<div class="widget-note-tags">' + tagHtml + '</div>' : '')
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

    let html = '';
    data.items.forEach(function (stk) {
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

        html += '<div class="widget-stock-item">'
            +   '  <div class="widget-stock-left">'
            +   '    <span class="widget-stock-ticker">' + escHtml(stk.ticker) + '</span>'
            +   '    <span class="widget-stock-market">' + escHtml(stk.market) + '</span>'
            +   '  </div>'
            +   '  <div class="widget-stock-right">'
            +   '    <span class="widget-stock-price">' + symbol + formattedPrice + '</span>'
            +   '    <span class="widget-stock-change-badge ' + badgeCls + '">' + chgText + '</span>'
            +   '  </div>'
            +   '</div>';
    });

    el.innerHTML = html;
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
        const statusBadge = isExpired
            ? '<span class="wdg-badge danger">หมดอายุ</span>'
            : '<span class="wdg-badge success">ใช้งานได้</span>';
            
        let sizeStr = formatSize(t.total_size);

        html += '<div class="widget-transfer-item" onclick="window.location.href=\'' + BASE_URL + '/transfer\'" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--color-border);">'
            +   '  <div class="widget-transfer-left" style="display:flex; flex-direction:column; gap:2px; overflow:hidden;">'
            +   '    <span class="widget-transfer-code" style="font-weight:600; font-size:0.95rem; color:var(--color-text);">' + escHtml(t.code) + '</span>'
            +   '    <span class="widget-transfer-meta" style="font-size:0.75rem; color:var(--color-muted); white-space:nowrap; text-overflow:ellipsis; overflow:hidden;">' + files.length + ' ไฟล์ · ' + sizeStr + ' · ดาวน์โหลด ' + t.download_count + ' ครั้ง</span>'
            +   '  </div>'
            +   '  <div class="widget-transfer-right" style="flex-shrink:0;">'
            +      statusBadge
            +   '  </div>'
            +   '</div>';
    });

    el.innerHTML = html;
}
