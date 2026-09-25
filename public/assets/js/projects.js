/* =====================================================
   projects.js — Projects & Kanban Client Logic
   Clean minimal SPA style interactions
===================================================== */

let allProjects = [];
let activeProjectId = null;
let activeProjectData = null; // เก็บงาน, กิจกรรม, สรุป AI
let currentCalendarDate = new Date();
let projectChart = null; // เก็บอินสแตนซ์ของ Chart.js
let currentChecklist = []; // เก็บเช็คลิสต์ที่เปิดอยู่ใน Modal ชั่วคราว
let chatPollTimer = null; // คุมเวลาแชท Dynamic Polling
let sortableInstances = []; // เก็บอินสแตนซ์บอร์ดคัมบังสำหรับการเปิด/ปิดการดึงลาก

document.addEventListener('DOMContentLoaded', async function () {
    // 1. โหลดข้อมูลโครงการทั้งหมด
    await loadProjects();
    
    // 2. เริ่มต้นระบบลากวางบอร์ดคัมบัง
    initSortable();
    
    // 3. วาดปฏิทินจิ๋ว
    renderCalendar();
});

// =====================================================
// --- โครงการ (Projects CRUD & Interactions) ---
// =====================================================

async function loadProjects() {
    try {
        const data = await apiFetch(BASE_URL + '/api/projects');
        allProjects = data.projects || [];
        renderProjects();
        
        // ถ้าเคยเปิดโปรเจคค้างไว้ หรือมีโปรเจคให้เลือกตัวแรก ให้โหลดขึ้นมาโดยอัตโนมัติ
        if (allProjects.length > 0) {
            let selectId = activeProjectId;
            if (!selectId || !allProjects.some(p => p.id === selectId)) {
                selectId = allProjects[0].id;
            }
            await selectProject(selectId);
        } else {
            // กรณีไม่มีโปรเจคเลย
            activeProjectId = null;
            document.getElementById('kanbanBoardSection').style.display = 'none';
            document.getElementById('aiSummaryCardWrap').style.display = 'none';
            document.getElementById('analyticsWidgetCard').style.display = 'none';
            document.getElementById('activityWidgetCard').style.display = 'none';
            document.getElementById('smartWarningBanner').style.display = 'none';
            document.getElementById('projectsEmptyState').style.display = 'block';
        }
    } catch (err) {
        toast('โหลดข้อมูลโครงการไม่สำเร็จ', 'danger');
    }
}

function renderProjects() {
    const grid = document.getElementById('projectsGrid');
    if (!grid) return;
    
    if (allProjects.length === 0) {
        grid.innerHTML = '';
        return;
    }
    
    document.getElementById('projectsEmptyState').style.display = 'none';
    
    grid.innerHTML = allProjects.map(p => {
        const isActive = p.id === activeProjectId;
        const total = parseInt(p.total_tasks || 0);
        const done = parseInt(p.completed_tasks || 0);
        const percent = total > 0 ? Math.round((done / total) * 100) : 0;
        
        // กำหนดสีของหลอดความก้าวหน้าตามความสำคัญ (Priority)
        let prAccent = '#6b7280'; // Low
        if (p.priority === 'Critical') prAccent = '#ef4444';
        else if (p.priority === 'High') prAccent = '#f97316';
        else if (p.priority === 'Medium') prAccent = '#eab308';
        else if (p.priority === 'Low') prAccent = '#22c55e';

        // ป้าย Priority
        let prLabel = 'Low';
        if (p.priority === 'Critical') prLabel = 'Critical';
        else if (p.priority === 'High') prLabel = 'High';
        else if (p.priority === 'Medium') prLabel = 'Medium';

        // คำนวณเดดไลน์
        let dueHtml = 'ไม่มีกำหนดส่ง';
        if (p.due_date) {
            const days = daysUntil(p.due_date);
            if (days !== null) {
                if (days < 0) {
                    dueHtml = `<span style="color:#ef4444; font-weight:700;">เกินกำหนด ${Math.abs(days)} วัน</span>`;
                } else if (days === 0) {
                    dueHtml = '<span style="color:#f59e0b; font-weight:700;">ส่งวันนี้</span>';
                } else {
                    dueHtml = `เหลืออีก ${days} วัน`;
                }
            }
        }

        // สถานะ
        let statusBadgeCls = 'badge-gray';
        if (p.status === 'In Progress') statusBadgeCls = 'badge-warning';
        else if (p.status === 'Review') statusBadgeCls = 'badge-danger';
        else if (p.status === 'Completed') statusBadgeCls = 'badge-success';

        return `
            <div class="project-card ${isActive ? 'active-project' : ''}" 
                 style="--pc-accent: ${prAccent}" 
                 data-act="selectProject" data-args="[${p.id}]">
                <div class="project-card-header">
                    <span class="project-card-title truncate" title="${escHtml(p.name)}">${escHtml(p.name)}</span>
                    <span class="badge ${statusBadgeCls}" style="font-size:0.7rem; font-weight:600; padding:1px 6px;">${p.status}</span>
                </div>
                <div class="project-card-desc" title="${escHtml(p.description || '')}">${escHtml(p.description) || '<i>ไม่มีคำอธิบายโครงการ</i>'}</div>
                
                <div class="project-progress-wrap">
                    <div class="project-progress-meta">
                        <span>ความคืบหน้า</span>
                        <span>${percent}%</span>
                    </div>
                    <div class="project-progress-track">
                        <div class="project-progress-bar" style="width: ${percent}%; --pc-accent: ${prAccent}"></div>
                    </div>
                </div>

                <div class="project-card-footer">
                    <span class="project-date-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                        ${dueHtml}
                    </span>
                    <div class="flex items-center gap-2">
                        <span class="priority-tag priority-${p.priority.toLowerCase()}" style="font-size: 0.65rem; padding: 1px 6px;">${prLabel}</span>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// คัดกรองตัวเลือก ค้นหา และจัดเรียงโปรเจคย่อย
function filterProjects() {
    const q = document.getElementById('projectSearch').value.toLowerCase();
    const status = document.getElementById('projectStatusFilter').value;
    const priority = document.getElementById('projectPriorityFilter').value;
    const sort = document.getElementById('projectSort').value;
    
    // คัดกรองตามเงื่อนไขที่เลือก
    let filtered = allProjects.filter(p => {
        const matchQ = p.name.toLowerCase().includes(q) || (p.description && p.description.toLowerCase().includes(q));
        const matchStatus = !status || p.status === status;
        const matchPriority = !priority || p.priority === priority;
        return matchQ && matchStatus && matchPriority;
    });

    // จัดเรียง
    if (sort === 'priority') {
        const priorityOrder = { 'Critical': 1, 'High': 2, 'Medium': 3, 'Low': 4 };
        filtered.sort((a, b) => priorityOrder[a.priority] - priorityOrder[b.priority]);
    } else if (sort === 'due_date') {
        filtered.sort((a, b) => {
            if (!a.due_date) return 1;
            if (!b.due_date) return -1;
            return new Date(a.due_date) - new Date(b.due_date);
        });
    } else if (sort === 'name') {
        filtered.sort((a, b) => a.name.localeCompare(b.name, 'th'));
    }

    const grid = document.getElementById('projectsGrid');
    if (filtered.length === 0) {
        grid.innerHTML = `
            <div style="grid-column: 1/-1; text-align: center; padding: 3rem 0; color: var(--color-muted);">
                <p>ไม่พบโปรเจคที่ตรงกับเงื่อนไขการค้นหาของคุณ</p>
            </div>
        `;
        return;
    }

    renderProjectsList(filtered);
}

// ช่วยพิมพ์รายการโปรเจคที่คัดกรองแล้ว
function renderProjectsList(list) {
    const grid = document.getElementById('projectsGrid');
    if (!grid) return;
    
    grid.innerHTML = list.map(p => {
        const isActive = p.id === activeProjectId;
        const total = parseInt(p.total_tasks || 0);
        const done = parseInt(p.completed_tasks || 0);
        const percent = total > 0 ? Math.round((done / total) * 100) : 0;
        
        let prAccent = '#6b7280';
        if (p.priority === 'Critical') prAccent = '#ef4444';
        else if (p.priority === 'High') prAccent = '#f97316';
        else if (p.priority === 'Medium') prAccent = '#eab308';
        else if (p.priority === 'Low') prAccent = '#22c55e';

        let prLabel = 'Low';
        if (p.priority === 'Critical') prLabel = 'Critical';
        else if (p.priority === 'High') prLabel = 'High';
        else if (p.priority === 'Medium') prLabel = 'Medium';

        let dueHtml = 'ไม่มีกำหนดส่ง';
        if (p.due_date) {
            const days = daysUntil(p.due_date);
            if (days !== null) {
                if (days < 0) {
                    dueHtml = `<span style="color:#ef4444; font-weight:700;">เกินกำหนด ${Math.abs(days)} วัน</span>`;
                } else if (days === 0) {
                    dueHtml = '<span style="color:#f59e0b; font-weight:700;">ส่งวันนี้</span>';
                } else {
                    dueHtml = `เหลืออีก ${days} วัน`;
                }
            }
        }

        let statusBadgeCls = 'badge-gray';
        if (p.status === 'In Progress') statusBadgeCls = 'badge-warning';
        else if (p.status === 'Review') statusBadgeCls = 'badge-danger';
        else if (p.status === 'Completed') statusBadgeCls = 'badge-success';

        return `
            <div class="project-card ${isActive ? 'active-project' : ''}" 
                 style="--pc-accent: ${prAccent}" 
                 data-act="selectProject" data-args="[${p.id}]">
                <div class="project-card-header">
                    <span class="project-card-title truncate" title="${escHtml(p.name)}">${escHtml(p.name)}</span>
                    <span class="badge ${statusBadgeCls}" style="font-size:0.7rem; font-weight:600; padding:1px 6px;">${p.status}</span>
                </div>
                <div class="project-card-desc" title="${escHtml(p.description || '')}">${escHtml(p.description) || '<i>ไม่มีคำอธิบายโครงการ</i>'}</div>
                
                <div class="project-progress-wrap">
                    <div class="project-progress-meta">
                        <span>ความคืบหน้า</span>
                        <span>${percent}%</span>
                    </div>
                    <div class="project-progress-track">
                        <div class="project-progress-bar" style="width: ${percent}%; --pc-accent: ${prAccent}"></div>
                    </div>
                </div>

                <div class="project-card-footer">
                    <span class="project-date-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                        ${dueHtml}
                    </span>
                    <div class="flex items-center gap-2">
                        <span class="priority-tag priority-${p.priority.toLowerCase()}" style="font-size: 0.65rem; padding: 1px 6px;">${prLabel}</span>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// เลือกและโหลดโครงการเพื่อดึงมาแสดงผลบอร์ดคัมบัง
async function selectProject(projectId) {
    activeProjectId = projectId;
    
    // เคลียร์ระบบดึงแชทสดเดิมออกก่อนป้องกันชนกัน
    if (chatPollTimer) {
        clearInterval(chatPollTimer);
        chatPollTimer = null;
    }
    
    // ไฮไลท์การ์ดที่กำลังเลือกอยู่แบบ Interactive
    document.querySelectorAll('.project-card').forEach(card => {
        card.classList.remove('active-project');
    });
    
    // โหลดความก้าวหน้าโครงการ, งานคัมบัง และประวัติกิจกรรมจาก API ในคำสั่งเดียว
    try {
        const data = await apiFetch(BASE_URL + '/api/projects/' + projectId + '/tasks');
        activeProjectData = data;
        
        // อัปเดตข้อมูลบนวิดเจ็ต
        document.getElementById('activeProjectTitle').textContent = data.project.name;
        
        // จัดการเปิด/ปิดปุ่มตามสิทธิ์ (Owner / Member)
        const btnInvite = document.getElementById('btnInviteMember');
        const btnEdit = document.getElementById('btnEditProject');
        const btnDelete = document.getElementById('btnDeleteProject');
        
        if (btnInvite) btnInvite.style.display = 'inline-flex'; // ให้ทุกคนดูรายชื่อได้
        if (btnEdit) btnEdit.style.display = data.project.is_owner ? 'inline-flex' : 'none';
        if (btnDelete) btnDelete.style.display = data.project.is_owner ? 'inline-flex' : 'none';
        
        // โหลดข้อมูลจำนวนสมาชิกแบบเบื้องหลัง
        await loadProjectMembers(false);
        
        // แสดงเฟรมบอร์ดและโมดูล
        document.getElementById('kanbanBoardSection').style.display = 'block';
        document.getElementById('aiSummaryCardWrap').style.display = 'block';
        document.getElementById('analyticsWidgetCard').style.display = 'block';
        document.getElementById('activityWidgetCard').style.display = 'block';
        document.getElementById('chatWidgetCard').style.display = 'flex'; // แสดงกล่องแชท
        
        // จัดการหน้าจอเปลี่ยนชื่อสำหรับแขก (Guest Name Edit)
        const guestRenameContainer = document.getElementById('guestRenameContainer');
        const lblGuestName = document.getElementById('lblGuestName');
        if (CURRENT_GUEST_NAME && guestRenameContainer && lblGuestName) {
            guestRenameContainer.style.display = 'inline-block';
            lblGuestName.textContent = `คุณ: ${CURRENT_GUEST_NAME}`;
        }
        
        // เรนเดอร์การ์ดคัมบัง
        renderKanbanCards();
        
        // สั่งสร้างระบบลากวางใหม่ (ซึ่งจะตรวจสอบและบล็อกหากผู้ใช้มีสิทธิ์เป็น Viewer)
        initSortable();
        
        // วาดและอัปเดต Doughnut Chart
        updateAnalyticsCharts();
        
        // แสดงฟีดประวัติกิจกรรม
        renderActivityFeed();
        
        // รายงาน AI Insights
        renderAiInsights();
        
        // อัปเดตไฮไลท์ปฏิทินเดดไลน์
        renderCalendar();
        
        // อัปเดตตัวกรองโปรเจคหลักอีกครั้งเพื่อให้คลาส Active สมบูรณ์
        renderProjects();
        
        // เริ่มระบบดึงแชทสด Dynamic Polling ในโครงการนี้ (ทุกๆ 3 วินาที)
        await fetchChatMessages(); // ดึงรอบแรกทันที
        chatPollTimer = setInterval(fetchChatMessages, 3000);
    } catch (err) {
        toast('ไม่สามารถโหลดข้อมูลของโครงการที่เลือกได้', 'danger');
    }
}

// =====================================================
// --- ปฏิทินจิ๋วแสดงเดดไลน์ (Mini Calendar Widget) ---
// =====================================================

function renderCalendar() {
    const grid = document.getElementById('miniCalendarGrid');
    const title = document.getElementById('calendarMonthTitle');
    if (!grid || !title) return;

    grid.innerHTML = '';
    
    const year = currentCalendarDate.getFullYear();
    const month = currentCalendarDate.getMonth(); // 0-11
    
    const thaiMonths = [
        'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ];
    title.textContent = `${thaiMonths[month]} ${year + 543}`;
    
    // แถบชื่อวันย่อภาษาไทย
    const daysArr = ['อ', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
    daysArr.forEach(d => {
        grid.innerHTML += `<div class="mini-cal-day-label">${d}</div>`;
    });
    
    // คำนวณขอบเขตวันของเดือน
    const firstDayIndex = new Date(year, month, 1).getDay(); // วันแรกเริ่มวันอะไร (0 = อาทิตย์)
    const totalDays = new Date(year, month + 1, 0).getDate(); // มีกี่วันในเดือนนี้
    const prevMonthTotalDays = new Date(year, month, 0).getDate(); // จำนวนวันในเดือนที่แล้ว
    
    // ดึงวันส่งงานของโครงการหลักและงานย่อยทั้งหมดมาเช็ค
    const deadlineDates = {};
    
    // 1. เพิ่มเดดไลน์ของโปรเจคทั้งหมด
    allProjects.forEach(p => {
        if (p.due_date) {
            deadlineDates[p.due_date] = true;
        }
    });

    // 2. เพิ่มเดดไลน์ของงานคัมบังย่อย (ถ้ามี)
    if (activeProjectData && activeProjectData.tasks) {
        activeProjectData.tasks.forEach(t => {
            if (t.due_date) {
                deadlineDates[t.due_date] = true;
            }
        });
    }

    // วาดวันของเดือนก่อนหน้าที่เกินมา
    for (let i = firstDayIndex; i > 0; i--) {
        const day = prevMonthTotalDays - i + 1;
        grid.innerHTML += `<div class="mini-cal-cell other-month">${day}</div>`;
    }
    
    // วาดวันของเดือนนี้หลัก
    const today = new Date();
    for (let i = 1; i <= totalDays; i++) {
        const formattedDate = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
        const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === i;
        const hasDeadline = deadlineDates[formattedDate] === true;
        
        let cellCls = 'mini-cal-cell';
        if (isToday) cellCls += ' cal-today';
        
        const dotHtml = hasDeadline ? `<span class="mini-cal-dot" style="${isToday ? 'background:#fff;' : ''}"></span>` : '';
        
        grid.innerHTML += `
            <div class="${cellCls}" title="${hasDeadline ? 'มีกำหนดส่งงานในวันนี้' : ''}" style="${hasDeadline && !isToday ? 'background:rgba(6,182,212,0.08); color:var(--color-primary); font-weight:700;' : ''}">
                ${i}
                ${dotHtml}
            </div>
        `;
    }
    
    // วันของเดือนถัดไปเพื่อให้ตารางพอดี 42 ช่อง
    const gridCount = firstDayIndex + totalDays;
    const remainingCells = 42 - gridCount;
    for (let i = 1; i <= remainingCells; i++) {
        grid.innerHTML += `<div class="mini-cal-cell other-month">${i}</div>`;
    }
}

function navCalendar(direction) {
    currentCalendarDate.setMonth(currentCalendarDate.getMonth() + direction);
    renderCalendar();
}

// =====================================================
// --- วิเคราะห์ความก้าวหน้าโครงการ (Analytics & AI) ---
// =====================================================

function updateAnalyticsCharts() {
    const tasks = activeProjectData.tasks || [];
    
    const done = tasks.filter(t => t.status === 'Done').length;
    const active = tasks.length - done;
    const percent = tasks.length > 0 ? Math.round((done / tasks.length) * 100) : 0;
    
    // อัปเดตสถิติตัวเลข
    document.getElementById('statCompletedTasks').textContent = done;
    document.getElementById('statRemainingTasks').textContent = active;
    document.getElementById('statProductivity').textContent = `${percent}%`;
    
    // แยกตามสถานะ
    const counts = { 'To Do': 0, 'In Progress': 0, 'Review': 0, 'Done': 0 };
    tasks.forEach(t => counts[t.status]++);
    
    // วาดกราฟ Doughnut Chart
    const canvas = document.getElementById('projectDoughnutChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    // ทำลายกราฟอินสแตนซ์เก่าเพื่อหลีกเลี่ยงการกะพริบซ้อน
    if (projectChart) {
        projectChart.destroy();
    }
    
    // ตรวจสอบความถูกต้องของ Chart.js
    if (typeof Chart === 'undefined') return;
    
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    
    if (tasks.length === 0) {
        // วาดรูปวงแหวนสีเทาโฮลเดอร์สุดสวย
        const placeholderColor = isDark ? '#242426' : '#f1f3f5';
        const placeholderBorder = isDark ? '#1c1c1e' : '#eaeaea';
        projectChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['ยังไม่มีงานย่อยในบอร์ด'],
                datasets: [{
                    data: [1],
                    backgroundColor: [placeholderColor],
                    borderColor: [placeholderBorder],
                    borderWidth: 1.5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 0,
                            font: { size: 10.5, weight: '500' },
                            color: isDark ? '#86868b' : '#86868b'
                        }
                    },
                    tooltip: {
                        enabled: false
                    }
                },
                cutout: '72%'
            }
        });
        return;
    }
    
    projectChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['To Do', 'In Progress', 'Review', 'Done'],
            datasets: [{
                data: [counts['To Do'], counts['In Progress'], counts['Review'], counts['Done']],
                backgroundColor: ['#6b7280', '#3b82f6', '#f59e0b', '#22c55e'],
                borderWidth: 2,
                borderColor: getComputedStyle(document.body).getPropertyValue('--color-surface') || '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 10,
                        font: { size: 10.5 },
                        color: getComputedStyle(document.body).getPropertyValue('--color-text') || '#1d1d1f'
                    }
                }
            },
            cutout: '68%'
        }
    });
}

function renderActivityFeed() {
    const list = document.getElementById('projectActivityList');
    if (!list) return;
    
    const acts = activeProjectData.activities || [];
    if (acts.length === 0) {
        list.innerHTML = `<span class="text-xs text-muted" style="padding-left:12px">ยังไม่มีกิจกรรมโครงการที่บันทึกไว้</span>`;
        return;
    }
    
    list.innerHTML = acts.map(a => {
        return `
            <div class="activity-feed-item">
                <span class="activity-icon-bullet"></span>
                <div class="activity-item-content">
                    <span class="activity-item-text">${escHtml(a.action)}</span>
                    <span class="activity-item-time">${formatDateTime(a.created_at)}</span>
                </div>
            </div>
        `;
    }).join('');
}

function renderAiInsights() {
    const ai = activeProjectData.ai;
    const card = document.getElementById('aiSummaryCardWrap');
    if (!ai || !card) return;
    
    document.getElementById('aiInsightText').textContent = ai.insight;
    
    const warnBox = document.getElementById('aiWarningBox');
    if (ai.warning) {
        warnBox.textContent = ai.warning;
        warnBox.style.display = 'block';
    } else {
        warnBox.style.display = 'none';
    }

    // อัปเดตแถบการเตือนเดดไลน์เร่งด่วนใน Banner ใหญ่ด้านบนด้วย
    const banner = document.getElementById('smartWarningBanner');
    const btext = document.getElementById('smartWarningText');
    if (ai.warning && (ai.status === 'warning' || ai.status === 'danger')) {
        btext.textContent = `ระบบตรวจสอบโครงการพบเหตุเร่งด่วน: ${ai.insight}`;
        banner.style.display = 'flex';
    } else {
        banner.style.display = 'none';
    }
}

// =====================================================
// --- การจัดการ Modals (สร้าง แก้ไข ลบ โปรเจค) ---
// =====================================================

function openCreateProjectModal() {
    document.getElementById('createProjectForm').reset();
    openModal('createProjectModal');
}

async function submitCreateProject(e) {
    e.preventDefault();
    const name = document.getElementById('newProjName').value.trim();
    const desc = document.getElementById('newProjDesc').value.trim();
    const priority = document.getElementById('newProjPriority').value;
    const status = document.getElementById('newProjStatus').value;
    const due = document.getElementById('newProjDue').value;

    if (!name) {
        toast('กรุณากรอกชื่อโปรเจค', 'danger');
        return;
    }

    try {
        const res = await apiFetch(BASE_URL + '/api/projects', {
            method: 'POST',
            body: JSON.stringify({
                name,
                description: desc,
                priority,
                status,
                due_date: due
            })
        });
        
        toast('สร้างโปรเจคเรียบร้อยแล้ว');
        closeModal('createProjectModal');
        
        // เลือกโปรเจคใหม่ที่เพิ่งสร้างขึ้น
        activeProjectId = res.id;
        await loadProjects();
    } catch(err) {
        toast(err.message || 'สร้างโครงการไม่สำเร็จ', 'danger');
    }
}

function openEditProjectModal() {
    if (!activeProjectData || !activeProjectData.project) return;
    const p = activeProjectData.project;
    
    document.getElementById('editProjId').value = p.id;
    document.getElementById('editProjName').value = p.name;
    document.getElementById('editProjDesc').value = p.description || '';
    document.getElementById('editProjPriority').value = p.priority;
    document.getElementById('editProjStatus').value = p.status;
    document.getElementById('editProjDue').value = p.due_date || '';
    
    openModal('editProjectModal');
}

async function submitEditProject(e) {
    e.preventDefault();
    const id = parseInt(document.getElementById('editProjId').value);
    const name = document.getElementById('editProjName').value.trim();
    const desc = document.getElementById('editProjDesc').value.trim();
    const priority = document.getElementById('editProjPriority').value;
    const status = document.getElementById('editProjStatus').value;
    const due = document.getElementById('editProjDue').value;

    if (!name) {
        toast('กรุณากรอกชื่อโครงการ', 'danger');
        return;
    }

    try {
        await apiFetch(BASE_URL + '/api/projects/' + id, {
            method: 'PUT',
            body: JSON.stringify({
                name: name,
                description: desc,
                priority: priority,
                status: status,
                due_date: due
            })
        });
        
        toast('แก้ไขรายละเอียดสำเร็จ');
        closeModal('editProjectModal');
        
        // รีเฟรชทั้งคู่
        await loadProjects();
        await selectProject(activeProjectId);
    } catch (err) {
        toast(err.message || 'บันทึกการแก้ไขไม่สำเร็จ', 'danger');
    }
}

async function deleteActiveProject() {
    if (!activeProjectId) return;
    if (!await confirmAction('การลบโปรเจคหลักจะส่งผลให้งานย่อยในคัมบังบอร์ดและประวัติประเมินผลทั้งหมดถูกลบถาวร ต้องการลบจริงหรือไม่?', 'ยืนยันการลบแบบถาวร', 'ลบโครงการและบอร์ด')) return;
    
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId, {
            method: 'DELETE'
        });
        
        toast('ลบโปรเจคเรียบร้อยแล้ว');
        activeProjectId = null;
        await loadProjects();
    } catch(e) {
        toast('ไม่สามารถลบโปรเจคนี้ได้', 'danger');
    }
}

