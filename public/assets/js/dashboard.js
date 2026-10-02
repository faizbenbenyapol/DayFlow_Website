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

    let loadedAt = 0;
    let reloadTimer = null;
    const shownDate = localToday();

    /* ---------- small helpers ---------- */

    /** Today as YYYY-MM-DD in the visitor's own time zone. */
    function localToday() {
        const now = new Date();
        return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    }

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
        const days = daysUntil(dateStr);
        if (days === 0) return 'วันนี้';
        if (days === -1) return 'เมื่อวาน';
        if (days < 0) return Math.abs(days) + ' วันที่แล้ว';
        return 'อีก ' + days + ' วัน';
    }

    function relativeTime(stamp) {
        if (!stamp) return '';
        const seconds = Math.floor((Date.now() - parseLocal(stamp)) / 1000);
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

    function taskRow(task, late) {
        const days = daysUntil(task.due_date);
        const side = late ? `<span class="side late">เกิน ${Math.abs(days)} วัน</span>` : '';
        return row(
            `<input type="checkbox" class="task-tick" aria-label="เสร็จแล้ว: ${esc(task.title)}">`
            + `<span class="grow"><span class="title">${esc(task.title)}</span><span class="meta">${QUADRANT[task.quadrant] || ''}</span></span>`
            + side,
            ` data-task="${Number(task.id)}"`
        );
    }

    function renderTasks(data) {
        const el = bodyOf('tasks');
        if (!el) return;

        const overdue = data?.overdue_items || [];
        const today = data?.today_items || [];
        setCount('tasks', overdue.length + today.length > 0 ? String((data.overdue || 0) + (data.due_today || 0)) : '');

        if (overdue.length + today.length === 0) {
            el.innerHTML = empty('ไม่มีงานค้างและไม่มีงานครบกำหนดวันนี้ พิมพ์งานแรกในช่องด้านล่างแล้วกด Enter');
            return;
        }

        let html = '';
        if (overdue.length) {
            html += `<div class="subhead late">เกินกำหนด · ${data.overdue}</div>` + list(overdue.map(t => taskRow(t, true)));
            if (data.overdue > overdue.length) {
                html += `<p class="subhead">และอีก ${data.overdue - overdue.length} รายการ · ${link('/tasks', 'ดูทั้งหมด')}</p>`;
            }
        }
        if (today.length) {
            html += `<div class="subhead">วันนี้ · ${data.due_today}</div>` + list(today.map(t => taskRow(t, false)));
        }
        el.innerHTML = html;
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

        el.innerHTML = list(items.map(h => row(
            `<input type="checkbox" class="habit-tick"${h.done_today ? ' checked' : ''} aria-label="ทำแล้ววันนี้: ${esc(h.name)}">`
            + `<span class="grow"><span class="title">${esc(h.name)}</span></span>`
            + `<span class="side">${h.streak > 0 ? 'ติดกัน ' + h.streak + ' วัน' : '—'}</span>`,
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
            if (need > 0) parts.push(`ต้องทำ <b>${need}</b>`);
            if (data.tasks.overdue > 0) parts.push(`<span class="late">เกินกำหนด <b>${data.tasks.overdue}</b></span>`);
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

    async function load() {
        const modules = wantedModules();
        if (modules.length === 0) {
            tally.textContent = '';
            return;
        }

        try {
            const data = await apiFetch(BASE_URL + '/api/dashboard/summary?modules=' + encodeURIComponent(modules.join(',')));
            document.getElementById('todayError')?.remove();

            renderTasks(data.tasks);
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

            loadedAt = Date.now();
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
            const left = root.querySelectorAll('.task-tick:not(:checked)').length;
            setCount('tasks', left ? String(left) : '');
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

    root.addEventListener('change', event => {
        if (event.target.matches('.task-tick')) tickTask(event.target);
        if (event.target.matches('.habit-tick')) tickHabit(event.target);
    });

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
                    body: JSON.stringify({ title, quadrant: 1, due_date: localToday() }),
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

    // Come back to the tab after a while, or past midnight: show the new truth.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        if (localToday() !== shownDate) window.location.reload();
        else if (Date.now() - loadedAt > 5 * 60 * 1000) load();
    });
    setInterval(() => { if (!document.hidden && localToday() !== shownDate) window.location.reload(); }, 60000);

    window.reloadToday = () => load();
    load();
})();

/* Copying the transfer link used to be written out inline in the widget. */
function copyTransferLink(url) {
    navigator.clipboard.writeText(url)
        .then(() => toast('คัดลอกลิงก์รับไฟล์แล้ว', 'success'))
        .catch(() => toast('คัดลอกไม่สำเร็จ', 'danger'));
}
