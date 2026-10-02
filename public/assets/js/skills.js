/* =====================================================
   skills.js — time spent on skills

   The stopwatch lives on the server (a running timer survives a reload or
   another device), so the page only counts up from the stored start time.
===================================================== */

let skills = [];
let activeTimer = null;
let timerInterval = null;
let timerStartTime = null;

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('timerNotesInput').addEventListener('change', saveTimerNotes);
    loadData();
});

/* ── Time ── */
function formatClock(totalSeconds) {
    const t = Math.max(0, Math.floor(totalSeconds));
    const pad = n => String(n).padStart(2, '0');
    return pad(Math.floor(t / 3600)) + ':' + pad(Math.floor((t % 3600) / 60)) + ':' + pad(t % 60);
}

/** "3 ชม. 20 นาที" */
function formatDuration(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    if (!hours) return minutes + ' นาที';
    return hours + ' ชม.' + (minutes ? ' ' + minutes + ' นาที' : '');
}

/** The same for a headline figure, with the units small. */
function formatDurationHtml(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    return (hours ? hours + '<small>ชม.</small> ' : '') + minutes + '<small>นาที</small>';
}

/* ── Load ── */
async function loadData() {
    try {
        skills = await apiFetch(BASE_URL + '/api/skills');
        renderSkillSelect();

        const stats = await apiFetch(BASE_URL + '/api/skills/stats');
        document.getElementById('statToday').innerHTML = formatDurationHtml(stats.today_seconds);
        document.getElementById('statWeek').innerHTML = formatDurationHtml(stats.week_seconds);
        document.getElementById('statMonth').innerHTML = formatDurationHtml(stats.month_seconds);
        document.getElementById('statTotal').innerHTML = formatDurationHtml(stats.total_seconds);

        renderSkillsProgress(stats.skills_progress || []);
        if (stats.active_timer) setActiveTimer(stats.active_timer);
        else clearActiveTimer();

        await loadLogs();
    } catch (err) {
        console.error(err);
        for (const id of ['skillsListContainer', 'logsList']) {
            const el = document.getElementById(id);
            el.removeAttribute('aria-busy');
            el.innerHTML = '<div class="alert alert-danger" role="alert">โหลดข้อมูลไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
                + '<button type="button" class="btn btn-sm" data-act="loadData">ลองอีกครั้ง</button></div>';
        }
    }
}

function renderSkillSelect() {
    const select = document.getElementById('timerSkillSelect');
    const chosen = select.value;
    select.innerHTML = '<option value="">เลือกทักษะ</option>'
        + skills.map(s => '<option value="' + escHtml(s.id) + '">' + escHtml(s.name) + '</option>').join('');
    select.value = chosen;
}

function renderSkillsProgress(progress) {
    const container = document.getElementById('skillsListContainer');
    container.removeAttribute('aria-busy');

    if (!skills.length) {
        container.innerHTML = '<div class="empty-state"><p class="empty-state-title">ยังไม่มีทักษะ</p>'
            + '<p class="empty-state-text">เพิ่มทักษะที่อยากฝึก ตั้งเป้าชั่วโมง แล้วจับเวลาทุกครั้งที่ฝึก</p>'
            + '<button type="button" class="btn btn-primary" data-act="openSkillModal">เพิ่มทักษะแรก</button></div>';
        return;
    }

    const seconds = {};
    progress.forEach(p => { seconds[p.skill_id] = parseInt(p.total_seconds, 10) || 0; });

    container.innerHTML = '<ul class="ruled-list">' + skills.map(s => {
        const hours = (seconds[s.id] || 0) / 3600;
        const target = parseInt(s.target_hours, 10) || 10000;
        const percent = Math.min(100, hours / target * 100);
        const name = escHtml(s.name);
        return '<li class="sk-goal" style="--skill:' + cssColor(s.color, '#5E6673') + '">'
            + '<div class="sk-goal-head"><span class="sk-goal-name">' + name + '</span>'
            + '<button type="button" class="icon-btn sm" data-act="editSkill" data-args="' + escHtml(JSON.stringify([s.id])) + '" aria-label="แก้ไข: ' + name + '"><svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg></button>'
            + '<button type="button" class="icon-btn sm" data-act="deleteSkill" data-args="' + escHtml(JSON.stringify([s.id])) + '" aria-label="ลบ: ' + name + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button></div>'
            + '<div class="progress sk-bar" role="img" aria-label="' + name + ' ทำได้ ' + percent.toFixed(2) + ' เปอร์เซ็นต์ของเป้าหมาย"><div class="progress-bar" style="--v:' + percent + '%"></div></div>'
            + '<p class="sk-goal-meta">' + hours.toFixed(1) + ' ชม. จาก ' + target.toLocaleString('th-TH') + ' ชม. (' + percent.toFixed(2) + '%)'
            + (hours >= target ? ' · ถึงเป้าแล้ว' : '') + '</p></li>';
    }).join('') + '</ul>';
}

async function loadLogs() {
    const el = document.getElementById('logsList');
    const logs = await apiFetch(BASE_URL + '/api/skills/logs');
    el.removeAttribute('aria-busy');

    if (!logs.length) {
        el.innerHTML = '<div class="empty-state"><p class="empty-state-title">ยังไม่มีประวัติการจับเวลา</p>'
            + '<p class="empty-state-text">เลือกทักษะด้านบนแล้วกด "เริ่มจับเวลา" ทุกครั้งที่ฝึก เวลาจะมาอยู่ตรงนี้</p></div>';
        return;
    }

    el.innerHTML = '<ul class="ruled-list">' + logs.map(l => {
        const name = escHtml(l.skill_name);
        return '<li class="ruled-row sk-log" style="--skill:' + cssColor(l.skill_color, '#5E6673') + '">'
            + '<span class="sk-log-when">' + escHtml(formatDate(l.start_time.substring(0, 10)))
            + '<small>' + l.start_time.substring(11, 16) + '–' + l.end_time.substring(11, 16) + '</small></span>'
            + '<span class="grow"><span class="title sk-name">' + name + '</span>'
            + (l.notes ? '<span class="meta">' + escHtml(l.notes) + '</span>' : '') + '</span>'
            + '<span class="side sk-dur">' + formatDuration(l.duration_seconds) + '</span>'
            + '<button type="button" class="icon-btn sm" data-act="deleteLog" data-args="[' + l.id + ']" aria-label="ลบรายการ ' + name + ' ' + formatDuration(l.duration_seconds) + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
            + '</li>';
    }).join('') + '</ul>'
        + (logs.length >= 50 ? '<p class="sk-more">แสดง 50 รายการล่าสุด</p>' : '');
}

/* ── The stopwatch ── */
function timerError(message) {
    const line = document.getElementById('timerError');
    line.textContent = message;
    line.hidden = message === '';
}

function setActiveTimer(timer) {
    activeTimer = timer;
    // "2026-10-02 14:00:00" is not parsed the same everywhere; slashes are.
    timerStartTime = new Date(timer.start_time.replace(/-/g, '/')).getTime();

    const select = document.getElementById('timerSkillSelect');
    select.value = timer.skill_id;
    select.disabled = true;
    document.getElementById('timerNotesInput').value = timer.notes || '';

    const btn = document.getElementById('btnTimerToggle');
    btn.textContent = 'หยุดและบันทึก';
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-danger');
    document.getElementById('timerDisplay').classList.add('running');

    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(updateTimerDisplay, 1000);
    updateTimerDisplay();
}

function clearActiveTimer() {
    activeTimer = null;
    timerStartTime = null;
    if (timerInterval) clearInterval(timerInterval);

    const select = document.getElementById('timerSkillSelect');
    select.disabled = false;
    select.value = '';
    document.getElementById('timerNotesInput').value = '';
    document.getElementById('timerDisplay').textContent = '00:00:00';
    document.getElementById('timerDisplay').classList.remove('running');
    document.title = 'ทักษะ';

    const btn = document.getElementById('btnTimerToggle');
    btn.textContent = 'เริ่มจับเวลา';
    btn.classList.remove('btn-danger');
    btn.classList.add('btn-primary');
}

function updateTimerDisplay() {
    if (!timerStartTime) return;
    const clock = formatClock((Date.now() - timerStartTime) / 1000);
    document.getElementById('timerDisplay').textContent = clock;
    document.title = clock + ' · ทักษะ';
}

async function toggleTimer() {
    timerError('');
    try {
        if (activeTimer) {
            await apiFetch(BASE_URL + '/api/skills/timer/stop', { method: 'POST' });
            clearActiveTimer();
            await loadData();
            toast('หยุดจับเวลาและบันทึกแล้ว');
            return;
        }

        const skillId = document.getElementById('timerSkillSelect').value;
        if (!skillId) {
            timerError(skills.length ? 'เลือกทักษะก่อนเริ่มจับเวลา' : 'เพิ่มทักษะก่อน แล้วจึงจับเวลาได้');
            document.getElementById('timerSkillSelect').focus();
            return;
        }
        await apiFetch(BASE_URL + '/api/skills/timer/start', {
            method: 'POST',
            body: JSON.stringify({ skill_id: skillId, notes: document.getElementById('timerNotesInput').value }),
        });
        await loadData(); // the start time comes from the server
    } catch (err) {
        timerError(err.message || 'จับเวลาไม่สำเร็จ ลองอีกครั้ง');
    }
}

/** A note changed while the timer runs goes to the running timer. */
async function saveTimerNotes(event) {
    if (!activeTimer) return;
    try {
        await apiFetch(BASE_URL + '/api/skills/timer/update', { method: 'POST', body: JSON.stringify({ notes: event.target.value }) });
    } catch {
        toast('บันทึกข้อความไม่สำเร็จ', 'danger');
    }
}

/* ── Skills ── */
function skillError(message) {
    const line = document.getElementById('skillError');
    line.textContent = message;
    line.hidden = message === '';
}

function openSkillModal() {
    document.getElementById('skillId').value = '';
    document.getElementById('skillName').value = '';
    document.getElementById('skillTargetHours').value = '10000';
    document.getElementById('skillColor').value = '#2D7B4D';
    document.getElementById('skillModalTitle').textContent = 'เพิ่มทักษะ';
    skillError('');
    openModal('skillModal');
    document.getElementById('skillName').focus();
}

function editSkill(id) {
    const skill = skills.find(s => s.id === id);
    if (!skill) return;
    document.getElementById('skillId').value = skill.id;
    document.getElementById('skillName').value = skill.name;
    document.getElementById('skillTargetHours').value = skill.target_hours;
    document.getElementById('skillColor').value = skill.color;
    document.getElementById('skillModalTitle').textContent = 'แก้ไขทักษะ';
    skillError('');
    openModal('skillModal');
}

async function saveSkill() {
    const id = document.getElementById('skillId').value;
    const body = {
        name: document.getElementById('skillName').value.trim(),
        target_hours: document.getElementById('skillTargetHours').value,
        color: document.getElementById('skillColor').value,
    };
    const hours = parseInt(body.target_hours, 10);

    if (!body.name) { skillError('ใส่ชื่อทักษะก่อน'); document.getElementById('skillName').focus(); return; }
    if (!(hours >= 1 && hours <= 100000)) { skillError('เป้าหมายต้องอยู่ระหว่าง 1 ถึง 100,000 ชั่วโมง'); return; }

    try {
        await apiFetch(id ? BASE_URL + '/api/skills/' + id : BASE_URL + '/api/skills', { method: id ? 'PUT' : 'POST', body: JSON.stringify(body) });
        closeModal('skillModal');
        await loadData();
        toast('บันทึกทักษะแล้ว');
    } catch (err) {
        skillError(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function deleteSkill(id) {
    if (!await confirmAction('ลบทักษะนี้แล้วกู้คืนไม่ได้ ประวัติการจับเวลาของทักษะนี้จะถูกลบไปด้วย', 'ลบทักษะ', 'ลบทักษะนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/skills/' + id, { method: 'DELETE' });
        await loadData();
        toast('ลบทักษะแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

async function deleteLog(id) {
    if (!await confirmAction('ลบรายการนี้แล้วกู้คืนไม่ได้ เวลารวมจะลดลงตาม', 'ลบรายการ', 'ลบรายการจับเวลานี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/skills/logs/' + id, { method: 'DELETE' });
        await loadData();
        toast('ลบรายการแล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
