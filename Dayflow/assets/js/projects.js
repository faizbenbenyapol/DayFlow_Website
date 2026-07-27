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
                 onclick="selectProject(${p.id})">
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
                 onclick="selectProject(${p.id})">
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
// --- คอนโทรลเลอร์ควบคุมบอร์ดคัมบัง (Kanban Board) ---
// =====================================================

function renderKanbanCards() {
    const columns = ['To Do', 'In Progress', 'Review', 'Done'];
    const ids = {
        'To Do': { list: 'todo-list', count: 'todo-count' },
        'In Progress': { list: 'inprogress-list', count: 'inprogress-count' },
        'Review': { list: 'review-list', count: 'review-count' },
        'Done': { list: 'done-list', count: 'done-count' }
    };
    
    // ตรวจสอบว่าผู้ใช้มีบทบาทเป็น Viewer (เข้าชมเท่านั้น) หรือไม่
    const isViewer = activeProjectData && activeProjectData.project.user_role === 'Viewer';
    
    // ซ่อนหรือแสดงปุ่มเพิ่มงานย่อยด่วน
    document.querySelectorAll('.kanban-quick-add-btn').forEach(btn => {
        btn.style.display = isViewer ? 'none' : 'block';
    });
    
    // เคลียร์รายการเก่าในทุกคอลัมน์ก่อน
    columns.forEach(col => {
        const el = document.getElementById(ids[col].list);
        if (el) el.innerHTML = '';
    });
    
    const tasks = activeProjectData.tasks || [];
    
    // นับจำนวนงานย่อยในแต่ละกลุ่ม
    const counts = { 'To Do': 0, 'In Progress': 0, 'Review': 0, 'Done': 0 };
    
    tasks.forEach(t => {
        counts[t.status]++;
        const listEl = document.getElementById(ids[t.status].list);
        if (listEl) {
            listEl.innerHTML += renderKanbanCardItem(t);
        }
    });
    
    // อัปเดตตัวเลขแสดงผลยอดสะสมหัวคอลัมน์
    columns.forEach(col => {
        const countEl = document.getElementById(ids[col].count);
        if (countEl) countEl.textContent = counts[col];
        
        // กรณีคอลัมน์ว่าง
        const listEl = document.getElementById(ids[col].list);
        if (listEl && counts[col] === 0) {
            if (isViewer) {
                listEl.innerHTML = `
                    <div class="kanban-empty-placeholder" style="cursor: default;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom:6px; opacity:0.6;"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="16"/><line x1="8" x2="16" y1="12" y2="12"/></svg>
                        <span>ไม่มีงานในคอลัมน์นี้</span>
                    </div>
                `;
            } else {
                listEl.innerHTML = `
                    <div class="kanban-empty-placeholder" onclick="toggleQuickAddForm('${col}')" title="คลิกเพื่อเพิ่มงานย่อยในคอลัมน์นี้">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom:6px; opacity:0.6;"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="16"/><line x1="8" x2="16" y1="12" y2="12"/></svg>
                        <span>ไม่มีงานในคอลัมน์นี้</span>
                        <span style="font-size:0.68rem; opacity:0.6; margin-top:2px;">คลิกเพื่อเพิ่มงานด่วน</span>
                    </div>
                `;
            }
        }
    });
}

function renderKanbanCardItem(task) {
    const days = daysUntil(task.due_date);
    const isViewer = activeProjectData && activeProjectData.project.user_role === 'Viewer';
    
    // สีของป้ายกำกับ Priority
    let prLabel = 'Low';
    if (task.priority === 'Critical') prLabel = 'Critical';
    else if (task.priority === 'High') prLabel = 'High';
    else if (task.priority === 'Medium') prLabel = 'Medium';

    // การคำนวณวันหมดอายุ/เดดไลน์
    let dueHtml = '';
    if (task.due_date) {
        let cls = 'text-xs text-muted';
        let label = formatDate(task.due_date);
        if (days !== null) {
            if (days < 0) {
                cls = 'text-xs font-semibold';
                label = `เกินกำหนด ${Math.abs(days)} วัน`;
            } else if (days === 0) {
                cls = 'text-xs font-semibold';
                label = 'ครบวันนี้';
            }
        }
        dueHtml = `<span class="${cls}" style="${days !== null && days <= 0 ? 'color:#ef4444;' : ''}">${label}</span>`;
    }

    // แสดงสัญลักษณ์เช็คลิสต์ย่อยสะสม
    let checklistHtml = '';
    if (task.checklist) {
        try {
            const list = JSON.parse(task.checklist) || [];
            if (list.length > 0) {
                const total = list.length;
                const checked = list.filter(item => item.done).length;
                const isAllDone = total === checked;
                checklistHtml = `
                    <div class="card-checklist-badge ${isAllDone ? 'all-done' : ''}" title="เช็คลิสต์ย่อยเสร็จสิ้น">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        <span>${checked}/${total}</span>
                    </div>
                `;
            }
        } catch(e) {}
    }

    // สีลำดับความสำคัญตรงด้านซ้ายของการ์ด
    let dotColor = '#9ca3af';
    if (task.priority === 'Critical') dotColor = '#ef4444';
    else if (task.priority === 'High') dotColor = '#f97316';
    else if (task.priority === 'Medium') dotColor = '#eab308';
    else if (task.priority === 'Low') dotColor = '#22c55e';

    // หมวดหมู่แท็กย่อย
    let tagHtml = '';
    if (task.category) {
        tagHtml = `<span class="badge badge-gray" style="font-size:0.65rem; border-color:var(--color-border); padding:1px 5px;">${escHtml(task.category)}</span>`;
    }

    // จำลองอะวาตาร์ผู้เกี่ยวข้อง
    let avatarHtml = '';
    if (task.assignee) {
        let avatarBg = '#9ca3af';
        const init = task.assignee.substring(0, 1).toUpperCase();
        if (task.assignee === 'Alex') avatarBg = '#3b82f6';
        else if (task.assignee === 'Jordan') avatarBg = '#8b5cf6';
        else if (task.assignee === 'Taylor') avatarBg = '#ec4899';
        else if (task.assignee === 'Me') avatarBg = '#10b981';

        avatarHtml = `<div class="avatar-member" style="--avatar-bg: ${avatarBg};" title="ผู้ทำงาน: ${task.assignee}">${init}</div>`;
    }

    // ปุ่มแก้ไขสำหรับสิทธิ์ทั่วไป (ซ่อนเมื่อเป็น Viewer)
    const cardActionsHtml = isViewer ? '' : `
        <div style="position: absolute; right: 10px; top: 10px; display: flex; gap: 4px;">
            <button onclick="openEditTask(${task.id})" style="background:none; border:none; cursor:pointer; color:var(--color-muted-2); font-size:0.8rem;" title="แก้ไขงาน">&#9998;</button>
            <button onclick="deleteTask(${task.id})" style="background:none; border:none; cursor:pointer; color:var(--color-danger); font-size:0.8rem;" title="ลบงาน">&times;</button>
        </div>
    `;

    return `
        <div class="kanban-card" data-id="${task.id}" style="border-left: 3.5px solid ${dotColor};">
            ${cardActionsHtml}
            <div class="kanban-card-title font-medium" ${isViewer ? '' : `onclick="openEditTask(${task.id})"`} style="padding-right: 28px;">${escHtml(task.title)}</div>
            <div class="kanban-card-meta">
                <div class="kanban-card-left">
                    <span class="priority-tag priority-${task.priority.toLowerCase()}" style="font-size:0.62rem; padding: 0px 5px;">${prLabel}</span>
                    ${tagHtml}
                    ${checklistHtml}
                </div>
                <div class="flex items-center gap-2">
                    ${dueHtml}
                    ${avatarHtml}
                </div>
            </div>
        </div>
    `;
}

// เริ่มต้นระบบ Drag and Drop บนบอร์ดคัมบัง
function initSortable() {
    if (typeof Sortable === 'undefined') return;
    
    // ทำลายอินสแตนซ์เก่าก่อนป้องกันพฤติกรรมผิดเพี้ยนในการโหลดข้ามโปรเจค
    sortableInstances.forEach(inst => {
        try { inst.destroy(); } catch(e) {}
    });
    sortableInstances = [];
    
    // หากมีบทบาทเป็น Viewer จะไม่มีสิทธิ์ในการลากวางจัดบอร์ดใดๆ ทั้งสิ้น
    if (activeProjectData && activeProjectData.project.user_role === 'Viewer') {
        return;
    }
    
    const lists = ['todo-list', 'inprogress-list', 'review-list', 'done-list'];
    
    lists.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        
        const inst = Sortable.create(el, {
            group: 'kanban-tasks',
            animation: 160,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            handle: '.kanban-card-title', // ดึงลากผ่านหัวชื่องาน
            delay: 120, // Smooth touch delay to avoid scroll locking
            delayOnTouchOnly: true, // Maintain instant dragging on desktop
            touchStartThreshold: 7, // Tolerates tiny finger tremors before starting drag
            onEnd: async function (evt) {
                // เก็บอาเรย์เรียงลำดับใหม่ทั้งหมด
                const items = [];
                const columns = [
                    { id: 'todo-list', status: 'To Do' },
                    { id: 'inprogress-list', status: 'In Progress' },
                    { id: 'review-list', status: 'Review' },
                    { id: 'done-list', status: 'Done' }
                ];
                
                columns.forEach(col => {
                    const listEl = document.getElementById(col.id);
                    if (!listEl) return;
                    
                    listEl.querySelectorAll('.kanban-card[data-id]').forEach((card, idx) => {
                        items.push({
                            id: parseInt(card.dataset.id),
                            status: col.status,
                            position: idx
                        });
                    });
                });
                
                // ยิง API บันทึกตำแหน่งและคอลัมน์ใหม่
                try {
                    await apiFetch(BASE_URL + '/api/projects/tasks/reorder', {
                        method: 'POST',
                        body: JSON.stringify({
                            project_id: activeProjectId,
                            items: items
                        })
                    });
                    
                    // โหลดข้อมูลเฉพาะในโครงการใหม่เพื่อคำนวณสถิติ
                    await selectProject(activeProjectId);
                    
                    // โหลดสถิติโปรเจคทั้งหมดด้านซ้ายใหม่ด้วย
                    const data = await apiFetch(BASE_URL + '/api/projects');
                    allProjects = data.projects || [];
                    renderProjects();
                } catch(e) {
                    toast('บันทึกการจัดบอร์ดคัมบังล้มเหลว', 'danger');
                }
            }
        });
        
        sortableInstances.push(inst);
    });
}

// ควบคุมการแสดงผลฟอร์มเพิ่มงานด่วน (Quick Add Inline)
function toggleQuickAddForm(status, show = true) {
    const idMap = {
        'To Do': 'todo',
        'In Progress': 'inprogress',
        'Review': 'review',
        'Done': 'done'
    };
    const s = idMap[status];
    const form = document.getElementById(`quickadd-${s}-form`);
    const input = document.getElementById(`quickadd-${s}-input`);
    
    if (show) {
        // ปิดฟอร์มอื่นๆ ก่อน
        Object.values(idMap).forEach(key => {
            document.getElementById(`quickadd-${key}-form`).style.display = 'none';
        });
        
        form.style.display = 'block';
        input.value = '';
        input.focus();
    } else {
        form.style.display = 'none';
    }
}

function handleQuickAddKey(e, status) {
    if (e.key === 'Enter') {
        submitQuickAdd(status);
    } else if (e.key === 'Escape') {
        toggleQuickAddForm(status, false);
    }
}

async function submitQuickAdd(status) {
    const idMap = {
        'To Do': 'todo',
        'In Progress': 'inprogress',
        'Review': 'review',
        'Done': 'done'
    };
    const s = idMap[status];
    const input = document.getElementById(`quickadd-${s}-input`);
    const title = input.value.trim();
    
    if (!title) {
        toast('กรุณาระบุชื่องานย่อย', 'danger');
        return;
    }
    
    try {
        await apiFetch(BASE_URL + `/api/projects/${activeProjectId}/tasks`, {
            method: 'POST',
            body: JSON.stringify({
                title: title,
                status: status,
                priority: 'Medium'
            })
        });
        
        toast('เพิ่มงานย่อยเรียบร้อยแล้ว');
        toggleQuickAddForm(status, false);
        
        // รีเฟรชเฉพาะงานในคัมบังบอร์ด AI
        await selectProject(activeProjectId);
        
        // อัปเดตสถิติโปรเจคทั้งหมดด้านซ้าย
        const data = await apiFetch(BASE_URL + '/api/projects');
        allProjects = data.projects || [];
        renderProjects();
    } catch(err) {
        toast(err.message || 'ไม่สามารถเพิ่มงานได้', 'danger');
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

// =====================================================
// --- ระบบจัดการงานและเช็คลิสต์ย่อยภายในงาน (Tasks & Checklist) ---
// =====================================================

function openEditTask(id) {
    const tasks = activeProjectData.tasks || [];
    const task = tasks.find(t => t.id === id);
    if (!task) return;

    document.getElementById('editTaskId').value = task.id;
    document.getElementById('editTaskTitle').value = task.title;
    document.getElementById('editTaskStatus').value = task.status;
    document.getElementById('editTaskPriority').value = task.priority;
    document.getElementById('editTaskCategory').value = task.category || '';
    document.getElementById('editTaskAssignee').value = task.assignee || '';
    document.getElementById('editTaskDue').value = task.due_date || '';
    
    // โหลดระบบเช็คลิสต์ย่อย
    currentChecklist = [];
    if (task.checklist) {
        try {
            currentChecklist = JSON.parse(task.checklist) || [];
        } catch(e) {}
    }
    
    renderChecklist();
    openModal('editTaskModal');
}

function renderChecklist() {
    const listEl = document.getElementById('modalChecklistList');
    const label = document.getElementById('checklistPercentageLabel');
    const fill = document.getElementById('checklistProgressFill');
    if (!listEl || !label || !fill) return;

    listEl.innerHTML = '';
    
    if (currentChecklist.length === 0) {
        listEl.innerHTML = `<p class="text-xs text-muted" style="text-align:center; padding:10px 0;">ยังไม่มีรายการเช็คลิสต์ย่อยในงานย่อยชิ้นนี้</p>`;
        label.textContent = '0% เสร็จสิ้น';
        fill.style.width = '0%';
        return;
    }

    const total = currentChecklist.length;
    const done = currentChecklist.filter(item => item.done).length;
    const percent = Math.round((done / total) * 100);

    label.textContent = `${percent}% เสร็จสิ้น (${done}/${total})`;
    fill.style.width = `${percent}%`;

    listEl.innerHTML = currentChecklist.map((item, idx) => {
        return `
            <div class="checklist-item">
                <div class="checklist-item-left">
                    <input type="checkbox" ${item.done ? 'checked' : ''} onchange="toggleChecklistItem(${idx}, this.checked)" style="width:15px; height:15px; cursor:pointer;">
                    <input type="text" class="checklist-item-input ${item.done ? 'line-through' : ''}" value="${escHtml(item.text)}" onchange="updateChecklistItemText(${idx}, this.value)">
                </div>
                <button type="button" class="checklist-btn-del" onclick="deleteChecklistItem(${idx})">&times;</button>
            </div>
        `;
    }).join('');
}

function toggleChecklistItem(index, checked) {
    if (currentChecklist[index]) {
        currentChecklist[index].done = checked;
        renderChecklist();
        saveChecklistQuickly();
    }
}

function updateChecklistItemText(index, value) {
    if (currentChecklist[index]) {
        currentChecklist[index].text = value.trim();
        renderChecklist();
        saveChecklistQuickly();
    }
}

function addChecklistItem() {
    const input = document.getElementById('newChecklistItemInput');
    const text = input.value.trim();
    if (!text) {
        toast('กรุณาระบุข้อความสำหรับเช็คลิสต์', 'danger');
        return;
    }

    currentChecklist.push({ text: text, done: false });
    input.value = '';
    
    renderChecklist();
    saveChecklistQuickly();
}

function deleteChecklistItem(index) {
    currentChecklist.splice(index, 1);
    renderChecklist();
    saveChecklistQuickly();
}

// ช่วยส่งบันทึกสถานะเช็คลิสต์แบบสดไปยังระบบ API เพื่อไม่ให้ข้อมูลหายขณะแก้ไข
async function saveChecklistQuickly() {
    const id = parseInt(document.getElementById('editTaskId').value);
    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + id, {
            method: 'PUT',
            body: JSON.stringify({ checklist: currentChecklist })
        });
        
        // โหลดข้อมูลเฉพาะในโครงการใหม่เบื้องหลัง
        const data = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/tasks');
        activeProjectData = data;
        
        // อัปเดตเฉพาะคัมบังบอร์ด และ อัปเดตสเกลความสำเร็จ
        renderKanbanCards();
        updateAnalyticsCharts();
        renderAiInsights();
    } catch(e) {
        toast('ไม่สามารถบันทึกสถานะเช็คลิสต์ได้', 'danger');
    }
}

async function submitEditTask(e) {
    e.preventDefault();
    const id = parseInt(document.getElementById('editTaskId').value);
    const title = document.getElementById('editTaskTitle').value.trim();
    const status = document.getElementById('editTaskStatus').value;
    const priority = document.getElementById('editTaskPriority').value;
    const category = document.getElementById('editTaskCategory').value.trim();
    const assignee = document.getElementById('editTaskAssignee').value;
    const due = document.getElementById('editTaskDue').value;

    if (!title) {
        toast('กรุณาระบุชื่องานย่อย', 'danger');
        return;
    }

    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + id, {
            method: 'PUT',
            body: JSON.stringify({
                title: title,
                status: status,
                priority: priority,
                category: category,
                assignee: assignee,
                due_date: due,
                checklist: currentChecklist
            })
        });

        toast('บันทึกรายละเอียดงานเสร็จสิ้น');
        closeModal('editTaskModal');
        
        // รีเฟรชสถิติของโครงการ
        await selectProject(activeProjectId);
        
        // อัปเดตสถิติโปรเจคทั้งหมดด้านซ้าย
        const data = await apiFetch(BASE_URL + '/api/projects');
        allProjects = data.projects || [];
        renderProjects();
    } catch(err) {
        toast(err.message || 'บันทึกการแก้ไขไม่สำเร็จ', 'danger');
    }
}

async function deleteTask(id) {
    if (!await confirmAction('ต้องการลบงานย่อยชิ้นนี้จากบอร์ดคัมบังถาวรหรือไม่?', 'ลบงานย่อย')) return;
    
    try {
        await apiFetch(BASE_URL + '/api/projects/tasks/' + id, {
            method: 'DELETE'
        });
        
        toast('ลบงานย่อยเรียบร้อยแล้ว');
        
        // รีเซ็ตรีโหลดสถิติ
        await selectProject(activeProjectId);
        
        // อัปเดตสถิติโปรเจคทั้งหมดด้านซ้าย
        const data = await apiFetch(BASE_URL + '/api/projects');
        allProjects = data.projects || [];
        renderProjects();
    } catch(e) {
        toast('ไม่สามารถลบงานนี้ได้', 'danger');
    }
}

// =====================================================
// --- ฟังก์ชันช่วยเหลือด้านความปลอดภัยและแปลงตัวอักษร ---
// =====================================================

function escHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// =====================================================
// --- ระบบแชทสนทนาและการทำงานร่วมกัน (Collaboration & Chat) ---
// =====================================================

// ดึงประวัติและข้อความแชทใหม่ของโครงการ
async function fetchChatMessages() {
    if (!activeProjectId) return;
    try {
        const chatStatus = document.getElementById('chatStatusText');
        if (chatStatus) chatStatus.textContent = 'เรียลไทม์';
        
        const data = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/chat');
        const list = document.getElementById('chatMessagesList');
        if (!list) return;
        
        const currentUserId = typeof CURRENT_USER_ID !== 'undefined' ? parseInt(CURRENT_USER_ID) : 0;
        
        // ตรวจสอบว่าแชทถูกเลื่อนไปบนสุดเพื่อเปิดระบบ Auto Scroll หรือไม่
        const isScrolledToBottom = list.scrollHeight - list.clientHeight <= list.scrollTop + 60;
        
        list.innerHTML = (data.messages || []).map(msg => {
            let isOwn = false;
            if (currentUserId > 0) {
                isOwn = currentUserId === parseInt(msg.user_id);
            } else if (CURRENT_GUEST_NAME) {
                isOwn = !msg.user_id && msg.guest_name === CURRENT_GUEST_NAME;
            }
            const senderName = escHtml(msg.display_name || msg.username);
            const dateText = formatDateTime(msg.created_at);
            const msgContent = escHtml(msg.message);
            
            return `
                <div class="chat-msg-item ${isOwn ? 'chat-msg-own' : 'chat-msg-other'}">
                    <div class="chat-msg-meta">
                        <span class="chat-msg-sender">${senderName}</span>
                        <span class="chat-msg-time">${dateText}</span>
                    </div>
                    <div class="chat-msg-bubble">
                        ${msgContent}
                    </div>
                </div>
            `;
        }).join('');
        
        if (list.innerHTML === '') {
            list.innerHTML = `
                <div style="text-align: center; color: var(--color-muted); padding: 2rem 0; font-size: 0.8rem;">
                    <i>ไม่มีข้อความแชทในโครงการนี้ เริ่มคุยกันได้เลย!</i>
                </div>
            `;
        }
        
        // เลื่อนลงล่างสุดถ้าเคยเลื่อนไว้หรือเป็นการดึงครั้งแรก
        if (isScrolledToBottom || list.dataset.firstLoad === undefined) {
            list.scrollTop = list.scrollHeight;
            list.dataset.firstLoad = 'done';
        }
    } catch (err) {
        const chatStatus = document.getElementById('chatStatusText');
        if (chatStatus) chatStatus.textContent = 'ข้อผิดพลาดการเชื่อมต่อ';
    }
}

// ส่งข้อความแชทใหม่ (Optimistic approach)
async function sendChatMessage() {
    if (!activeProjectId) return;
    const input = document.getElementById('chatMessageInput');
    if (!input) return;
    
    const msg = input.value.trim();
    if (msg === '') return;
    
    // เคลียร์ค่าทันทีก่อนยิง API เพื่อความฉับไว
    input.value = '';
    
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/chat', {
            method: 'POST',
            body: JSON.stringify({ message: msg })
        });
        
        // โหลดแชทใหม่ทันที
        await fetchChatMessages();
    } catch(err) {
        toast('ไม่สามารถส่งข้อความได้', 'danger');
        // คืนข้อความหากส่งล้มเหลว
        input.value = msg;
    }
}

// ผูกการกดปุ่ม Enter สำหรับแชท
function handleChatKeyDown(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        sendChatMessage();
    }
}

// เปิดโมดอลเชิญผู้ร่วมทีมและจัดการสิทธิ์
function openInviteMemberModal() {
    if (!activeProjectId) return;
    loadProjectMembers(true);
}

// โหลดข้อมูลสมาชิกจากเซิร์ฟเวอร์
async function loadProjectMembers(shouldOpenModal = true) {
    if (!activeProjectId) return;
    try {
        const data = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/members');
        const list = document.getElementById('projectMembersList');
        if (!list) return;
        
        // อัปเดตตัวเลขจำนวนผู้เข้าร่วมในแถบหัวโครงการ
        const countBadge = document.getElementById('memberCountBadge');
        if (countBadge) {
            countBadge.textContent = data.members.length;
        }
        
        // ถ้าต้องการเปิดแสดงหน้าต่าง Modal ขึ้นมา
        if (shouldOpenModal) {
            const isOwner = activeProjectData && activeProjectData.project.is_owner;
            const currentUserId = typeof CURRENT_USER_ID !== 'undefined' ? parseInt(CURRENT_USER_ID) : 0;
            
            // แสดงหรือซ่อนฟอร์มเชิญตามบทบาทสิทธิ์ (เฉพาะ Owner เท่านั้นที่จะเชิญได้)
            const inviteForm = document.getElementById('inviteMemberForm');
            if (inviteForm) inviteForm.style.display = isOwner ? 'block' : 'none';
            
            // โหลดข้อมูลสวิตช์แชร์สาธารณะ (ถ้าเป็นเจ้าของโครงการ)
            const publicShareSection = document.getElementById('publicShareSection');
            if (publicShareSection) {
                if (isOwner) {
                    publicShareSection.style.display = 'block';
                    loadProjectShareSettings();
                } else {
                    publicShareSection.style.display = 'none';
                }
            }
            
            list.innerHTML = data.members.map(m => {
                const isMe = currentUserId === parseInt(m.id);
                const showDelete = isOwner && !isMe && m.role !== 'Owner';
                
                let roleCls = 'role-editor';
                if (m.role === 'Owner') roleCls = 'role-owner';
                else if (m.role === 'Viewer') roleCls = 'role-viewer';
                
                const init = escHtml(m.display_name || m.username).substring(0, 1).toUpperCase();
                
                return `
                    <div class="member-item">
                        <div class="member-info">
                            <div class="member-avatar">${init}</div>
                            <div class="member-details">
                                <span class="member-name">${escHtml(m.display_name || m.username)} ${isMe ? ' (คุณ)' : ''}</span>
                                <span class="member-email">${escHtml(m.email)}</span>
                            </div>
                        </div>
                        <div class="member-actions">
                            <span class="member-role-badge ${roleCls}">${m.role}</span>
                            ${showDelete ? `
                                <button type="button" class="btn-remove-member" onclick="removeMember(${m.id})" title="ลบสมาชิกออกจากกลุ่ม">&#215;</button>
                            ` : ''}
                            ${!isOwner && isMe && m.role !== 'Owner' ? `
                                <button type="button" class="btn btn-danger btn-xs" onclick="removeMember(${m.id})" style="padding:2px 8px; font-size:0.7rem; border-radius:var(--radius-md);">ออกจากโครงการ</button>
                            ` : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            openModal('inviteMemberModal');
        }
    } catch(err) {
        toast('ไม่สามารถเรียกดูรายชื่อสมาชิกได้', 'danger');
    }
}

// ยื่นคำร้องขอเชิญผู้ร่วมงานใหม่
async function submitInviteMember(event) {
    event.preventDefault();
    if (!activeProjectId) return;
    
    const input = document.getElementById('inviteSearchInput');
    const roleSelect = document.getElementById('inviteRoleSelect');
    if (!input || !roleSelect) return;
    
    const target = input.value.trim();
    const role = roleSelect.value;
    
    if (target === '') return;
    
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/members', {
            method: 'POST',
            body: JSON.stringify({
                email_or_username: target,
                role: role
            })
        });
        
        toast('เชิญผู้ร่วมทีมเรียบร้อยแล้ว');
        input.value = '';
        
        // โหลดข้อมูลสมาชิกใหม่เพื่ออัปเดต UI
        await loadProjectMembers(true);
        await selectProject(activeProjectId);
    } catch(err) {
        toast(err.message || 'เชิญสมาชิกไม่สำเร็จ', 'danger');
    }
}

// นำสมาชิกออกหรือออกจากโครงการร่วมงาน
async function removeMember(userId) {
    if (!activeProjectId) return;
    
    const currentUserId = typeof CURRENT_USER_ID !== 'undefined' ? parseInt(CURRENT_USER_ID) : 0;
    const isMe = currentUserId === userId;
    const confirmMsg = isMe 
        ? 'คุณต้องการออกจากกลุ่มโครงการร่วมกันนี้ใช่หรือไม่?' 
        : 'คุณต้องการนำสมาชิกคนนี้ออกจากกลุ่มโครงการหรือไม่?';
    const confirmBtn = isMe ? 'ออกจากโครงการ' : 'ลบสมาชิก';
    
    if (!await confirmAction(confirmMsg, confirmBtn)) return;
    
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/members/' + userId, {
            method: 'DELETE'
        });
        
        toast(isMe ? 'คุณได้ออกจากโครงการแล้ว' : 'นำสมาชิกออกเสร็จสิ้น');
        
        if (isMe) {
            closeModal('inviteMemberModal');
            await loadProjects();
        } else {
            await loadProjectMembers(true);
            await selectProject(activeProjectId);
        }
    } catch(err) {
        toast(err.message || 'ดำเนินการไม่สำเร็จ', 'danger');
    }
}

// ฟังก์ชันแปลงเวลาแชทสดให้กระชับ
function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(/-/g, '/'));
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');
    
    const today = new Date();
    if (d.toDateString() === today.toDateString()) {
        return `วันนี้ ${hours}:${minutes} น.`;
    }
    
    const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return `${d.getDate()} ${months[d.getMonth()]} ${hours}:${minutes} น.`;
}

// =====================================================
// --- ระบบลิงก์แชร์โครงการสาธารณะและ Guest ---
// =====================================================

async function loadProjectShareSettings() {
    if (!activeProjectData || !activeProjectData.project) return;
    const p = activeProjectData.project;
    const toggle = document.getElementById('shareLinkToggle');
    const details = document.getElementById('shareLinkDetails');
    const roleSelect = document.getElementById('shareLinkRole');
    const urlInput = document.getElementById('shareLinkUrl');

    if (!toggle || !details || !roleSelect || !urlInput) return;

    if (p.share_token) {
        toggle.checked = true;
        details.style.display = 'block';
        roleSelect.value = p.share_role || 'Viewer';
        urlInput.value = `${BASE_URL}/project/shared/${p.share_token}`;
    } else {
        toggle.checked = false;
        details.style.display = 'none';
        roleSelect.value = 'Viewer';
        urlInput.value = '';
    }
}

async function togglePublicShare() {
    if (!activeProjectId) return;
    const toggle = document.getElementById('shareLinkToggle');
    const roleSelect = document.getElementById('shareLinkRole');
    
    if (!toggle || !roleSelect) return;
    
    try {
        if (toggle.checked) {
            const role = roleSelect.value;
            const res = await apiFetch(`${BASE_URL}/api/projects/${activeProjectId}/share`, {
                method: 'POST',
                body: JSON.stringify({ share_role: role })
            });
            
            // อัปเดตข้อมูลในสคริปต์
            activeProjectData.project.share_token = res.share_token;
            activeProjectData.project.share_role = res.share_role;
            toast('เปิดใช้งานลิงก์สาธารณะสำเร็จ');
        } else {
            await apiFetch(`${BASE_URL}/api/projects/${activeProjectId}/share`, {
                method: 'DELETE'
            });
            activeProjectData.project.share_token = null;
            activeProjectData.project.share_role = 'Viewer';
            toast('ปิดใช้งานลิงก์สาธารณะแล้ว');
        }
        await loadProjectShareSettings();
    } catch (err) {
        toggle.checked = !toggle.checked; // คืนค่ากลับ
        toast(err.message || 'ดำเนินการไม่สำเร็จ', 'danger');
    }
}

async function updateShareRole() {
    if (!activeProjectId) return;
    const roleSelect = document.getElementById('shareLinkRole');
    if (!roleSelect) return;
    const role = roleSelect.value;
    
    try {
        const res = await apiFetch(`${BASE_URL}/api/projects/${activeProjectId}/share`, {
            method: 'POST',
            body: JSON.stringify({ share_role: role })
        });
        activeProjectData.project.share_token = res.share_token;
        activeProjectData.project.share_role = res.share_role;
        toast('อัปเดตสิทธิ์ของลิงก์แชร์สำเร็จ');
        await loadProjectShareSettings();
    } catch (err) {
        toast(err.message || 'อัปเดตสิทธิ์ไม่สำเร็จ', 'danger');
    }
}

function copyShareUrl() {
    const urlInput = document.getElementById('shareLinkUrl');
    if (!urlInput || !urlInput.value) return;
    
    urlInput.select();
    urlInput.setSelectionRange(0, 99999); // สำหรับมือถือ
    
    navigator.clipboard.writeText(urlInput.value)
        .then(() => {
            toast('คัดลอกลิงก์แชร์ไปยังคลิปบอร์ดแล้ว');
        })
        .catch(() => {
            toast('คัดลอกลิงก์ไม่สำเร็จ กรุณาคัดลอกด้วยตนเอง', 'danger');
        });
}

async function changeGuestName() {
    const { value: newName } = await Swal.fire({
        title: 'แก้ไขชื่อของคุณ',
        input: 'text',
        inputLabel: 'ชื่อเล่นหรือชื่อเรียกสำหรับการแสดงผลร่วมทีม',
        inputValue: CURRENT_GUEST_NAME ? CURRENT_GUEST_NAME.replace(' (ผู้เยี่ยมชม)', '') : '',
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#06b6d4',
        cancelButtonColor: '#6b7280',
        inputValidator: (value) => {
            if (!value.trim()) {
                return 'กรุณากรอกชื่อของคุณ!';
            }
        }
    });

    if (newName) {
        try {
            await apiFetch(`${BASE_URL}/api/projects/guest-name`, {
                method: 'POST',
                body: JSON.stringify({ name: newName })
            });
            window.location.reload(); // รีโหลดเพื่อให้เซสชันและแชทสดอัปเดตชื่อผู้เยี่ยมชม
        } catch (err) {
            toast(err.message || 'เปลี่ยนชื่อไม่สำเร็จ', 'danger');
        }
    }
}

