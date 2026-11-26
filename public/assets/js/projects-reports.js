/* =====================================================
   projects-reports.js — กราฟงานคงเหลือ (burndown) และความเร็วทีม (velocity)
   ในแท็บภาพรวมของโปรเจคซอฟต์แวร์

   โหลดหลัง projects-sprints.js และใช้สถานะร่วมกับไฟล์อื่น
   ข้อมูลทั้งหมดมาจาก GET /api/projects/{id}/reports ซึ่งสมาชิกทุกบทบาทอ่านได้
   แต่ผู้เยี่ยมชมจากลิงก์แชร์อ่านไม่ได้ หน้านี้จึงซ่อนส่วนกราฟสำหรับผู้เยี่ยมชม
   (แท็บตั้งค่าถูกข้ามไปสำหรับผู้เยี่ยมชม ใช้การไม่มีแท็บนั้นเป็นเครื่องหมาย)
===================================================== */

/** ลำดับคำขอรายงานล่าสุด ใช้ทิ้งคำตอบเก่าที่มาช้ากว่าคำขอใหม่ */
let reportsRequestSeq = 0;

/** รหัสโปรเจคของรายงานที่กำลังแสดงอยู่ (null = ยังไม่เคยโหลด) */
let reportsProjectId = null;

/** รหัสรอบงานที่ผู้ใช้เลือกจากตัวเลือก "รอบ" (null = ให้เซิร์ฟเวอร์เลือกรอบเอง) */
let reportSprintId = null;

/** หน้านี้เป็นหน้าของผู้เยี่ยมชมจากลิงก์แชร์หรือไม่ (แท็บตั้งค่าไม่มีในหน้านั้น) */
function isSharedGuestPage() {
    return !document.getElementById('tabSettings');
}

/** แสดงกราฟได้เฉพาะโปรเจคซอฟต์แวร์ที่เปิดใช้รอบงาน และไม่ใช่หน้าลิงก์แชร์ */
function canShowSprintReports() {
    return !isSharedGuestPage() && typeof isSprintMode === 'function' && isSprintMode();
}

/** ซ่อน/แสดงองค์ประกอบด้วยแอตทริบิวต์ hidden ซึ่งใช้ได้กับ SVG ด้วย */
function reportShow(id, visible) {
    const el = document.getElementById(id);
    if (!el) return;
    if (visible) el.removeAttribute('hidden');
    else el.setAttribute('hidden', '');
}

/**
 * เรียกเมื่อแท็บเปลี่ยนหรือโปรเจคโหลดใหม่: ซ่อนส่วนกราฟเมื่อไม่ใช่แท็บภาพรวม
 * หรือไม่ใช่โหมดรอบงาน แล้วโหลดรายงานเมื่อเปิดแท็บภาพรวม
 */
function syncSprintReports() {
    const section = document.getElementById('sprintReports');
    if (!section) return;
    const visible = projectSettingsTab === 'overview' && canShowSprintReports();
    section.hidden = !visible;
    if (!visible) {
        reportsRequestSeq++;
        return;
    }
    loadSprintReports();
}

/** โหลดรายงานของโปรเจคที่เปิดอยู่แล้ววาดกราฟ เมื่อคำขอยังเป็นคำขอล่าสุดของโปรเจคเดิม */
async function loadSprintReports() {
    if (!activeProjectId || !canShowSprintReports()) return;
    const projectId = Number(activeProjectId);
    if (reportsProjectId !== projectId) {
        reportsProjectId = projectId;
        reportSprintId = null;
    }

    const seq = ++reportsRequestSeq;
    const query = reportSprintId ? '?sprint=' + encodeURIComponent(String(reportSprintId)) : '';
    try {
        const data = await apiFetch(BASE_URL + '/api/projects/' + projectId + '/reports' + query);
        if (seq !== reportsRequestSeq) return;
        if (projectId !== Number(activeProjectId)) return;
        renderSprintReports(data || {});
    } catch (_) {
        /* เก็บกราฟเดิมไว้ ไม่รบกวนผู้ใช้ด้วยข้อความเมื่อโหลดไม่ได้ */
    }
}

/** รับค่าจากตัวเลือก "รอบ" แล้วโหลดรายงานของรอบนั้นใหม่ */
function selectReportSprint(value) {
    const id = Number(value);
    reportSprintId = id > 0 ? id : null;
    loadSprintReports();
}

/** วาดการ์ด burndown และ velocity จากคำตอบของ /reports */
function renderSprintReports(data) {
    renderBurndownCard(data.burndown || null);
    renderVelocityCard(data.velocity || { sprints: [], average: null });
}

/** ปัดตัวเลขเป็นทศนิยมหนึ่งตำแหน่งสำหรับใส่ใน SVG */
function reportNum(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '0';
    return String(Math.round(n * 10) / 10);
}

/** แปลง 'YYYY-MM-DD' เป็นจำนวนวันแบบ UTC เพื่อบวกวันโดยไม่เพี้ยนเพราะเขตเวลา */
function reportDayNumber(day) {
    const parts = String(day).split('-').map(Number);
    return Date.UTC(parts[0], parts[1] - 1, parts[2]) / 86400000;
}

/** สร้าง 'YYYY-MM-DD' จากจำนวนวันแบบ UTC */
function reportDayFromNumber(number) {
    const date = new Date(number * 86400000);
    return date.getUTCFullYear() + '-'
        + String(date.getUTCMonth() + 1).padStart(2, '0') + '-'
        + String(date.getUTCDate()).padStart(2, '0');
}

/** เลือกยอดสูงสุดของแกน y ที่เป็นเลขกลมและแบ่งได้ 4 ช่วงเท่า ๆ กัน */
function reportAxis(maxValue) {
    const target = Math.max(Number(maxValue) || 0, 4) / 4;
    const power = Math.pow(10, Math.floor(Math.log10(target)));
    const step = [1, 2, 2.5, 5, 10]
        .map(multiplier => multiplier * power)
        .find(candidate => candidate >= target) || 10 * power;
    return { max: step * 4, step: step };
}

/** ตำแหน่งของป้ายแกน x ที่มากที่สุด 5 ป้าย กระจายเท่า ๆ กันตลอดช่วง */
function reportLabelIndexes(count) {
    if (count <= 5) return Array.from({ length: count }, (_, index) => index);
    const picks = [
        0,
        Math.round((count - 1) / 4),
        Math.round((count - 1) / 2),
        Math.round((count - 1) * 3 / 4),
        count - 1
    ];
    return picks.filter((value, index) => picks.indexOf(value) === index);
}

/** ชื่อรอบงานที่ยาวเกิน 8 ตัวอักษรตัดด้วย … (ชื่อเต็มยังอยู่ใน title) */
function reportShortName(name) {
    const chars = Array.from(String(name || ''));
    return chars.length > 8 ? chars.slice(0, 8).join('') + '…' : chars.join('');
}

/** เติมตัวเลือกรอบเมื่อมีรอบ active/closed ให้เลือกมากกว่าหนึ่งรอบ */
function renderReportSprintPicker(burndown) {
    const picker = document.getElementById('reportSprintPick');
    const select = document.getElementById('reportSprintSelect');
    if (!picker || !select) return;

    const sprints = projectSprints().filter(sprint => sprint.state === 'active' || sprint.state === 'closed');
    if (sprints.length <= 1) {
        select.innerHTML = '';
        picker.hidden = true;
        return;
    }

    const current = burndown && burndown.sprint
        ? Number(burndown.sprint.id)
        : Number(reportSprintId) || 0;
    select.innerHTML = sprints.map(sprint =>
        '<option value="' + Number(sprint.id) + '"'
        + (Number(sprint.id) === current ? ' selected' : '') + '>'
        + escHtml(sprint.name) + '</option>'
    ).join('');
    picker.hidden = false;
}

/** วาดการ์ดงานคงเหลือรายวัน: แกนวันของรอบ เส้นจริง จุด เส้นที่ควรเป็น และตาราง */
function renderBurndownCard(burndown) {
    const title = document.getElementById('burndownTitle');
    const chart = document.getElementById('burndownChart');
    const table = document.getElementById('burndownTable');
    const empty = document.getElementById('burndownEmpty');
    if (!title || !chart || !table) return;

    renderReportSprintPicker(burndown);

    const sprint = (burndown && burndown.sprint) || {};
    const days = (burndown && Array.isArray(burndown.days)) ? burndown.days : [];
    const name = sprint.name || '';
    title.textContent = 'งานคงเหลือรายวัน' + (name ? ' · ' + name : '');

    if (!burndown || !days.length) {
        chart.innerHTML = '';
        table.innerHTML = '';
        reportShow('burndownChart', false);
        reportShow('burndownLegend', false);
        reportShow('burndownDetails', false);
        empty.textContent = 'ยังไม่มีข้อมูล เริ่มรอบแล้วกราฟจะขึ้นตั้งแต่วันแรก';
        reportShow('burndownEmpty', true);
        return;
    }

    let firstDay = days[0].day;
    let lastDay = days[days.length - 1].day;
    if (sprint.start_date && sprint.start_date < firstDay) firstDay = sprint.start_date;
    if (sprint.end_date && sprint.end_date > lastDay) lastDay = sprint.end_date;

    const startNumber = reportDayNumber(firstDay);
    const endNumber = reportDayNumber(lastDay);
    const range = [];
    const indexByDay = new Map();
    for (let number = startNumber; number <= endNumber; number++) {
        const day = reportDayFromNumber(number);
        indexByDay.set(day, range.length);
        range.push(day);
    }
    if (!range.length) range.push(firstDay);

    const scopeStart = Number(days[0].scope) || 0;
    let largestScope = 0;
    days.forEach(day => { largestScope = Math.max(largestScope, Number(day.scope) || 0); });
    const axis = reportAxis(largestScope);
    const W = 460;
    const H = 240;
    const L = 34;
    const R = 14;
    const T = 12;
    const B = 28;
    const x = index => range.length <= 1
        ? L + (W - L - R) / 2
        : L + index * (W - L - R) / (range.length - 1);
    const y = value => {
        const clamped = Math.max(0, Math.min(Number(value) || 0, axis.max));
        return T + (1 - clamped / axis.max) * (H - T - B);
    };

    let grid = '<g class="grid">';
    let axisMarks = '<g class="axis">';
    for (let index = 0; index < 4; index++) {
        const value = axis.step * index;
        grid += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + reportNum(y(value))
            + '" y2="' + reportNum(y(value)) + '"/>';
        axisMarks += '<text x="' + (L - 6) + '" y="' + reportNum(y(value) + 4)
            + '" text-anchor="end">' + reportNum(value) + '</text>';
    }
    grid += '</g>';

    reportLabelIndexes(range.length).forEach(index => {
        const label = parseServerDate(range[index]).getDate();
        axisMarks += '<text x="' + reportNum(x(index)) + '" y="' + (H - 8)
            + '" text-anchor="middle">' + label + '</text>';
    });
    axisMarks += '</g>';

    let today = '';
    const lastReturned = days[days.length - 1];
    const lastReturnedIndex = indexByDay.get(lastReturned.day);
    if (sprint.state === 'active' && lastReturnedIndex !== undefined) {
        today = '<g class="today"><line x1="' + reportNum(x(lastReturnedIndex))
            + '" x2="' + reportNum(x(lastReturnedIndex)) + '" y1="' + T + '" y2="' + (H - B) + '"/>'
            + '<text x="' + reportNum(x(lastReturnedIndex) - 4) + '" y="' + (T + 10)
            + '" text-anchor="end">วันนี้</text></g>';
    }

    const ideal = '<line class="ideal" x1="' + reportNum(x(0)) + '" y1="' + reportNum(y(scopeStart))
        + '" x2="' + reportNum(x(range.length - 1)) + '" y2="' + reportNum(y(0)) + '"/>';

    const points = [];
    const dots = [];
    days.forEach(day => {
        const index = indexByDay.get(day.day);
        if (index === undefined) return;
        const cx = reportNum(x(index));
        const cy = reportNum(y(day.remaining));
        points.push(cx + ',' + cy);
        dots.push('<circle class="dot" cx="' + cx + '" cy="' + cy + '" r="3"/>');
    });

    const actual = points.length
        ? '<polyline class="actual" points="' + points.join(' ') + '"/>' + dots.join('')
        : '';

    const lastValue = Number(lastReturned.remaining) || 0;
    const labelAnchor = x(lastReturnedIndex) + 8 > W - 10 ? 'end' : 'start';
    const labelX = labelAnchor === 'end' ? x(lastReturnedIndex) - 6 : x(lastReturnedIndex) + 6;
    const valueLabel = '<text class="lbl" x="' + reportNum(labelX) + '" y="' + reportNum(y(lastValue) + 4)
        + '" text-anchor="' + labelAnchor + '">' + reportNum(lastValue) + '</text>';

    const firstRemaining = Number(days[0].remaining) || 0;
    const desc = '<desc id="burndownDesc">กราฟงานคงเหลือ ' + escHtml(name)
        + ' เริ่ม ' + reportNum(firstRemaining) + ' แต้ม วันนี้เหลือ ' + reportNum(lastValue) + ' แต้ม</desc>';

    chart.innerHTML = desc + grid + axisMarks + ideal + today + actual + valueLabel;
    reportShow('burndownChart', true);
    reportShow('burndownLegend', true);
    reportShow('burndownDetails', true);
    reportShow('burndownEmpty', false);

    const rows = days.map(day => {
        const index = indexByDay.get(day.day);
        const idealValue = range.length <= 1
            ? 0
            : Math.round(scopeStart * (1 - index / (range.length - 1)));
        return '<tr><td>' + escHtml(formatDate(day.day)) + '</td>'
            + '<td>' + reportNum(day.remaining) + '</td>'
            + '<td>' + idealValue + '</td>'
            + '<td>' + reportNum(day.scope) + '</td></tr>';
    }).join('');
    table.innerHTML = '<thead><tr><th scope="col">วันที่</th><th scope="col">คงเหลือ</th>'
        + '<th scope="col">ที่ควรเป็น</th><th scope="col">ขอบเขต</th></tr></thead>'
        + '<tbody>' + rows + '</tbody>';
}

/** วาดการ์ดความเร็วทีม: แท่งคู่ วางแผน/เสร็จ ต่อรอบ เส้นเฉลี่ย และตาราง */
function renderVelocityCard(velocity) {
    const title = document.getElementById('velocityTitle');
    const sub = document.getElementById('velocitySub');
    const chart = document.getElementById('velocityChart');
    const table = document.getElementById('velocityTable');
    const empty = document.getElementById('velocityEmpty');
    if (!title || !sub || !chart || !table) return;

    const sprints = (velocity && Array.isArray(velocity.sprints)) ? velocity.sprints : [];
    const average = velocity && velocity.average !== null && velocity.average !== undefined
        ? Number(velocity.average)
        : null;

    title.textContent = sprints.length ? 'ความเร็วทีม ' + sprints.length + ' รอบล่าสุด' : 'ความเร็วทีม';
    sub.innerHTML = 'แต้มที่วางแผนตอนเริ่มรอบ เทียบกับที่เสร็จ'
        + (average !== null ? ' · เฉลี่ย 3 รอบล่าสุด <b>' + reportNum(average) + '</b> แต้ม' : '');

    if (!sprints.length) {
        chart.innerHTML = '';
        table.innerHTML = '';
        reportShow('velocityChart', false);
        reportShow('velocityLegend', false);
        reportShow('velocityDetails', false);
        empty.textContent = 'ยังไม่มีรอบที่ปิด ปิดรอบแรกแล้วจะเห็นความเร็วทีม';
        reportShow('velocityEmpty', true);
        return;
    }

    let largest = 0;
    sprints.forEach(sprint => {
        largest = Math.max(largest, Number(sprint.committed) || 0, Number(sprint.completed) || 0);
    });
    if (average !== null) largest = Math.max(largest, average);
    const axis = reportAxis(largest);
    const W = 320;
    const H = 240;
    const L = 30;
    const R = 8;
    const T = 12;
    const B = 40;
    const slot = (W - L - R) / sprints.length;
    const y = value => {
        const clamped = Math.max(0, Math.min(Number(value) || 0, axis.max));
        return T + (1 - clamped / axis.max) * (H - T - B);
    };

    let grid = '<g class="grid">';
    let axisMarks = '<g class="axis">';
    for (let index = 0; index < 4; index++) {
        const value = axis.step * index;
        grid += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + reportNum(y(value))
            + '" y2="' + reportNum(y(value)) + '"/>';
        axisMarks += '<text x="' + (L - 6) + '" y="' + reportNum(y(value) + 4)
            + '" text-anchor="end">' + reportNum(value) + '</text>';
    }
    grid += '</g>';

    let bars = '';
    sprints.forEach((sprint, index) => {
        const x0 = L + index * slot + 6;
        const width = Math.max((slot - 16) / 2, 1);
        const committed = Number(sprint.committed) || 0;
        const completed = Number(sprint.completed) || 0;
        const name = sprint.name || '';
        const shortName = reportShortName(name);
        bars += '<rect class="bar-plan" x="' + reportNum(x0) + '" y="' + reportNum(y(committed))
            + '" width="' + reportNum(width) + '" height="' + reportNum(y(0) - y(committed))
            + '" rx="2"><title>' + escHtml(name) + ' วางแผน ' + reportNum(committed) + '</title></rect>'
            + '<rect class="bar-done" x="' + reportNum(x0 + width + 2) + '" y="' + reportNum(y(completed))
            + '" width="' + reportNum(width) + '" height="' + reportNum(y(0) - y(completed))
            + '" rx="2"><title>' + escHtml(name) + ' เสร็จ ' + reportNum(completed) + '</title></rect>'
            + '<g class="axis"><text x="' + reportNum(x0 + width + 1) + '" y="' + (H - 10)
            + '" text-anchor="middle"><title>' + escHtml(name) + '</title>'
            + escHtml(shortName) + '</text></g>';
    });
    axisMarks += '</g>';

    const avgLine = average !== null
        ? '<line class="avg" x1="' + L + '" x2="' + (W - R) + '" y1="' + reportNum(y(average))
            + '" y2="' + reportNum(y(average)) + '"/>'
        : '';
    const desc = '<desc id="velocityDesc">กราฟแท่ง ความเร็วทีม ' + sprints.length + ' รอบล่าสุด</desc>';

    chart.innerHTML = desc + grid + axisMarks + bars + avgLine;
    reportShow('velocityChart', true);
    reportShow('velocityLegend', true);
    reportShow('velocityDetails', true);
    reportShow('velocityEmpty', false);

    const rows = sprints.map(sprint =>
        '<tr><td>' + escHtml(sprint.name || '') + '</td>'
        + '<td>' + reportNum(sprint.committed) + '</td>'
        + '<td>' + reportNum(sprint.completed) + '</td></tr>'
    ).join('');
    table.innerHTML = '<thead><tr><th scope="col">รอบ</th><th scope="col">วางแผน</th>'
        + '<th scope="col">เสร็จ</th></tr></thead><tbody>' + rows + '</tbody>';
}
