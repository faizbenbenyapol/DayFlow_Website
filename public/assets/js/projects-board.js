/* =====================================================
   projects-board.js — the kanban board, its tasks and their checklists
   Loaded after projects.js on the projects page; shares its state.
===================================================== */

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
                    <div class="kanban-empty-placeholder" data-act="toggleQuickAddForm" data-args="[&quot;${col}&quot;]" title="คลิกเพื่อเพิ่มงานย่อยในคอลัมน์นี้">
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

        avatarHtml = `<div class="avatar-member" style="--avatar-bg: ${avatarBg};" title="ผู้ทำงาน: ${escHtml(task.assignee)}">${escHtml(init)}</div>`;
    }

    // ปุ่มแก้ไขสำหรับสิทธิ์ทั่วไป (ซ่อนเมื่อเป็น Viewer)
    const cardActionsHtml = isViewer ? '' : `
        <div style="position: absolute; right: 10px; top: 10px; display: flex; gap: 4px;">
            <button data-act="openEditTask" data-args="[${task.id}]" style="background:none; border:none; cursor:pointer; color:var(--color-muted-2); font-size:0.8rem;" title="แก้ไขงาน">&#9998;</button>
            <button data-act="deleteTask" data-args="[${task.id}]" style="background:none; border:none; cursor:pointer; color:var(--color-danger); font-size:0.8rem;" title="ลบงาน">&times;</button>
        </div>
    `;

    return `
        <div class="kanban-card" data-id="${task.id}" style="border-left: 3.5px solid ${dotColor};">
            ${cardActionsHtml}
            <div class="kanban-card-title font-medium" ${isViewer ? '' : `data-act="openEditTask" data-args="[${task.id}]"`} style="padding-right: 28px;">${escHtml(task.title)}</div>
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
                    <input type="checkbox" ${item.done ? 'checked' : ''} data-act="toggleChecklistItem" data-args="[${idx}, &quot;$checked&quot;]" data-on="change" style="width:15px; height:15px; cursor:pointer;">
                    <input type="text" class="checklist-item-input ${item.done ? 'line-through' : ''}" value="${escHtml(item.text)}" data-act="updateChecklistItemText" data-args="[${idx}, &quot;$value&quot;]" data-on="change">
                </div>
                <button type="button" class="checklist-btn-del" data-act="deleteChecklistItem" data-args="[${idx}]">&times;</button>
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

