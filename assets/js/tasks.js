/* =====================================================
   tasks.js — Eisenhower Matrix
===================================================== */

let allTasks = {};

document.addEventListener('DOMContentLoaded', async function () {
    await loadTasks();
    initSortable();
});

async function loadTasks() {
    try {
        const data = await apiFetch(BASE_URL + '/api/tasks');
        allTasks = data.quadrants || {};
        renderAllQuadrants();
    } catch (err) {
        toast('โหลดข้อมูลไม่สำเร็จ', 'danger');
    }
}

function renderAllQuadrants() {
    [1, 2, 3, 4].forEach(q => renderQuadrant(q));
}

function renderQuadrant(q) {
    const list  = document.getElementById('q' + q + '-list');
    const count = document.getElementById('q' + q + '-count');
    if (!list) return;

    const tasks = allTasks[q] || [];
    count.textContent = tasks.length + ' รายการ';

    if (tasks.length === 0) {
        list.innerHTML = '<div class="empty-state" style="padding:1.5rem 0"><span class="text-sm text-muted">ไม่มีงาน</span></div>';
        return;
    }

    list.innerHTML = tasks.map(t => renderTaskItem(t)).join('');
}

function renderTaskItem(task) {
    const isDone = task.status === 'done';
    const days   = daysUntil(task.due_date);

    let dueHtml = '';
    if (task.due_date) {
        let dueCls = 'task-due';
        let dueLabel = formatDate(task.due_date);
        if (days !== null) {
            if (days < 0) { dueCls += ' overdue'; dueLabel += ' (เกินกำหนด)'; }
            else if (days === 0) { dueCls += ' today'; dueLabel += ' (วันนี้)'; }
        }
        dueHtml = '<span class="' + dueCls + '">' + escHtml(dueLabel) + '</span>';
    }

    return '<div class="task-item' + (isDone ? ' done' : '') + '" data-id="' + task.id + '" data-quadrant="' + task.quadrant + '">'
        + '<input type="checkbox" class="task-checkbox" ' + (isDone ? 'checked' : '') + ' onchange="toggleTask(' + task.id + ', this.checked)">'
        + '<div class="task-content">'
        + '<div class="task-title" onclick="openEditTask(' + task.id + ')">' + escHtml(task.title) + '</div>'
        + (dueHtml ? '<div class="task-meta">' + dueHtml + '</div>' : '')
        + '</div>'
        + '<div class="task-actions">'
        + '<button class="task-action-btn" onclick="openEditTask(' + task.id + ')" title="แก้ไข">&#9998;</button>'
        + '<button class="task-action-btn danger" onclick="deleteTask(' + task.id + ')" title="ลบ">&#10005;</button>'
        + '</div>'
        + '</div>';
}

/* --- Sortable (drag & drop between quadrants) --- */
function initSortable() {
    if (typeof Sortable === 'undefined') return;

    [1, 2, 3, 4].forEach(q => {
        const el = document.getElementById('q' + q + '-list');
        if (!el) return;

        Sortable.create(el, {
            group:       'tasks',
            animation:   150,
            ghostClass:  'sortable-ghost',
            dragClass:   'sortable-drag',
            handle:      '.task-content',
            delay:       120, // Smooth touch delay to avoid scroll locking
            delayOnTouchOnly: true,
            touchStartThreshold: 7, // Tolerates tiny finger tremors before starting drag
            onEnd:       handleDragEnd
        });
    });
}

function handleDragEnd(evt) {
    const items = [];

    [1, 2, 3, 4].forEach(q => {
        const list = document.getElementById('q' + q + '-list');
        if (!list) return;

        list.querySelectorAll('.task-item[data-id]').forEach((el, idx) => {
            items.push({
                id:       parseInt(el.dataset.id),
                quadrant: q,
                position: idx
            });
        });
    });

    apiFetch(BASE_URL + '/api/tasks/reorder', {
        method: 'POST',
        body:   JSON.stringify({ items: items })
    }).then(() => loadTasks()).catch(() => toast('บันทึกการเรียงลำดับไม่สำเร็จ', 'danger'));
}

/* --- Toggle done/open --- */
async function toggleTask(id, isDone) {
    try {
        await apiFetch(BASE_URL + '/api/tasks/' + id, {
            method: 'PUT',
            body:   JSON.stringify({ status: isDone ? 'done' : 'open' })
        });
        await loadTasks();
    } catch {
        toast('อัปเดตสถานะไม่สำเร็จ', 'danger');
        await loadTasks(); // restore
    }
}

/* --- Add Task (quick-add inline) --- */
function openAddTask(quadrant) {
    const form  = document.getElementById('q' + quadrant + '-form');
    const input = document.getElementById('q' + quadrant + '-input');
    if (!form || !input) return;

    form.style.display = 'block';
    input.value = '';
    input.focus();

    input.onkeydown = function(e) {
        if (e.key === 'Enter' && input.value.trim()) {
            submitAddTask(quadrant, input.value.trim());
        } else if (e.key === 'Escape') {
            form.style.display = 'none';
        }
    };

    input.onblur = function() {
        setTimeout(() => {
            if (!input.value.trim()) form.style.display = 'none';
        }, 200);
    };
}

async function submitAddTask(quadrant, title) {
    const form = document.getElementById('q' + quadrant + '-form');
    try {
        await apiFetch(BASE_URL + '/api/tasks', {
            method: 'POST',
            body:   JSON.stringify({ title, quadrant })
        });
        form.style.display = 'none';
        await loadTasks();
        toast('เพิ่มงานแล้ว');
    } catch (err) {
        toast(err.message || 'เพิ่มงานไม่สำเร็จ', 'danger');
    }
}

/* --- Edit Task Modal --- */
function openEditTask(id) {
    let task = null;
    for (const q in allTasks) {
        task = allTasks[q].find(t => t.id === id);
        if (task) break;
    }
    if (!task) return;

    document.getElementById('editTaskId').value      = task.id;
    document.getElementById('editTaskTitle').value   = task.title;
    document.getElementById('editTaskDesc').value    = task.description || '';
    document.getElementById('editTaskQuadrant').value = task.quadrant;
    document.getElementById('editTaskDue').value     = task.due_date || '';

    openModal('editTaskModal');
}

async function saveEditTask() {
    const id       = parseInt(document.getElementById('editTaskId').value);
    const title    = document.getElementById('editTaskTitle').value.trim();
    const desc     = document.getElementById('editTaskDesc').value.trim();
    const quadrant = parseInt(document.getElementById('editTaskQuadrant').value);
    const due      = document.getElementById('editTaskDue').value;

    if (!title) { toast('กรุณากรอกชื่องาน', 'danger'); return; }

    try {
        await apiFetch(BASE_URL + '/api/tasks/' + id, {
            method: 'PUT',
            body:   JSON.stringify({ title, description: desc, quadrant, due_date: due })
        });
        closeModal('editTaskModal');
        await loadTasks();
        toast('บันทึกแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

/* --- Delete Task --- */
async function deleteTask(id) {
    if (!await confirmAction('ต้องการลบงานนี้?', 'ลบ')) return;
    try {
        await apiFetch(BASE_URL + '/api/tasks/' + id, { method: 'DELETE' });
        await loadTasks();
        toast('ลบแล้ว');
    } catch {
        toast('ลบไม่สำเร็จ', 'danger');
    }
}

/* --- Helper --- */
function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
