/* =====================================================
   review.js — weekly / monthly cross-module summary
===================================================== */

let reviewPeriod = 'week';

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.review-period').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.dataset.period === reviewPeriod) return;
            reviewPeriod = btn.dataset.period;

            document.querySelectorAll('.review-period').forEach(function (other) {
                const isActive = other === btn;
                other.classList.toggle('active', isActive);
                other.classList.toggle('btn-primary', isActive);
                other.classList.toggle('btn-ghost', !isActive);
            });

            loadReview();
        });
    });

    loadReview();
});

async function loadReview() {
    try {
        const data = await apiFetch(BASE_URL + '/api/review?period=' + encodeURIComponent(reviewPeriod));
        renderReview(data);
        if (data.meta && data.meta.warnings && data.meta.warnings.length) {
            toast('บางส่วนยังโหลดไม่ได้: ' + data.meta.warnings.join(', '), 'warning');
        }
    } catch (err) {
        console.error('Review load error:', err);
        toast('โหลดสรุปผลไม่สำเร็จ', 'danger');
    }
}

function renderReview(data) {
    renderRange(data.period);
    renderStrip(data);
    renderTasks(data.tasks);
    renderFocus(data.focus, data.period);
    renderHabits(data.habits);
    renderMixed(data.exercise, data.finance, data.notes);
}

function renderRange(period) {
    const el = document.getElementById('reviewRange');
    if (!el || !period) return;
    // The stored end is exclusive; show the last day that is actually included.
    const lastDay = new Date(period.end);
    lastDay.setDate(lastDay.getDate() - 1);
    el.textContent = formatDate(period.start) + ' – ' + formatDate(lastDay.toISOString().slice(0, 10));
}

function renderStrip(data) {
    const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };

    set('rvTasksDone', (data.tasks?.completed ?? 0) + ' งาน');
    set('rvFocusHours', formatMinutes(data.focus?.minutes ?? 0));

    const done = data.habits?.done_days ?? 0;
    const target = data.habits?.target_days ?? 0;
    set('rvHabits', target > 0 ? done + ' / ' + target + ' วัน' : '—');

    const balance = data.finance?.balance ?? 0;
    const el = document.getElementById('rvBalance');
    if (el) {
        el.textContent = formatMoney(balance) + ' บาท';
        el.className = 'review-stat-val' + (balance < 0 ? ' danger' : balance > 0 ? ' success' : '');
    }
}

function renderTasks(tasks) {
    const el = document.getElementById('rvTasksBody');
    if (!el || !tasks) return;

    const created = tasks.created ?? 0;
    const completed = tasks.completed ?? 0;
    // Completed can exceed created when older tasks are finished in this period,
    // so the bar is capped rather than allowed to overflow.
    const ratio = created > 0 ? Math.min(100, Math.round((completed / created) * 100)) : 0;

    el.innerHTML =
        '<div class="review-rows">'
        + reviewRow('สร้างใหม่', created + ' งาน')
        + reviewRow('ทำเสร็จ', completed + ' งาน', completed > 0 ? 'success' : '')
        + reviewRow('ยังค้างอยู่', (tasks.open ?? 0) + ' งาน')
        + reviewRow('เกินกำหนด', (tasks.overdue ?? 0) + ' งาน', (tasks.overdue ?? 0) > 0 ? 'danger' : '')
        + '</div>'
        + (created > 0
            ? '<div class="review-bar"><div class="review-bar-fill" style="width:' + ratio + '%"></div></div>'
              + '<p class="text-xs text-muted" style="margin-top:6px">ปิดงานที่สร้างในช่วงนี้ได้ ' + ratio + '%</p>'
            : '');
}

function renderFocus(focus, period) {
    const el = document.getElementById('rvFocusBody');
    if (!el || !focus) return;

    if (!focus.sessions) {
        el.innerHTML = '<p class="text-sm text-muted">ยังไม่มีรอบโฟกัสในช่วงนี้</p>';
        return;
    }

    const byDay = focus.by_day || [];
    const peak = byDay.reduce((max, d) => Math.max(max, Number(d.minutes) || 0), 0) || 1;

    el.innerHTML =
        '<div class="review-rows">'
        + reviewRow('จำนวนรอบ', focus.sessions + ' รอบ')
        + reviewRow('เวลารวม', formatMinutes(focus.minutes))
        + '</div>'
        + '<div class="review-spark">'
        + byDay.map(function (day) {
            const minutes = Number(day.minutes) || 0;
            const height = Math.max(4, Math.round((minutes / peak) * 60));
            return '<div class="review-spark-col" title="' + escHtml(day.day) + ' — ' + formatMinutes(minutes) + '">'
                 + '<div class="review-spark-bar" style="height:' + height + 'px"></div>'
                 + '<span>' + new Date(day.day).getDate() + '</span>'
                 + '</div>';
        }).join('')
        + '</div>';
}

function renderHabits(habits) {
    const el = document.getElementById('rvHabitsBody');
    if (!el || !habits) return;

    const items = habits.items || [];
    if (!items.length) {
        el.innerHTML = '<p class="text-sm text-muted">ยังไม่ได้ตั้งนิสัยประจำวัน</p>';
        return;
    }

    el.innerHTML = '<div class="review-rows">' + items.map(function (habit) {
        const done = Number(habit.done_days) || 0;
        const target = Number(habit.target_days) || 0;
        const hit = target > 0 && done >= target;
        return reviewRow(
            escHtml(habit.name),
            done + (target > 0 ? ' / ' + target : '') + ' วัน',
            hit ? 'success' : ''
        );
    }).join('') + '</div>';
}

function renderMixed(exercise, finance, notes) {
    const el = document.getElementById('rvMixedBody');
    if (!el) return;

    const categories = (finance?.top_categories || []).slice(0, 3);

    el.innerHTML =
        '<div class="review-rows">'
        + reviewRow('ออกกำลังกาย', (exercise?.sessions ?? 0) + ' ครั้ง · ' + formatMinutes(exercise?.minutes ?? 0))
        + reviewRow('โน้ตที่เขียน', (notes?.created ?? 0) + ' โน้ต')
        + reviewRow('รายรับ', formatMoney(finance?.income ?? 0) + ' บาท', 'success')
        + reviewRow('รายจ่าย', formatMoney(finance?.expense ?? 0) + ' บาท', 'danger')
        + '</div>'
        + (categories.length
            ? '<p class="text-xs text-muted" style="margin:10px 0 6px">หมวดที่ใช้จ่ายมากที่สุด</p>'
              + '<div class="review-rows">'
              + categories.map(c => reviewRow(escHtml(c.name), formatMoney(c.total) + ' บาท')).join('')
              + '</div>'
            : '');
}

/* --- helpers --- */
function reviewRow(label, value, cls) {
    return '<div class="review-row">'
         + '<span class="review-row-label">' + label + '</span>'
         + '<span class="review-row-value' + (cls ? ' ' + cls : '') + '">' + value + '</span>'
         + '</div>';
}

function formatMinutes(minutes) {
    const total = Number(minutes) || 0;
    if (total < 60) return total + ' นาที';
    const hours = Math.floor(total / 60);
    const rest = total % 60;
    return rest ? hours + ' ชม. ' + rest + ' นาที' : hours + ' ชม.';
}
