/* =====================================================
   review.js — the week or the month, looked back on

   One request for the period; each part of the page is drawn from its slice.
   A part the server could not work out says so instead of showing zero.
===================================================== */

let reviewPeriod = 'week';
const RV_MINUS = '−';

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#reviewPeriods button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.dataset.period === reviewPeriod) return;
            reviewPeriod = btn.dataset.period;
            document.querySelectorAll('#reviewPeriods button').forEach(other => {
                other.setAttribute('aria-pressed', String(other === btn));
            });
            loadReview();
        });
    });

    loadReview();
});

function showReviewError(message) {
    const box = document.getElementById('reviewError');
    box.hidden = message === '';
    box.className = message === '' ? '' : 'alert alert-danger';
    box.innerHTML = message === '' ? '' : escHtml(message) + ' <button type="button" class="btn btn-sm" data-act="loadReview">ลองอีกครั้ง</button>';
}

async function loadReview() {
    showReviewError('');
    try {
        const data = await apiFetch(BASE_URL + '/api/review?period=' + encodeURIComponent(reviewPeriod));
        renderReview(data);
        const warnings = data.meta && data.meta.warnings ? data.meta.warnings : [];
        if (warnings.length) showReviewError('บางส่วนยังโหลดไม่ได้: ' + warnings.join(', '));
    } catch (err) {
        console.error('Review load error:', err);
        document.getElementById('reviewRange').textContent = 'โหลดสรุปไม่ได้';
        showReviewError('โหลดสรุปผลไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่');
    }
}

function renderReview(data) {
    renderRange(data.period);
    renderFacts(data);
    renderTasks(data.tasks);
    renderFocus(data.focus, data.period);
    renderHabits(data.habits);
    renderMoney(data.finance);
    renderBody(data.exercise, data.notes);
    document.querySelectorAll('[aria-busy="true"]').forEach(el => el.removeAttribute('aria-busy'));
}

function renderRange(period) {
    const el = document.getElementById('reviewRange');
    if (!el || !period) return;
    // The stored end is exclusive; show the last day that is actually included.
    const lastDay = new Date(period.end);
    lastDay.setDate(lastDay.getDate() - 1);
    el.textContent = formatDate(period.start) + ' – ' + formatDate(lastDay.toISOString().slice(0, 10));
}

function setHtml(id, html) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html;
}

function renderFacts(data) {
    setHtml('rvTasksDone', (data.tasks?.completed ?? 0) + '<small>งาน</small>');
    setHtml('rvFocusHours', formatMinutesHtml(data.focus?.minutes ?? 0));

    const done = data.habits?.done_days ?? 0;
    const target = data.habits?.target_days ?? 0;
    setHtml('rvHabits', target > 0 ? done + ' / ' + target + '<small>วัน</small>' : '—');

    const balance = Number(data.finance?.balance ?? 0);
    const el = document.getElementById('rvBalance');
    el.innerHTML = rvMoney(balance) + '<small>บาท</small>';
    el.classList.toggle('pos', balance > 0);
    el.classList.toggle('neg', balance < 0);
}

/** A ledger line: a label and a figure, ruled. `cls` is "in", "out" or nothing. */
function reviewRow(label, value, cls) {
    return '<tr><td>' + label + '</td><td class="num' + (cls ? ' ' + cls : '') + '">' + value + '</td></tr>';
}

function ledger(rows, label) {
    return '<table class="ledger" aria-label="' + label + '">' + rows.join('') + '</table>';
}

function renderTasks(tasks) {
    if (!tasks) return;
    const created = tasks.created ?? 0;
    const completed = tasks.completed ?? 0;
    const overdue = tasks.overdue ?? 0;
    // Finished tasks can outnumber new ones when older tasks are closed in this period.
    const ratio = created > 0 ? Math.min(100, Math.round((completed / created) * 100)) : 0;

    setHtml('rvTasksBody',
        ledger([
            reviewRow('สร้างใหม่', created + ' งาน'),
            reviewRow('ทำเสร็จ', completed + ' งาน', completed > 0 ? 'in' : ''),
            reviewRow('ยังค้างอยู่', (tasks.open ?? 0) + ' งาน'),
            reviewRow('เกินกำหนด', overdue + ' งาน', overdue > 0 ? 'out' : ''),
        ], 'สรุปงาน')
        + (created > 0
            ? '<div class="progress rv-progress" role="img" aria-label="ปิดงานที่สร้างในช่วงนี้ได้ ' + ratio + ' เปอร์เซ็นต์"><div class="progress-bar" style="--v:' + ratio + '%"></div></div>'
              + '<p class="rv-note">ปิดงานที่สร้างในช่วงนี้ได้ ' + ratio + '%</p>'
            : ''));
}

/** Every day of the period, with 0 for the days nothing was timed. */
function daysOf(period, byDay) {
    const minutes = {};
    byDay.forEach(d => { minutes[d.day] = Number(d.minutes) || 0; });
    if (!period) return byDay.map(d => ({ day: d.day, minutes: minutes[d.day] }));

    const days = [];
    const end = new Date(period.end + 'T12:00:00');
    for (let d = new Date(period.start + 'T12:00:00'); d < end; d.setDate(d.getDate() + 1)) {
        const key = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        days.push({ day: key, minutes: minutes[key] || 0 });
    }
    return days;
}

function renderFocus(focus, period) {
    if (!focus) return;
    if (!focus.sessions) {
        setHtml('rvFocusBody', '<p class="rv-empty">ยังไม่มีรอบโฟกัสในช่วงนี้ เริ่มจับเวลาที่หน้า "โฟกัส"</p>');
        return;
    }

    const byDay = daysOf(period, focus.by_day || []);
    const peak = byDay.reduce((max, d) => Math.max(max, Number(d.minutes) || 0), 0) || 1;

    setHtml('rvFocusBody',
        ledger([
            reviewRow('จำนวนรอบ', focus.sessions + ' รอบ'),
            reviewRow('เวลารวม', formatMinutes(focus.minutes)),
        ], 'สรุปเวลาโฟกัส')
        + '<ol class="rv-days" aria-label="เวลาโฟกัสแต่ละวัน">'
        + byDay.map(day => {
            const minutes = Number(day.minutes) || 0;
            const height = Math.max(minutes > 0 ? 4 : 1, Math.round((minutes / peak) * 100));
            return '<li><span class="rv-day-bar" style="--v:' + height + '%" title="' + escHtml(formatDate(day.day)) + ' · ' + formatMinutes(minutes) + '"></span>'
                 + '<span class="rv-day-label" aria-hidden="true">' + new Date(day.day).getDate() + '</span>'
                 + '<span class="sr-only">' + escHtml(formatDate(day.day)) + ' ' + formatMinutes(minutes) + '</span></li>';
        }).join('')
        + '</ol>');
}

function renderHabits(habits) {
    if (!habits) return;
    const items = habits.items || [];
    if (!items.length) {
        setHtml('rvHabitsBody', '<p class="rv-empty">ยังไม่ได้ตั้งนิสัยประจำวัน เพิ่มได้ที่หน้า "นิสัย"</p>');
        return;
    }

    setHtml('rvHabitsBody', ledger(items.map(habit => {
        const done = Number(habit.done_days) || 0;
        const target = Number(habit.target_days) || 0;
        const hit = target > 0 && done >= target;
        return reviewRow(escHtml(habit.name), done + (target > 0 ? ' / ' + target : '') + ' วัน', hit ? 'in' : '');
    }), 'นิสัยแต่ละอย่าง'));
}

function rvMoney(value) {
    const n = Number(value) || 0;
    return (n < 0 ? RV_MINUS : '') + formatMoney(Math.abs(n));
}

function renderMoney(finance) {
    const income = Number(finance?.income ?? 0);
    const expense = Number(finance?.expense ?? 0);
    const balance = Number(finance?.balance ?? income - expense);
    const categories = (finance?.top_categories || []).slice(0, 3);

    if (!income && !expense) {
        setHtml('rvMoneyBody', '<p class="rv-empty">ยังไม่มีรายรับรายจ่ายในช่วงนี้</p>');
        return;
    }

    setHtml('rvMoneyBody',
        '<table class="ledger" aria-label="รายรับรายจ่ายในช่วงนี้">'
        + reviewRow('รายรับ', '+' + formatMoney(income), 'in')
        + reviewRow('รายจ่าย', RV_MINUS + formatMoney(expense), 'out')
        + '<tr class="total"><td>คงเหลือ</td><td class="num">' + rvMoney(balance) + '</td></tr></table>'
        + (categories.length
            ? '<h3 class="subhead">หมวดที่ใช้จ่ายมากที่สุด</h3>'
              + ledger(categories.map(c => reviewRow(escHtml(c.name), formatMoney(c.total) + ' บาท')), 'หมวดที่ใช้จ่ายมากที่สุด')
            : ''));
}

function renderBody(exercise, notes) {
    setHtml('rvBodyBody', ledger([
        reviewRow('ออกกำลังกาย', (exercise?.sessions ?? 0) + ' ครั้ง · ' + formatMinutes(exercise?.minutes ?? 0)),
        reviewRow('โน้ตที่เขียน', (notes?.created ?? 0) + ' โน้ต'),
    ], 'ออกกำลังกายและโน้ต'));
}

/* --- helpers --- */
function formatMinutes(minutes) {
    const total = Number(minutes) || 0;
    if (total < 60) return total + ' นาที';
    const hours = Math.floor(total / 60);
    const rest = total % 60;
    return rest ? hours + ' ชม. ' + rest + ' นาที' : hours + ' ชม.';
}

/** The same, for a headline figure: the number large and its unit small. */
function formatMinutesHtml(minutes) {
    const total = Number(minutes) || 0;
    if (total < 60) return total + '<small>นาที</small>';
    const hours = Math.floor(total / 60);
    const rest = total % 60;
    return hours + '<small>ชม.</small>' + (rest ? ' ' + rest + '<small>นาที</small>' : '');
}
