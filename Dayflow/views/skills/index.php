<div class="skills-container">
    <div class="page-header flex items-center justify-between mb-6">
        <div>
            <h1 class="page-title">ระบบติดตามเวลา (Time Tracker)</h1>
            <p class="page-subtitle">จับเวลาและติดตามการเรียนรู้ตามกฎ 10,000 ชั่วโมง</p>
        </div>
        <button class="btn btn-primary" onclick="openSkillModal()">+ เพิ่มทักษะเป้าหมาย</button>
    </div>

    <!-- Timer Widget (Toggl Style) -->
    <div class="timer-widget">
        <div class="timer-input-group">
            <select id="timerSkillSelect" class="form-control">
                <option value="">-- เลือกทักษะเพื่อจับเวลา --</option>
            </select>
            <input type="text" id="timerNotesInput" class="form-control" placeholder="คุณกำลังทำอะไรอยู่? (บันทึกย่อ)">
        </div>
        <div class="timer-display-wrapper" style="display:flex; align-items:center; gap: 1rem;">
            <div class="timer-display" id="timerDisplay">00:00:00</div>
            <button class="btn-timer" id="btnTimerToggle" onclick="toggleTimer()">
                ▶
            </button>
        </div>
    </div>

    <!-- Stats summary -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">วันนี้</div>
            <div class="stat-value" id="statToday">0h 0m</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">สัปดาห์นี้</div>
            <div class="stat-value" id="statWeek">0h 0m</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">เดือนนี้</div>
            <div class="stat-value" id="statMonth">0h 0m</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">รวมทั้งหมด</div>
            <div class="stat-value" id="statTotal">0h 0m</div>
        </div>
    </div>

    <div class="skills-grid">
        <!-- Left: Logs -->
        <div class="logs-section">
            <div class="section-title">ประวัติการจับเวลา</div>
            <div class="card">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>ทักษะ</th>
                            <th>บันทึกย่อ</th>
                            <th>ช่วงเวลา</th>
                            <th>ระยะเวลา</th>
                            <th width="50"></th>
                        </tr>
                    </thead>
                    <tbody id="logsTableBody">
                        <tr><td colspan="5" class="text-center">กำลังโหลด...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Goals/Skills -->
        <div class="goals-section">
            <div class="section-title">เป้าหมาย (10,000 Hours)</div>
            <div class="card" id="skillsListContainer">
                <div class="text-center p-3">กำลังโหลด...</div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Skill Modal -->
<div class="modal-backdrop" id="skillModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="skillModalTitle">เพิ่มทักษะ / เป้าหมายใหม่</span>
            <button class="modal-close" type="button" onclick="closeSkillModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="skillForm" onsubmit="saveSkill(event)">
                <input type="hidden" id="skillId">
                <div class="form-group">
                    <label class="form-label">ชื่อทักษะ (เช่น เขียนโปรแกรม, กีต้าร์)</label>
                    <input type="text" id="skillName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">เป้าหมาย (ชั่วโมง)</label>
                    <input type="number" id="skillTargetHours" class="form-control" value="10000" required>
                    <div class="form-hint mt-1">ค่าเริ่มต้นคือ 10,000 ชั่วโมงตามกฎการฝึกฝน</div>
                </div>
                <div class="form-group">
                    <label class="form-label">สีประจำทักษะ</label>
                    <input type="color" id="skillColor" class="form-control" value="#3b82f6" style="height:40px; padding:2px;">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeSkillModal()">ยกเลิก</button>
            <button type="submit" form="skillForm" class="btn btn-primary">บันทึก</button>
        </div>
    </div>
</div>

<script>
let skills = [];
let activeTimer = null;
let timerInterval = null;
let timerStartTime = null;

// Format seconds to H:i:s
function formatTime(totalSeconds) {
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = Math.floor(totalSeconds % 60);
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function formatDurationHm(totalSeconds) {
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    return `${h}h ${m}m`;
}

async function loadData() {
    try {
        // Load Skills
        const resSkills = await fetch('<?= APP_URL ?>/api/skills');
        skills = await resSkills.json();
        
        // Populate Select
        const select = document.getElementById('timerSkillSelect');
        select.innerHTML = '<option value="">-- เลือกทักษะเพื่อจับเวลา --</option>';
        skills.forEach(s => {
            select.innerHTML += `<option value="${s.id}">${s.name}</option>`;
        });

        // Load Stats & Active Timer
        const resStats = await fetch('<?= APP_URL ?>/api/skills/stats');
        const stats = await resStats.json();
        
        document.getElementById('statToday').innerText = formatDurationHm(stats.today_seconds);
        document.getElementById('statWeek').innerText = formatDurationHm(stats.week_seconds);
        document.getElementById('statMonth').innerText = formatDurationHm(stats.month_seconds);
        document.getElementById('statTotal').innerText = formatDurationHm(stats.total_seconds);

        renderSkillsProgress(stats.skills_progress || []);
        
        if (stats.active_timer) {
            setActiveTimer(stats.active_timer);
        } else {
            clearActiveTimer();
        }

        // Load Logs
        loadLogs();

    } catch (e) {
        console.error(e);
        showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
    }
}

function renderSkillsProgress(skillsProgressData) {
    const container = document.getElementById('skillsListContainer');
    container.innerHTML = '';
    
    if (skills.length === 0) {
        container.innerHTML = '<div class="text-center p-3 text-muted">ยังไม่มีทักษะเป้าหมาย</div>';
        return;
    }

    const progressMap = {};
    skillsProgressData.forEach(p => {
        progressMap[p.skill_id] = parseInt(p.total_seconds);
    });

    skills.forEach(s => {
        const totalSec = progressMap[s.id] || 0;
        const totalHours = totalSec / 3600;
        const targetHours = parseInt(s.target_hours) || 10000;
        let pcent = (totalHours / targetHours) * 100;
        if (pcent > 100) pcent = 100;

        container.innerHTML += `
            <div class="skill-item">
                <div class="skill-header">
                    <span class="skill-name">
                        <span class="color-dot" style="background:${s.color}"></span>
                        ${s.name}
                    </span>
                    <div>
                        <button class="btn btn-sm btn-ghost" onclick="editSkill('${s.id}')" title="แก้ไข" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">✎</button>
                        <button class="btn btn-sm btn-ghost text-danger" onclick="deleteSkill('${s.id}')" title="ลบ">&times;</button>
                    </div>
                </div>
                <div class="progress-container">
                    <div class="progress-bar" style="width: ${pcent}%; background: ${s.color}"></div>
                </div>
                <div class="skill-meta">
                    <span>${totalHours.toFixed(1)} ชม.</span>
                    <span>/ ${targetHours.toLocaleString()} ชม. (${pcent.toFixed(2)}%)</span>
                </div>
            </div>
        `;
    });
}

async function loadLogs() {
    const res = await fetch('<?= APP_URL ?>/api/skills/logs');
    const logs = await res.json();
    const tbody = document.getElementById('logsTableBody');
    tbody.innerHTML = '';
    
    if(logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">ยังไม่มีประวัติการจับเวลา</td></tr>';
        return;
    }

    logs.forEach(l => {
        const start = l.start_time.substring(11, 16);
        const end = l.end_time.substring(11, 16);
        const date = l.start_time.substring(0, 10);
        
        tbody.innerHTML += `
            <tr>
                <td>
                    <span class="log-skill-badge" style="background:${l.skill_color}">${l.skill_name}</span>
                </td>
                <td style="color:var(--text-muted)">${l.notes || '-'}</td>
                <td style="font-size:0.85rem">${date}<br/>${start} - ${end}</td>
                <td><strong>${formatDurationHm(l.duration_seconds)}</strong></td>
                <td>
                    <button class="btn btn-sm btn-ghost text-danger" onclick="deleteLog('${l.id}')">&times;</button>
                </td>
            </tr>
        `;
    });
}

function setActiveTimer(timerData) {
    activeTimer = timerData;
    const timeStr = activeTimer.start_time.replace(/-/g, '/'); // fix Safari date parsing
    timerStartTime = new Date(timeStr).getTime();
    document.getElementById('timerSkillSelect').value = activeTimer.skill_id;
    document.getElementById('timerNotesInput').value = activeTimer.notes || '';
    
    const btn = document.getElementById('btnTimerToggle');
    btn.innerHTML = '⏹';
    btn.classList.add('active');
    
    // Start interval
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(updateTimerDisplay, 1000);
    updateTimerDisplay();
}

function clearActiveTimer() {
    activeTimer = null;
    timerStartTime = null;
    if (timerInterval) clearInterval(timerInterval);
    
    document.getElementById('timerSkillSelect').value = '';
    document.getElementById('timerNotesInput').value = '';
    document.getElementById('timerDisplay').innerText = '00:00:00';
    
    const btn = document.getElementById('btnTimerToggle');
    btn.innerHTML = '▶';
    btn.classList.remove('active');
}

function updateTimerDisplay() {
    if (!timerStartTime) return;
    const now = new Date().getTime();
    const diffSeconds = (now - timerStartTime) / 1000;
    document.getElementById('timerDisplay').innerText = formatTime(diffSeconds);
}

async function toggleTimer() {
    if (activeTimer) {
        // Stop Timer
        await fetch('<?= APP_URL ?>/api/skills/timer/stop', { method: 'POST', headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content} });
        clearActiveTimer();
        loadData(); // refresh stats and logs
        showToast('หยุดการจับเวลาและบันทึกเวลาเรียบร้อย', 'success');
    } else {
        // Start Timer
        const skillId = document.getElementById('timerSkillSelect').value;
        const notes = document.getElementById('timerNotesInput').value;
        
        if (!skillId) {
            showToast('กรุณาเลือกทักษะก่อนเริ่มจับเวลา', 'error');
            document.getElementById('timerSkillSelect').focus();
            return;
        }

        const res = await fetch('<?= APP_URL ?>/api/skills/timer/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ skill_id: skillId, notes: notes })
        });
        
        const json = await res.json();
        if (json.success) {
            showToast('เริ่มจับเวลา', 'success');
            loadData(); // reload active timer from db to get correct start_time
        } else {
            showToast(json.error || 'ไม่สามารถเริ่มจับเวลาได้', 'error');
        }
    }
}

// Auto update notes if changed while running
document.getElementById('timerNotesInput').addEventListener('change', async (e) => {
    if (activeTimer) {
        await fetch('<?= APP_URL ?>/api/skills/timer/update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ notes: e.target.value })
        });
    }
});

// Modals
function openSkillModal() {
    document.getElementById('skillForm').reset();
    document.getElementById('skillId').value = '';
    document.getElementById('skillModalTitle').innerText = 'เพิ่มทักษะ / เป้าหมายใหม่';
    document.getElementById('skillModal').classList.add('active');
}
function closeSkillModal() {
    document.getElementById('skillModal').classList.remove('active');
}

function editSkill(id) {
    const skill = skills.find(s => s.id === id);
    if (!skill) return;
    document.getElementById('skillId').value = skill.id;
    document.getElementById('skillName').value = skill.name;
    document.getElementById('skillTargetHours').value = skill.target_hours;
    document.getElementById('skillColor').value = skill.color;
    document.getElementById('skillModalTitle').innerText = 'แก้ไขทักษะ / เป้าหมาย';
    document.getElementById('skillModal').classList.add('active');
}

async function saveSkill(e) {
    e.preventDefault();
    const id = document.getElementById('skillId').value;
    const data = {
        name: document.getElementById('skillName').value,
        target_hours: document.getElementById('skillTargetHours').value,
        color: document.getElementById('skillColor').value
    };

    const method = id ? 'PUT' : 'POST';
    const url = id ? `<?= APP_URL ?>/api/skills/${id}` : `<?= APP_URL ?>/api/skills`;

    try {
        const res = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(data)
        });
        const json = await res.json();
        if (json.success) {
            closeSkillModal();
            loadData();
            showToast('บันทึกทักษะเรียบร้อย', 'success');
        } else {
            showToast(json.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch(e) {
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function deleteSkill(id) {
    const ok = await confirmAction('คุณต้องการลบทักษะนี้ใช่หรือไม่? ประวัติการจับเวลาของทักษะนี้จะถูกลบไปด้วย', 'ลบทักษะ', 'ลบทักษะเป้าหมาย');
    if (!ok) return;
    try {
        const res = await fetch(`<?= APP_URL ?>/api/skills/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        if(res.ok) {
            showToast('ลบเรียบร้อย', 'success');
            loadData();
        }
    } catch(e) { console.error(e); }
}

async function deleteLog(id) {
    const ok = await confirmAction('ต้องการลบประวัติรายการนี้ใช่หรือไม่?', 'ลบประวัติ', 'ลบรายการ');
    if (!ok) return;
    try {
        const res = await fetch(`<?= APP_URL ?>/api/skills/logs/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        if(res.ok) {
            showToast('ลบเรียบร้อย', 'success');
            loadData();
        }
    } catch(e) { console.error(e); }
}

// Initial load
document.addEventListener('DOMContentLoaded', loadData);
</script>
