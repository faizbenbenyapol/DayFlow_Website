/* =====================================================
   projects-roadmap.js — แท็บ "แผนงาน" ของโปรเจคซอฟต์แวร์ (REQ-S7)

   งานใหญ่หนึ่งแถวต่อเดือน: แถบเริ่มที่ start_date และจบที่ due_date
   ลากกลางแถบเลื่อนทั้งช่วง ลากขอบซ้าย/ขวายืด/หด ปล่อยแล้ว PUT ผ่าน API เดียวกับแผงงาน
   งานใหญ่ที่ยังไม่มีวันอยู่ในกล่อง .rm-undated พร้อมทางไปใส่ในแผงรายละเอียดงาน
===================================================== */

const ROADMAP_MIN_MONTHS = 5;
const ROADMAP_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
    'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

/** งานใหญ่ทั้งหมดของโปรเจคที่เปิดอยู่ */
function roadmapEpics() {
    return ((activeProjectData && activeProjectData.tasks) || [])
        .filter(task => (task.kind || 'task') === 'epic');
}

/**
 * วันเริ่มและวันจบของงานใหญ่: วันจบคือ due_date เดิม
 * งานที่มีวันเดียวได้แถบหนึ่งวัน และถ้าสองวันสลับกันให้เรียงใหม่เพื่อวาดได้
 */
function roadmapEpicDates(task) {
    let start = task.start_date || '';
    let due = task.due_date || '';
    if (!start && !due) return { start: '', due: '' };
    if (!start) start = due;
    if (!due) due = start;
    if (start > due) {
        const swap = start;
        start = due;
        due = swap;
    }
    return { start, due };
}

/** วันที่ Y-m-d → ค่า UTC ของเที่ยงคืนวันนั้น (คำนวณวันไม่เพี้ยนเพราะ DST) */
function roadmapUtc(dateStr) {
    const [y, m, d] = dateStr.split('-').map(Number);
    return Date.UTC(y, m - 1, d);
}

/** ค่า UTC → วันที่ Y-m-d */
function roadmapYmd(ms) {
    const date = new Date(ms);
    return date.getUTCFullYear() + '-'
        + String(date.getUTCMonth() + 1).padStart(2, '0') + '-'
        + String(date.getUTCDate()).padStart(2, '0');
}

/** บวก/ลบจำนวนวันจากวันที่ Y-m-d */
function roadmapAddDays(dateStr, days) {
    return roadmapYmd(roadmapUtc(dateStr) + days * 86400000);
}

/** จำนวนวันจากวันหนึ่งถึงอีกวัน (บวกเมื่อปลายทางอยู่หลัง) */
function roadmapDaysBetween(from, to) {
    return Math.round((roadmapUtc(to) - roadmapUtc(from)) / 86400000);
}

/** เดือนของวันที่ Y-m-d ในรูป YYYY-MM */
function roadmapMonthKey(dateStr) {
    return String(dateStr).slice(0, 7);
}

/** วันแรกของเดือน YYYY-MM */
function roadmapMonthStart(monthKey) {
    return monthKey + '-01';
}

/** วันสุดท้ายของเดือน YYYY-MM */
function roadmapMonthEnd(monthKey) {
    const [y, m] = monthKey.split('-').map(Number);
    return roadmapYmd(Date.UTC(y, m, 0));
}

/** เลื่อนเดือน YYYY-MM ไปข้างหน้าหรือถอยหลังตามจำนวนที่ระบุ */
function roadmapAddMonths(monthKey, count) {
    const [y, m] = monthKey.split('-').map(Number);
    const total = y * 12 + (m - 1) + count;
    return String(Math.floor(total / 12)) + '-' + String((total % 12) + 1).padStart(2, '0');
}

/** จำนวนเดือนจากเดือนแรกถึงเดือนสุดท้าย (รวมทั้งสองเดือน) */
function roadmapMonthCount(startMonth, endMonth) {
    const [sy, sm] = startMonth.split('-').map(Number);
    const [ey, em] = endMonth.split('-').map(Number);
    return (ey - sy) * 12 + (em - sm) + 1;
}

/** หัวเดือนแบบสั้น: "พ.ย. 69" (พ.ศ. สองหลักท้าย) */
function roadmapMonthLabel(monthKey) {
    const [y, m] = monthKey.split('-').map(Number);
    return ROADMAP_MONTHS[m - 1] + ' ' + String((y + 543) % 100).padStart(2, '0');
}

/** ค่าเปอร์เซ็นต์สั้น ๆ โดยไม่ให้มีศูนย์ท้ายเกินจำเป็น */
function roadmapPercent(value) {
    return String(Number(Number(value).toFixed(4)));
}

/**
 * ช่วงเวลาของแผนงาน: เริ่มที่เดือนของวันแรกสุด (หรือเดือนนี้เมื่อยังไม่มีวัน)
 * และยาวอย่างน้อย ROADMAP_MIN_MONTHS เดือน โดยมีเดือนของวันนี้อยู่ในช่วงเสมอ
 */
function roadmapTimeline(epics) {
    const today = serverToday();
    let first = today;
    let last = today;
    epics.forEach(task => {
        const dates = roadmapEpicDates(task);
        if (dates.start && dates.start < first) first = dates.start;
        if (dates.due && dates.due > last) last = dates.due;
    });
    const startMonth = roadmapMonthKey(first);
    const endMonth = roadmapMonthKey(last);
    const count = Math.max(ROADMAP_MIN_MONTHS, roadmapMonthCount(startMonth, endMonth));
    const months = [];
    for (let i = 0; i < count; i++) months.push(roadmapAddMonths(startMonth, i));
    const start = roadmapMonthStart(startMonth);
    const end = roadmapMonthEnd(months[months.length - 1]);
    return { start, end, months, totalDays: roadmapDaysBetween(start, end) + 1 };
}

/** วาดแท็บแผนงานใหม่ทั้งหมดจากข้อมูลโปรเจคที่โหลดอยู่ */
function renderRoadmap() {
    const software = isSoftwareProject();
    show('tabRoadmap', software);
    if (!software) {
        if (projectSettingsTab === 'roadmap') showProjectTab('board');
        return;
    }

    const root = document.getElementById('roadmap');
    if (!root) return;
    const empty = document.getElementById('roadmapEmpty');
    const epics = roadmapEpics();
    if (!epics.length) {
        root.innerHTML = '';
        root.style.removeProperty('--rm-months');
        if (empty) {
            empty.hidden = false;
            empty.textContent = 'ยังไม่มีงานใหญ่ สร้างงานใหญ่ในแผงรายละเอียดงานก่อน';
        }
        renderRoadmapUndated([]);
        return;
    }
    if (empty) {
        empty.hidden = true;
        empty.textContent = '';
    }

    buildTaskIndex();
    const timeline = roadmapTimeline(epics);
    const today = serverToday();
    const todayMonth = roadmapMonthKey(today);
    const todayLeft = roadmapDaysBetween(timeline.start, today) / timeline.totalDays * 100;
    const dated = [];
    const undated = [];
    epics.forEach(task => {
        const dates = roadmapEpicDates(task);
        if (dates.start) dated.push({ task, dates });
        else undated.push(task);
    });
    dated.sort((a, b) => a.dates.start.localeCompare(b.dates.start) || Number(a.task.number) - Number(b.task.number));

    root.style.setProperty('--rm-months', String(timeline.months.length));
    let html = '<div class="rm-name rm-head"><b class="text-sm">งานใหญ่</b></div>'
        + timeline.months.map(month => '<div class="rm-month' + (month === todayMonth ? ' now' : '') + '">'
            + escHtml(roadmapMonthLabel(month)) + '</div>').join('');

    dated.forEach(({ task, dates }) => {
        const progress = epicProgress(task.id);
        const percent = progress.total ? Math.round(progress.done / progress.total * 100) : 0;
        const leftDay = roadmapDaysBetween(timeline.start, dates.start);
        const span = roadmapDaysBetween(dates.start, dates.due) + 1;
        const key = taskKey(task);
        html += '<div class="rm-name">' + kindIcon('epic')
            + '<span class="rm-title">' + (key ? '<span class="key">' + escHtml(key) + '</span> ' : '')
            + escHtml(task.title || '') + '</span>'
            + '<span class="pct">' + percent + '%</span></div>'
            + '<div class="rm-lane">'
            + '<div class="rm-bar" data-epic="' + Number(task.id) + '"'
            + ' style="left:' + roadmapPercent(leftDay / timeline.totalDays * 100) + '%;'
            + 'width:' + roadmapPercent(span / timeline.totalDays * 100) + '%"'
            + ' title="ลากเพื่อเลื่อน ลากขอบเพื่อยืดหรือหด">'
            + '<i class="fill" style="width:' + percent + '%"></i>'
            + '<span>' + escHtml(task.title || '') + '</span></div>'
            + '<i class="rm-today" style="left:' + roadmapPercent(todayLeft) + '%"></i></div>';
    });
    root.innerHTML = html;
    renderRoadmapUndated(undated);
}

/** งานใหญ่ที่ยังไม่มีวัน: อยู่ในกล่องใต้ตาราง กดชื่อเพื่อเปิดแผงแล้วใส่วัน */
function renderRoadmapUndated(undated) {
    const box = document.getElementById('roadmapUndated');
    if (!box) return;
    box.hidden = undated.length === 0;
    if (!undated.length) {
        box.innerHTML = '';
        return;
    }
    box.innerHTML = '<b>ยังไม่ลงวัน:</b> ' + undated.map(task => {
        const key = taskKey(task);
        return '<button type="button" class="rm-undated-item" data-act="openEditTask" data-args="[' + Number(task.id) + ']">'
            + kindIcon('epic') + (key ? '<span class="key">' + escHtml(key) + '</span> ' : '')
            + escHtml(task.title || '') + '</button>';
    }).join(' ') + ' <span class="sub">— ใส่วันเริ่ม-จบในแผงรายละเอียดงาน</span>';
}

/**
 * เริ่มลากแถบแผนงาน: ขอบซ้าย/ขวายืดวันเริ่ม/จบ กลางแถบเลื่อนทั้งช่วง
 * ใช้ pointer events จึงได้ทั้งเมาส์และนิ้ว (ลากแล้วไม่เปิดแผง กดเฉย ๆ จึงเปิด)
 */
function startRoadmapDrag(event) {
    const bar = event.target.closest ? event.target.closest('#roadmap .rm-bar') : null;
    if (!bar || (event.pointerType === 'mouse' && event.button !== 0)) return;
    const task = ((activeProjectData && activeProjectData.tasks) || [])
        .find(item => Number(item.id) === Number(bar.dataset.epic));
    if (!task) return;
    const dates = roadmapEpicDates(task);
    if (!dates.start) return;

    const lane = bar.parentNode;
    const laneWidth = lane.getBoundingClientRect().width;
    const barRect = bar.getBoundingClientRect();
    if (!laneWidth) return;
    const mode = event.clientX - barRect.left <= 10
        ? 'start'
        : barRect.right - event.clientX <= 10 ? 'end' : 'move';
    const timeline = roadmapTimeline(roadmapEpics());
    const startDay = roadmapDaysBetween(timeline.start, dates.start);
    const dueDay = roadmapDaysBetween(timeline.start, dates.due);
    const x0 = event.clientX;

    const tip = document.createElement('span');
    tip.className = 'rm-tip';
    tip.textContent = formatDate(dates.start) + ' – ' + formatDate(dates.due);
    lane.appendChild(tip);
    tip.style.left = bar.style.left;
    bar.classList.add('dragging');

    let next = { start: dates.start, due: dates.due };
    let moved = false;

    function onMove(moveEvent) {
        moveEvent.preventDefault();
        const delta = Math.round((moveEvent.clientX - x0) / laneWidth * timeline.totalDays);
        let start = startDay;
        let due = dueDay;
        if (mode === 'move') {
            start += delta;
            due += delta;
        } else if (mode === 'start') {
            start = Math.min(startDay + delta, dueDay);
        } else {
            due = Math.max(dueDay + delta, startDay);
        }
        // อยู่ในช่วงที่วาดอยู่และยาวอย่างน้อยหนึ่งวัน
        start = Math.max(0, Math.min(start, timeline.totalDays - 1));
        due = Math.max(0, Math.min(due, timeline.totalDays - 1));
        if (due < start) {
            if (mode === 'start') start = due;
            else due = start;
        }
        next = {
            start: roadmapAddDays(timeline.start, start),
            due: roadmapAddDays(timeline.start, due)
        };
        bar.style.left = roadmapPercent(start / timeline.totalDays * 100) + '%';
        bar.style.width = roadmapPercent((due - start + 1) / timeline.totalDays * 100) + '%';
        tip.style.left = bar.style.left;
        tip.textContent = formatDate(next.start) + ' – ' + formatDate(next.due);
        moved = next.start !== dates.start || next.due !== dates.due;
    }

    function finish(upEvent) {
        document.removeEventListener('pointermove', onMove);
        document.removeEventListener('pointerup', finish);
        document.removeEventListener('pointercancel', finish);
        tip.remove();
        bar.classList.remove('dragging');
        if (moved) saveRoadmapDates(Number(task.id), next.start, next.due);
        else if (upEvent.type === 'pointerup') openEditTask(Number(task.id));
    }

    event.preventDefault();
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', finish);
    document.addEventListener('pointercancel', finish);
}

/** บันทึกวันใหม่ผ่าน API เดียวกับแผงงาน แล้ววาดจากข้อมูลเซิร์ฟเวอร์ (ผิดพลาดก็วาดกลับ) */
async function saveRoadmapDates(taskId, start, due) {
    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + Number(taskId), {
            method: 'PUT',
            body: JSON.stringify({ start_date: start, due_date: due })
        });
        await selectProject(activeProjectId);
    } catch (err) {
        toast(err.message || 'บันทึกวันไม่สำเร็จ ลองอีกครั้ง', 'danger');
        await selectProject(activeProjectId);
    }
}

document.addEventListener('pointerdown', startRoadmapDrag);
