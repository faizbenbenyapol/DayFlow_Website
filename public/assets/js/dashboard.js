/* =====================================================
   dashboard.js — the Today page

   views/dashboard/index.php draws the frame with a skeleton in every section;
   this fills them from /api/dashboard/summary. Only the sections on the page
   are asked for, so a section switched off in settings costs no queries.

   A row can be ticked in place (a task, a habit). A task or note added from
   the quick-add sheet refreshes the page's data instead of reloading it.
===================================================== */

(function () {
    const root = document.getElementById('today');
    if (!root) return;

    const QUADRANT = {
        1: 'สำคัญ + เร่งด่วน',
        2: 'สำคัญ + ไม่เร่งด่วน',
        3: 'ไม่สำคัญ + เร่งด่วน',
        4: 'ไม่สำคัญ + ไม่เร่งด่วน',
    };
    const PROJECT_STATUS = { 'Planning': 'วางแผน', 'In Progress': 'กำลังทำ', 'Review': 'ตรวจทาน', 'Completed': 'เสร็จแล้ว' };
    const WEEKDAY = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
    const MINUS = '−'; // a real minus sign, the width of a plus, so money columns line up

    const tally = document.getElementById('todayTally');
    const esc = escHtml;

    // Browser clock minus the server's wall clock, both read as local time.
    // Timestamps come in the server's zone; comparing them with this instead
    // of Date.now() keeps "5 นาทีที่แล้ว" right in any time zone.
    let clockGap = 0;
    let reloadTimer = null;

    /* ---------- small helpers ---------- */

    const section = key => root.querySelector(`[data-section="${key}"]`);
    const bodyOf = key => section(key)?.querySelector('[data-body]') ?? null;

    function setCount(key, text) {
        const el = section(key)?.querySelector('[data-count]');
        if (el) el.textContent = text;
    }

    function money(amount) {
        return formatMoney(Math.abs(amount));
    }

    /** "1 ชม. 30 นาที" for a number of minutes. */
    function duration(minutes) {
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        return [h ? h + ' ชม.' : '', m ? m + ' นาที' : ''].filter(Boolean).join(' ');
    }

    /** A MySQL datetime, read as local time. */
    function parseLocal(stamp) {
        return new Date(String(stamp).replace(' ', 'T'));
    }

    function dayWord(dateStr) {
        const days = serverDaysUntil(dateStr);
        if (days === 0) return 'วันนี้';
        if (days === -1) return 'เมื่อวาน';
        if (days < 0) return Math.abs(days) + ' วันที่แล้ว';
        return 'อีก ' + days + ' วัน';
    }

    function relativeTime(stamp) {
        if (!stamp) return '';
        const seconds = Math.floor((Date.now() - clockGap - parseLocal(stamp)) / 1000);
        if (seconds < 60) return 'เมื่อครู่';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' นาทีที่แล้ว';
        if (seconds < 86400) return Math.floor(seconds / 3600) + ' ชั่วโมงที่แล้ว';
        return parseLocal(stamp).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
    }

    function fileSize(bytes) {
        bytes = Number(bytes);
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    }

    /** One line of "what is empty and what to do about it". */
    function empty(message, action = '') {
        return `<div class="empty-state"><p class="empty-state-text">${message}</p>${action}</div>`;
    }

    const link = (path, text) => `<a href="${BASE_URL}${path}">${text}</a>`;
    const row = (inner, attrs = '', cls = '') => `<li class="ruled-row${cls ? ' ' + cls : ''}"${attrs}>${inner}</li>`;
    const list = (rows, extra = '') => `<ul class="ruled-list${extra}">${rows.join('')}</ul>`;

    /* ---------- sections ---------- */

    /** แปลงวันที่ YYYY-MM-DD เป็นรูปแบบสั้น เช่น "20 พ.ย." */
    function formatShortDate(dateStr) {
        if (!dateStr) return '';
        const d = parseServerDate(dateStr);
        const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        return d.getDate() + ' ' + months[d.getMonth()];
    }

    /** สร้างบรรทัดเมต้าของงานค้าง: "เลย N วัน · วันเดิม" พร้อมบอกงานทำซ้ำ */
    function overdueMeta(dueDate, repeatRule) {
        const days = serverDaysUntil(dueDate);
        const lateText = days === -1 ? 'เมื่อวาน' : `เลย ${Math.abs(days)} วัน`;
        let meta = `<span class="late-date">${lateText}</span> · ${formatShortDate(dueDate)}`;
        if (repeatRule && repeatRule !== 'none') {
            meta += ' · ทำซ้ำ…';
        }
        return meta;
    }

    const sub = key => root.querySelector(`[data-subsection="${key}"]`);
    const bodyOfSub = key => sub(key)?.querySelector('[data-body]') ?? null;
    function setSubCount(key, text) {
        const el = sub(key)?.querySelector('[data-count]');
        if (el) el.textContent = text;
    }
    function setSubHidden(key, hide) {
        const el = sub(key);
        if (el) el.hidden = hide;
    }

    /** สร้างเมนูปุ่ม ⋯ ของแถวงานค้าง */
    function overdueMenu(taskId) {
        const items = [
            ['today', 'ทำวันนี้'],
            ['tomorrow', 'พรุ่งนี้'],
            ['date', 'เลือกวัน…'],
            ['clear', 'เอาวันที่ออก'],
            ['delete', 'ลบ'],
        ];
        return '<ul class="overdue-menu" hidden data-menu-for="' + Number(taskId) + '">'
            + items.map(([action, label]) => `<li><button type="button" class="overdue-menu-action${action === 'delete' ? ' text-danger' : ''}" data-act="overdueAction" data-task-id="${Number(taskId)}" data-action="${esc(action)}">${esc(label)}</button></li>`).join('')
            + '</ul>';
    }

    /** สร้าง HTML ของแถวงานค้าง พับแถวที่ 6 เป็นต้นไปไว้ก่อน */
    function overdueRow(task, idx) {
        const extraClass = idx >= 5 ? ' overdue-extra' : '';
        return row(
            `<input type="checkbox" class="task-tick" aria-label="เสร็จแล้ว: ${esc(task.title)}">`
            + `<span class="grow"><span class="title"><span class="done-mark">${esc(task.title)}</span></span><span class="meta">${overdueMeta(task.due_date, task.repeat_rule)}</span></span>`
            + `<button type="button" class="icon-btn sm overdue-menu-btn" aria-label="จัดการงาน: ${esc(task.title)}" data-act="openOverdueMenu" data-task-id="${Number(task.id)}">⋯</button>`
            + overdueMenu(task.id),
            ` data-task="${Number(task.id)}"`,
            'overdue-row' + extraClass
        );
    }

    /** วาดรายการงานค้าง: 5 แถวแรก พับที่เหลือที่โหลดมา และบอกจำนวนที่ยังไม่ได้โหลด */
    function renderOverdue(items, total, keepExpanded = false) {
        const body = bodyOfSub('overdue');
        if (!body) return;

        setSubCount('overdue', total > 0 ? `${total} งาน · เก่าสุดก่อน` : '');
        const label = sub('overdue')?.querySelector('[data-bulk-label]');
        if (label) {
            label.textContent = total > 0 ? `ทั้ง ${total} งาน →` : '';
            label.dataset.total = String(total);
        }

        if (items.length === 0) {
            body.innerHTML = empty('ไม่มีงานค้าง');
            return;
        }

        const shown = 5;
        const expandAll = keepExpanded || items.length <= shown;
        let rowsHtml = list(items.map((t, i) => overdueRow(t, expandAll ? 0 : i)));
        if (items.length > shown && !keepExpanded) {
            rowsHtml += `<button type="button" class="btn btn-link more-overdue" data-act="expandOverdue">แสดงอีก ${items.length - shown} งาน</button>`;
        }
        if (total > items.length) {
            rowsHtml += `<p class="subhead">และอีก ${total - items.length} งาน · ${link('/tasks', 'ดูทั้งหมด')}</p>`;
        }
        body.innerHTML = rowsHtml;
    }

    /** วาดรายการงานวันนี้ ถ้ามีมากกว่าที่โหลดมาให้บอกจำนวนที่เหลือและลิงก์ไปหน้างาน */
    function renderToday(items, total) {
        const body = bodyOfSub('today');
        if (!body) return;
        setSubCount('today', total > 0 ? String(total) : '');

        if (items.length === 0) {
            body.innerHTML = '';
            return;
        }
        let rowsHtml = list(items.map(t => row(
            `<input type="checkbox" class="task-tick" aria-label="เสร็จแล้ว: ${esc(t.title)}">`
            + `<span class="grow"><span class="title"><span class="done-mark">${esc(t.title)}</span></span>${QUADRANT[t.quadrant] ? `<span class="meta">${QUADRANT[t.quadrant]}</span>` : ''}</span>`,
            ` data-task="${Number(t.id)}"`
        )));
        if (total > items.length) {
            rowsHtml += `<p class="subhead">และอีก ${total - items.length} งาน · ${link('/tasks', 'ดูทั้งหมด')}</p>`;
        }
        body.innerHTML = rowsHtml;
    }

    /** สร้างบรรทัดเมต้าของงานโปรเจกต์ที่มอบให้ฉัน */
    function projectMeta(dueDate) {
        const days = serverDaysUntil(dueDate);
        if (days === 0) return 'วันนี้';
        if (days < 0) return `<span class="late-date">เลย ${Math.abs(days)} วัน</span> · ${formatShortDate(dueDate)}`;
        return formatShortDate(dueDate);
    }

    /** วาดรายการงานโปรเจกต์ที่มอบให้ผู้ใช้ปัจจุบัน */
    function renderMyProject(items) {
        const body = bodyOfSub('my-project');
        if (!body) return;
        setSubCount('my-project', items.length > 0 ? String(items.length) : '');

        if (items.length === 0) {
            body.innerHTML = '';
            return;
        }
        body.innerHTML = list(items.map(t => {
            const canEdit = !!t.can_edit;
            const control = canEdit
                ? `<input type="checkbox" class="project-tick" aria-label="เสร็จแล้ว: ${esc(t.title)}">`
                : `<span class="readonly">ดูอย่างเดียว</span>`;
            return row(
                control
                + `<span class="grow"><span class="title">${esc(t.title)}</span><span class="meta">${projectMeta(t.due_date)}</span></span>`
                + `<span class="chip project-chip">${esc(t.project_name)}</span>`
                + `<a class="icon-btn sm" href="${BASE_URL}/projects?project=${Number(t.project_id)}" aria-label="เปิดในโปรเจกต์ ${esc(t.project_name)}">↗</a>`,
                ` data-project-task="${Number(t.id)}" data-project-task-status="${esc(t.status || '')}"`
            );
        }));
    }

    /** วาดส่วนงานทั้งหมด พร้อมรักษาสถานะ "แสดงอีก" ของงานค้าง */
    function renderTasks(data, keepOverdueExpanded = false) {
        const overdueCount = data?.overdue || 0;
        const todayCount = data?.due_today || 0;
        const overdueItems = data?.overdue_items || [];
        const todayItems = data?.today_items || [];
        const projectItems = data?.my_project_items || [];
        const hasAnything = overdueCount > 0 || todayCount > 0 || projectItems.length > 0;

        setSubHidden('overdue', overdueCount === 0);
        setSubHidden('today', false);
        setSubHidden('my-project', projectItems.length === 0);
        setSubHidden('empty', hasAnything);

        renderOverdue(overdueItems, overdueCount, keepOverdueExpanded);
        renderToday(todayItems, todayCount);
        renderMyProject(projectItems);
    }

    function renderCalendar(data) {
        const el = bodyOf('calendar');
        if (!el) return;

        const events = data?.today_events || [];
        setCount('calendar', events.length ? String(events.length) : '');
        if (events.length === 0) {
            el.innerHTML = empty('ไม่มีนัดวันนี้', link('/planner', 'เพิ่มนัด'));
            return;
        }

        const now = new Date();
        const rows = [];
        let nowPlaced = false;
        let pastSeen = false;

        // All-day events first, then by start time (the server already sorted by start).
        const ordered = events.filter(e => e.is_all_day).concat(events.filter(e => !e.is_all_day));

        ordered.forEach(ev => {
            const start = parseLocal(ev.start_datetime);
            const end = ev.end_datetime ? parseLocal(ev.end_datetime) : start;
            const timed = !ev.is_all_day;
            const over = timed && end < now;
            if (over) pastSeen = true;

            // The line goes between what has happened and what has not.
            if (timed && !over && pastSeen && !nowPlaced) {
                rows.push(`<li class="now-line" aria-label="ตอนนี้ ${now.toTimeString().slice(0, 5)}">${now.toTimeString().slice(0, 5)}</li>`);
                nowPlaced = true;
            }

            const minutes = timed && end > start ? Math.round((end - start) / 60000) : 0;
            rows.push(row(
                `<span class="time">${timed ? start.toTimeString().slice(0, 5) : 'ทั้งวัน'}</span>`
                + `<span class="grow"><span class="title">${esc(ev.title)}</span>${minutes ? `<span class="meta">${duration(minutes)}</span>` : ''}</span>`,
                '',
                over ? 'past' : ''
            ));
        });

        // Everything is over: the line closes the day.
        if (pastSeen && !nowPlaced) {
            rows.push(`<li class="now-line" aria-label="ตอนนี้ ${now.toTimeString().slice(0, 5)}">${now.toTimeString().slice(0, 5)}</li>`);
        }
        el.innerHTML = list(rows);
    }

    function renderHabits(data) {
        const el = bodyOf('habits');
        if (!el) return;

        const items = data?.items || [];
        setCount('habits', items.length ? `${data.done} จาก ${data.total}` : '');
        if (items.length === 0) {
            el.innerHTML = empty('ยังไม่ได้ตั้งนิสัย', link('/habits', 'เพิ่มนิสัยแรก'));
            return;
        }

        // A habit done today gets a star stuck on, in the habit's own colour
        // softened into the paper; a run of days shows as a flame.
        el.innerHTML = list(items.map(h => row(
            `<input type="checkbox" class="habit-tick star-tick" style="--habit-c: color-mix(in srgb, ${cssColor(h.color, '#10b981')} 45%, var(--paper))"${h.done_today ? ' checked' : ''} aria-label="ทำแล้ววันนี้: ${esc(h.name)}">`
            + `<span class="grow"><span class="title">${esc(h.name)}</span></span>`
            + (h.streak > 0
                ? `<span class="side streak" title="ทำติดกัน ${Number(h.streak)} วัน"><svg class="flame" viewBox="0 0 20 24" aria-hidden="true"><path d="M10 1c1 5 7 7 7 14a7 7 0 0 1-14 0c0-4 2-6 3-8 0 3 1 4 2 4 0-4 0-7 2-10z"/></svg>${Number(h.streak)} วัน</span>`
                : '<span class="side">เริ่มใหม่</span>'),
            ` data-habit="${Number(h.id)}"`
        )), ' habits');
    }

    function renderFinance(data) {
        const el = bodyOf('finance');
        if (!el) return;

        const income = data?.income || 0;
        const expense = data?.expense || 0;
        const balance = data?.balance || 0;
        if (income === 0 && expense === 0) {
            el.innerHTML = empty(
                'ยังไม่มีรายการเดือนนี้',
                '<button type="button" class="btn" data-act="openQuickAdd" data-args=\'["money"]\'>บันทึกรายรับหรือรายจ่ายแรก</button>'
            );
            return;
        }

        const share = income > 0 ? Math.round(expense / income * 100) : null;
        el.innerHTML =
            '<table class="ledger ledger-lg">'
            + `<tr><td>รายรับ</td><td class="num in">+${money(income)}</td></tr>`
            + `<tr><td>รายจ่าย</td><td class="num out">${MINUS}${money(expense)}</td></tr>`
            + `<tr class="total"><td>คงเหลือ</td><td class="num">${balance < 0 ? MINUS : ''}${money(balance)}</td></tr>`
            + '</table>'
            + (share === null ? '' : `<p class="budget-note">รายจ่ายคิดเป็น ${share}% ของรายรับเดือนนี้</p>`);
    }

    function renderSubscriptions(data) {
        const el = bodyOf('subscriptions');
        if (!el) return;

        const items = data?.upcoming || [];
        if (items.length === 0) {
            el.innerHTML = empty('ไม่มีรายการที่จะตัดเงินในสัปดาห์นี้');
            return;
        }

        let sum = 0;
        const rows = items.map(sub => {
            const when = new Date(sub.next_due_date.replace(/-/g, '/'));
            const days = daysUntil(sub.next_due_date);
            const late = days < 0;
            sum += Number(sub.amount) || 0;
            return `<tr><td>${esc(sub.name)}<span class="meta">${WEEKDAY[when.getDay()]} ${formatDate(sub.next_due_date).replace(/ \d{4}$/, '')} · `
                + `${late ? `<span class="text-danger">เกิน ${Math.abs(days)} วัน</span>` : dayWord(sub.next_due_date)}</span></td>`
                + `<td class="num">${money(Number(sub.amount) || 0)}</td></tr>`;
        });
        el.innerHTML = '<table class="ledger">' + rows.join('')
            + `<tr class="total"><td>รวม</td><td class="num">${money(sum)}</td></tr></table>`;
    }

    function renderWorkout(workout, focus) {
        const el = bodyOf('workout');
        if (!el) return;

        const session = workout?.last_session;
        const minutes = focus?.today_minutes || 0;
        const h = Math.floor(minutes / 60);
        const m = String(minutes % 60).padStart(2, '0');

        el.innerHTML = '<div class="facts">'
            + '<div class="fact"><div class="k">โฟกัสวันนี้</div>'
            +   `<div class="v">${h}:${m}<small>ชม.</small></div>`
            +   `<div class="note">${focus?.today_sessions ? focus.today_sessions + ' รอบ' : 'ยังไม่ได้จับเวลา'}</div></div>`
            + '<div class="fact"><div class="k">ออกกำลังกายล่าสุด</div>'
            +   (session
                    ? `<div class="v">${session.duration_min ? session.duration_min + '<small>นาที</small>' : '—'}</div>`
                      + `<div class="note">${dayWord(session.workout_date)} · ${esc(session.type)}</div>`
                    : '<div class="v">—</div><div class="note">ยังไม่มีบันทึก</div>')
            + '</div></div>';
    }

    function renderProjects(data) {
        const el = bodyOf('projects');
        if (!el) return;

        const items = data?.items || [];
        if (items.length === 0) {
            el.innerHTML = empty('ไม่มีโปรเจคที่กำลังทำ', link('/projects', 'สร้างโปรเจค'));
            return;
        }

        el.innerHTML = list(items.map(p => {
            const total = parseInt(p.total_tasks, 10) || 0;
            const done = parseInt(p.completed_tasks, 10) || 0;
            const pct = total > 0 ? Math.round(done / total * 100) : 0;
            return row(
                `<span class="grow"><a class="title" href="${BASE_URL}/projects">${esc(p.name)}</a>`
                + `<span class="meta">งานเสร็จ ${done}/${total}${p.due_date ? ' · กำหนด ' + formatDate(p.due_date) : ''}</span></span>`
                + `<span class="side">${esc(PROJECT_STATUS[p.status] || p.status)} · ${pct}%</span>`
            );
        }));
    }

    function renderNotes(data) {
        const el = bodyOf('notes');
        if (!el) return;

        const items = data?.items || [];
        if (items.length === 0) {
            el.innerHTML = empty('ยังไม่มีโน้ต', link('/notes', 'เขียนโน้ตแรก'));
            return;
        }

        el.innerHTML = list(items.map(n => row(
            `<span class="grow"><a class="title" href="${BASE_URL}/notes">${n.pinned ? '<span class="tag">ปักหมุด</span> ' : ''}${esc(n.title)}</a>`
            + `<span class="meta">${n.is_encrypted ? 'เนื้อหาเข้ารหัส' : esc(n.preview || 'ยังไม่มีเนื้อหา')}</span></span>`
            + `<span class="side">${relativeTime(n.updated_at)}</span>`
        )));
    }

    function renderStocks(data) {
        const el = bodyOf('stocks');
        if (!el) return;

        const items = data?.items || [];
        if (items.length === 0) {
            el.innerHTML = empty('ยังไม่มีหุ้นในพอร์ตหรือรายการที่ติดตาม', link('/stocks', 'เปิดหุ้น'));
            return;
        }

        el.innerHTML = list(items.map(s => {
            const change = parseFloat(s.day_change_pct);
            const has = !Number.isNaN(change);
            const price = s.last_price !== null && s.last_price !== undefined
                ? (s.currency === 'THB' ? '฿' : '$') + Number(s.last_price).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : '—';
            // An arrow as well as a colour, so the direction reads without telling red from green.
            const move = !has || change === 0 ? '0.00%'
                : change > 0 ? `<span class="chg-up">▲ ${change.toFixed(2)}%</span>`
                : `<span class="chg-down">▼ ${Math.abs(change).toFixed(2)}%</span>`;
            return row(
                `<span class="grow"><a class="title code" href="${BASE_URL}/stocks">${esc(s.ticker)}</a><span class="meta">${esc(s.market)}</span></span>`
                + `<span class="side">${price} · ${move}</span>`
            );
        }));
    }

    function renderTransfer(data) {
        const el = bodyOf('transfer');
        if (!el) return;

        const items = data?.items || [];
        if (items.length === 0) {
            el.innerHTML = empty('ยังไม่เคยส่งไฟล์', link('/transfer', 'ส่งไฟล์'));
            return;
        }

        el.innerHTML = list(items.map(t => {
            let count = 0;
            try { count = JSON.parse(t.files_json || '[]').length; } catch { /* a damaged row still lists */ }
            const url = BASE_URL + '/transfer?code=' + encodeURIComponent(t.code);
            return row(
                `<span class="grow"><a class="title code" href="${BASE_URL}/transfer">${esc(t.code)}</a>`
                + `<span class="meta">${count} ไฟล์ · ${fileSize(t.total_size)} · ดาวน์โหลด ${Number(t.download_count)} ครั้ง</span></span>`
                + `<span class="side">${t.is_expired ? 'หมดอายุ' : 'ใช้งานได้'}</span>`
                + `<button type="button" class="btn btn-sm" data-act="copyTransferLink" data-args='${esc(JSON.stringify([url]))}'>คัดลอกลิงก์</button>`
            );
        }));
    }

    /** The line under the weekday: only what there is, in numbers. */
    function renderTally(data) {
        const parts = [];

        if (data.tasks) {
            const need = (data.tasks.overdue || 0) + (data.tasks.due_today || 0);
            const projectCount = data.tasks.my_project_items?.length || 0;
            if (need > 0) parts.push(`ต้องทำ <b>${need}</b>`);
            if (data.tasks.overdue > 0) parts.push(`<span class="late">เกินกำหนด <b>${data.tasks.overdue}</b></span>`);
            if (projectCount > 0) parts.push(`งานโปรเจกต์ <b>${projectCount}</b>`);
        }
        const events = data.calendar?.today_events?.length || 0;
        if (events > 0) parts.push(`นัด <b>${events}</b>`);
        const dues = data.subscriptions?.upcoming?.length || 0;
        if (dues > 0) parts.push(`ตัดเงินใน 7 วัน <b>${dues}</b>`);
        if (data.finance && (data.finance.income || data.finance.expense)) {
            parts.push(`เหลือ <b>${data.finance.balance < 0 ? MINUS : ''}${money(data.finance.balance)}</b> บาท`);
        }

        tally.innerHTML = parts.length ? parts.join(' · ') : 'วันนี้ยังว่าง';
    }

    /* ---------- loading ---------- */

    function wantedModules() {
        const wanted = new Set();
        root.querySelectorAll('[data-section]').forEach(el => wanted.add(el.dataset.section));
        if (wanted.has('workout')) wanted.add('focus');
        return [...wanted];
    }

    function showError() {
        root.querySelectorAll('[data-body]').forEach(el => { el.innerHTML = ''; });
        tally.textContent = '';
        if (document.getElementById('todayError')) return;

        const banner = document.createElement('div');
        banner.id = 'todayError';
        banner.className = 'alert alert-danger';
        banner.setAttribute('role', 'alert');
        banner.innerHTML = 'โหลดข้อมูลวันนี้ไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="reloadToday">ลองอีกครั้ง</button>';
        root.querySelector('.page-head').insertAdjacentElement('afterend', banner);
    }

    let lastRenderedJson = '';
    // ผู้ใช้กด "แสดงอีก" แล้วหรือยัง เก็บไว้ในตัวแปรเพราะหลังวาดใหม่ปุ่มนั้นไม่อยู่ใน DOM ให้อ่านแล้ว
    let overdueExpanded = false;

    /** ตรวจว่ามี modal หรือเมนูค้างเปิดอยู่ ไม่ควรรีเรนเดอร์ทับสิ่งที่ผู้ใช้กำลังทำ */
    function uiIsOpen() {
        return document.getElementById('overdueDateModal')?.classList.contains('active')
            || !!root.querySelector('.overdue-menu:not([hidden])');
    }

    /** โหลดข้อมูลหน้าวันนี้จากเซิร์ฟเวอร์ รีเรนเดอร์เฉพาะเมื่อข้อมูลเปลี่ยน */
    async function load() {
        const modules = wantedModules();
        if (modules.length === 0) {
            tally.textContent = '';
            return;
        }

        try {
            const data = await apiFetch(BASE_URL + '/api/dashboard/summary?modules=' + encodeURIComponent(modules.join(',')));
            document.getElementById('todayError')?.remove();
            if (data.meta?.server_time) clockGap = Date.now() - parseLocal(data.meta.server_time);

            // ถ้าวันของเซิร์ฟเวอร์เปลี่ยน ให้รีโหลดหน้าเพื่อ render วันใหม่ทั้งหมด
            if (data.meta?.today && data.meta.today !== serverToday()) {
                window.location.reload();
                return;
            }

            // เทียบเฉพาะข้อมูล meta มีเวลาของเซิร์ฟเวอร์ที่เปลี่ยนทุกวินาที ถ้ารวมไว้จะวาดใหม่ทุกครั้ง
            const json = JSON.stringify({ ...data, meta: undefined });
            if (json === lastRenderedJson) return;
            if (uiIsOpen()) {
                // รอจนกว่าผู้ใช้จะปิด dialog/menu แล้วค่อยรีเรนเดอร์
                lastRenderedJson = '';
                return;
            }

            renderTasks(data.tasks, overdueExpanded);
            renderCalendar(data.calendar);
            renderHabits(data.habits);
            renderFinance(data.finance);
            renderSubscriptions(data.subscriptions);
            renderWorkout(data.workout, data.focus);
            renderProjects(data.projects);
            renderNotes(data.notes);
            renderStocks(data.stocks);
            renderTransfer(data.transfer);
            renderTally(data);

            lastRenderedJson = json;
            if (data.meta?.warnings?.length) toast('บางส่วนยังโหลดไม่ได้: ' + data.meta.warnings.join(', '), 'warning');
        } catch (error) {
            console.error('Today failed to load:', error);
            showError();
        }
    }

    /** Refresh soon, once: several ticks in a row should cost one request. */
    function reloadSoon(delay) {
        clearTimeout(reloadTimer);
        reloadTimer = setTimeout(load, delay);
    }

    /* ---------- acting on a row ---------- */

    async function tickTask(box) {
        const item = box.closest('[data-task]');
        const done = box.checked;
        box.disabled = true;
        try {
            await apiFetch(`${BASE_URL}/api/tasks/${item.dataset.task}`, {
                method: 'PUT',
                body: JSON.stringify({ status: done ? 'done' : 'open' }),
            });
            item.classList.toggle('done', done);
            toast(done ? 'ทำงานเสร็จแล้ว' : 'เปิดงานกลับมาแล้ว');
            reloadSoon(2500);
        } catch (error) {
            box.checked = !done;
            toast(error.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
        } finally {
            box.disabled = false;
        }
    }

    /** ติ๊กเสร็จ/เปิดกลับงานโปรเจกต์ที่มอบให้ฉัน คืนค่า status เดิมเมื่อยกเลิกติ๊ก */
    async function tickProjectTask(box) {
        const item = box.closest('[data-project-task]');
        const done = box.checked;
        const previousStatus = item.dataset.projectTaskStatus || 'To Do';
        box.disabled = true;
        try {
            await apiFetch(`${BASE_URL}/api/projects/tasks/${item.dataset.projectTask}`, {
                method: 'PUT',
                body: JSON.stringify({ status: done ? 'Done' : previousStatus }),
            });
            toast(done ? 'ทำงานโปรเจกต์เสร็จแล้ว' : 'เปิดงานโปรเจกต์กลับมาแล้ว');
            reloadSoon(2500);
        } catch (error) {
            box.checked = !done;
            toast(error.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
        } finally {
            box.disabled = false;
        }
    }

    async function tickHabit(box) {
        const item = box.closest('[data-habit]');
        const done = box.checked;
        box.disabled = true;
        try {
            await apiFetch(`${BASE_URL}/api/habits/${item.dataset.habit}/toggle`, { method: 'POST', body: '{}' });
            toast(done ? 'ติ๊กนิสัยแล้ว' : 'ยกเลิกการติ๊กแล้ว');
            reloadSoon(300); // the streak changes, and only the server knows by how much
        } catch (error) {
            box.checked = !done;
            toast(error.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
        } finally {
            box.disabled = false;
        }
    }

    /** ข้อความ toast สำหรับ action ทั้งกลุ่ม */
    function actionLabel(action, count, date) {
        if (action === 'today') return `ย้าย ${count} งานไปวันนี้แล้ว`;
        if (action === 'tomorrow') return `ย้าย ${count} งานไปพรุ่งนี้แล้ว`;
        if (action === 'date') return `ย้าย ${count} งานเป็นวันที่ ${formatDate(date)} แล้ว`;
        if (action === 'clear') return `เอาวันที่ออก ${count} งานแล้ว`;
        if (action === 'delete') return `ลบ ${count} งานแล้ว`;
        return 'บันทึกแล้ว';
    }

    /** ย้ายงานค้างทั้งกลุ่มตาม action ที่เลือก */
    async function moveOverdue(action, date) {
        try {
            const payload = { action };
            if (action === 'date') payload.date = date;
            const result = await apiFetch(BASE_URL + '/api/tasks/overdue', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            toast(actionLabel(action, result.count || 0, date));
            await load();
        } catch (error) {
            toast(error.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
        }
    }

    /** ย้าย/ลบงานค้างทีละรายการ ส่งคำสั่ง today/tomorrow ให้เซิร์ฟเวอร์แปลงวัน */
    async function moveTask(taskId, action, date) {
        try {
            if (action === 'delete') {
                if (!await confirmAction('ลบงานนี้ถาวร ย้อนกลับไม่ได้', 'ลบ', 'ลบงานนี้?')) return;
                await apiFetch(`${BASE_URL}/api/tasks/${taskId}`, { method: 'DELETE' });
                toast('ลบงานแล้ว');
            } else {
                let dueDate = '';
                if (action === 'today') dueDate = 'today';
                else if (action === 'tomorrow') dueDate = 'tomorrow';
                else if (action === 'date') dueDate = date;
                await apiFetch(`${BASE_URL}/api/tasks/${taskId}`, {
                    method: 'PUT',
                    body: JSON.stringify({ due_date: dueDate }),
                });
                toast(action === 'clear' ? 'เอาวันที่ออกแล้ว' : 'ย้ายงานแล้ว');
            }
            await load();
        } catch (error) {
            toast(error.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง', 'danger');
        }
    }

    /* ---------- date picker ---------- */

    let pendingDateCallback = null;

    /** เปิด date picker สำหรับเลือกวันที่ย้ายงานค้าง */
    function openDatePicker(callback) {
        pendingDateCallback = callback;
        const input = document.getElementById('overdueDateInput');
        if (input) input.value = serverToday();
        openModal('overdueDateModal');
    }

    /** ปิด date picker และลบ callback ที่รออยู่ */
    function closeDateModal() {
        pendingDateCallback = null;
        closeModal('overdueDateModal');
    }

    /** ยืนยันวันที่จาก date picker แล้วส่งต่อให้ callback */
    function confirmDate() {
        const input = document.getElementById('overdueDateInput');
        const date = input?.value || '';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            toast('เลือกวันที่ก่อน', 'danger');
            return;
        }
        if (pendingDateCallback) {
            pendingDateCallback(date);
        }
        closeDateModal();
    }

    /* ---------- menus and clicks ---------- */

    /** ปิดเมนูปุ่ม ⋯ ของงานค้างทั้งหมด */
    function closeOverdueMenus() {
        root.querySelectorAll('.overdue-menu').forEach(m => { m.hidden = true; });
    }

    /** เปิดเมนูปุ่ม ⋯ ของงานค้างรายการเดียว */
    function openOverdueMenu(taskId) {
        closeOverdueMenus();
        const menu = root.querySelector(`.overdue-menu[data-menu-for="${Number(taskId)}"]`);
        if (menu) {
            menu.hidden = false;
            // ให้ focus อยู่ที่เมนูแรกเพื่อใช้งานด้วยคีย์บอร์ด
            const first = menu.querySelector('button');
            if (first) first.focus();
        }
    }

    root.addEventListener('click', event => {
        const target = event.target;

        if (target.matches('[data-act="overdueBulk"]')) {
            const action = target.dataset.action;
            if (action === 'date') {
                openDatePicker(date => moveOverdue('date', date));
            } else if (action === 'delete') {
                const total = Number(sub('overdue')?.querySelector('[data-bulk-label]')?.dataset.total || 0);
                confirmAction(
                    `งานค้างทั้งหมด ${total} งาน จะถูกลบถาวร รวมงานที่ไม่ได้แสดงบนจอ ย้อนกลับไม่ได้`,
                    `ลบ ${total} งาน`,
                    `ลบงานค้างทั้ง ${total} งาน?`
                ).then(ok => { if (ok) moveOverdue('delete'); });
            } else {
                moveOverdue(action);
            }
            return;
        }

        if (target.matches('[data-act="openOverdueMenu"]')) {
            event.stopPropagation();
            openOverdueMenu(target.dataset.taskId);
            return;
        }

        if (target.matches('[data-act="overdueAction"]')) {
            event.stopPropagation();
            const action = target.dataset.action;
            const taskId = target.dataset.taskId;
            closeOverdueMenus();
            if (action === 'date') {
                openDatePicker(date => moveTask(taskId, 'date', date));
            } else {
                moveTask(taskId, action);
            }
            return;
        }

        if (target.matches('[data-act="expandOverdue"]')) {
            root.querySelectorAll('.overdue-extra').forEach(el => el.classList.remove('overdue-extra'));
            target.hidden = true;
            overdueExpanded = true;
            return;
        }

        if (target.matches('[data-close-modal]')) {
            closeDateModal();
            return;
        }

        if (target.matches('#overdueDateConfirm')) {
            confirmDate();
        }
    });

    document.addEventListener('click', event => {
        if (!event.target.closest('.overdue-menu') && !event.target.closest('.overdue-menu-btn')) {
            closeOverdueMenus();
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeOverdueMenus();
        }
    });

    root.addEventListener('change', event => {
        if (event.target.matches('.task-tick')) tickTask(event.target);
        if (event.target.matches('.project-tick')) tickProjectTask(event.target);
        if (event.target.matches('.habit-tick')) tickHabit(event.target);
    });

    // ช่อง "+ เพิ่มงานวันนี้" ส่ง due_date = 'today' ให้เซิร์ฟเวอร์ตัดสินวันที่จริง
    const addInput = document.getElementById('todayAddTask');
    if (addInput) {
        addInput.addEventListener('keydown', async event => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            const title = addInput.value.trim();
            if (title === '') return;

            addInput.disabled = true;
            try {
                await apiFetch(BASE_URL + '/api/tasks', {
                    method: 'POST',
                    body: JSON.stringify({ title, quadrant: 1, due_date: 'today' }),
                });
                addInput.value = '';
                toast('เพิ่มงานแล้ว');
                await load();
            } catch (error) {
                toast(error.message || 'เพิ่มงานไม่สำเร็จ ลองอีกครั้ง', 'danger');
            } finally {
                addInput.disabled = false;
                addInput.focus();
            }
        });
    }

    // A task, note or expense added from the quick-add sheet: refresh in place.
    document.addEventListener('dayflow:added', event => {
        event.preventDefault();
        load();
    });

    // Come back to the tab after a while, or past midnight: ask the server for
    // the current day and reload if it differs from the day the page was built.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        load();
    });
    setInterval(() => { if (!document.hidden) load(); }, 60000);

    window.reloadToday = () => load();
    load();
})();

/* Copying the transfer link used to be written out inline in the widget. */
function copyTransferLink(url) {
    navigator.clipboard.writeText(url)
        .then(() => toast('คัดลอกลิงก์รับไฟล์แล้ว', 'success'))
        .catch(() => toast('คัดลอกไม่สำเร็จ', 'danger'));
}
