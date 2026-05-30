/* =====================================================
   exercise.js
===================================================== */

let workouts = [];
let editingId = null;

document.addEventListener('DOMContentLoaded', async function () {
    await Promise.all([loadWorkouts(), loadStats()]);
    await initWorkoutTypeDropdown();
});

async function loadWorkouts(month) {
    const m = month || document.getElementById('monthFilter')?.value || '';
    try {
        const data = await apiFetch(BASE_URL + '/api/exercise?limit=100' + (m ? '&month=' + m : ''));
        workouts = data.workouts || [];
        renderWorkouts();
    } catch {
        toast('โหลดข้อมูลไม่สำเร็จ', 'danger');
    }
}

async function loadStats() {
    try {
        const data = await apiFetch(BASE_URL + '/api/exercise/stats');
        document.getElementById('statSessions').textContent = data.month_sessions || 0;
        document.getElementById('statMinutes').textContent  = data.month_minutes  || 0;

        const topType = data.by_type && data.by_type[0] ? data.by_type[0].type : 'ยังไม่มี';
        document.getElementById('statTopType').textContent = topType;

        renderTypeStats(data.by_type || []);
    } catch {}
}

function renderWorkouts() {
    const tbody = document.getElementById('workoutList');
    if (!workouts.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:2rem">ยังไม่มีการบันทึก</td></tr>';
        return;
    }

    tbody.innerHTML = workouts.map(w => `
        <tr>
            <td>${escHtml(formatDate(w.workout_date))}</td>
            <td><span class="workout-type-badge" data-wtype="${escHtml(w.type)}">${escHtml(w.type)}</span></td>
            <td>${w.duration_min ? w.duration_min + ' นาที' : '—'}</td>
            <td>${w.sets || w.reps ? (w.sets || '—') + ' x ' + (w.reps || '—') : '—'}</td>
            <td>${w.weight_kg ? w.weight_kg + ' กก.' : '—'}</td>
            <td class="text-sm text-muted">${escHtml(w.notes || '')}</td>
            <td>
                <button class="btn-link" onclick="openEditWorkout(${w.id})">แก้ไข</button>
                <button class="btn-link" style="color:var(--color-danger)" onclick="deleteWorkout(${w.id})">ลบ</button>
            </td>
        </tr>
    `).join('');
}

function renderTypeStats(byType) {
    const el = document.getElementById('typeStats');
    if (!byType.length) {
        el.innerHTML = '<div class="text-sm text-muted">ยังไม่มีสถิติ</div>';
        return;
    }

    const max = Math.max(...byType.map(t => t.sessions));

    el.innerHTML = byType.map(t => `
        <div style="margin-bottom:12px">
            <div class="flex justify-between mb-1">
                <span class="text-sm">${escHtml(t.type)}</span>
                <span class="text-xs text-muted">${t.sessions} ครั้ง${t.total_min > 0 ? ' · ' + t.total_min + ' นาที' : ''}</span>
            </div>
            <div class="progress wt-progress" data-wtype="${escHtml(t.type)}"><div class="progress-bar" style="width:${Math.round(t.sessions / max * 100)}%"></div></div>
        </div>
    `).join('');
}

function filterByMonth(m) {
    loadWorkouts(m);
}

/* --- Modal --- */
function openAddWorkout() {
    editingId = null;
    document.getElementById('workoutModalTitle').textContent = 'บันทึกการออกกำลังกาย';
    document.getElementById('editWorkoutId').value = '';
    document.getElementById('workoutDate').value     = todayISO();
    document.getElementById('workoutType').value     = '';
    document.getElementById('workoutDuration').value = '';
    document.getElementById('workoutSets').value     = '';
    document.getElementById('workoutReps').value     = '';
    document.getElementById('workoutWeight').value   = '';
    document.getElementById('workoutNotes').value    = '';
    document.getElementById('workoutTypeDropdown')?.classList.remove('open');
    openModal('workoutModal');
}

function openEditWorkout(id) {
    const w = workouts.find(x => x.id === id);
    if (!w) return;
    editingId = id;
    document.getElementById('workoutModalTitle').textContent = 'แก้ไขการออกกำลังกาย';
    document.getElementById('editWorkoutId').value    = id;
    document.getElementById('workoutDate').value      = w.workout_date;
    document.getElementById('workoutType').value      = w.type;
    document.getElementById('workoutDuration').value  = w.duration_min || '';
    document.getElementById('workoutSets').value      = w.sets || '';
    document.getElementById('workoutReps').value      = w.reps || '';
    document.getElementById('workoutWeight').value    = w.weight_kg || '';
    document.getElementById('workoutNotes').value     = w.notes || '';
    document.getElementById('workoutTypeDropdown')?.classList.remove('open');
    openModal('workoutModal');
}

async function saveWorkout() {
    const body = {
        workout_date: document.getElementById('workoutDate').value,
        type:         document.getElementById('workoutType').value.trim(),
        duration_min: document.getElementById('workoutDuration').value,
        sets:         document.getElementById('workoutSets').value,
        reps:         document.getElementById('workoutReps').value,
        weight_kg:    document.getElementById('workoutWeight').value,
        notes:        document.getElementById('workoutNotes').value
    };

    if (!body.type) { toast('กรุณากรอกประเภท', 'danger'); return; }
    if (!body.workout_date) { toast('กรุณาเลือกวันที่', 'danger'); return; }

    try {
        const url    = editingId ? BASE_URL + '/api/exercise/' + editingId : BASE_URL + '/api/exercise';
        const method = editingId ? 'PUT' : 'POST';
        await apiFetch(url, { method, body: JSON.stringify(body) });
        closeModal('workoutModal');
        await Promise.all([loadWorkouts(), loadStats()]);
        toast('บันทึกแล้ว');
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

async function deleteWorkout(id) {
    if (!await confirmAction('ต้องการลบรายการนี้?', 'ลบ')) return;
    await apiFetch(BASE_URL + '/api/exercise/' + id, { method: 'DELETE' });
    await Promise.all([loadWorkouts(), loadStats()]);
    toast('ลบแล้ว');
}

function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Custom Workout Type Dropdown Implementation ── */
async function initWorkoutTypeDropdown() {
    const dropdown = document.getElementById('workoutTypeDropdown');
    const input = document.getElementById('workoutType');
    const menu = document.getElementById('workoutTypeMenu');
    if (!dropdown || !input || !menu) return;

    let defaultTypes = ['วิ่ง', 'ยกน้ำหนัก', 'ว่ายน้ำ', 'ปั่นจักรยาน', 'โยคะ', 'HIIT', 'เดิน', 'กระโดดเชือก'];
    try {
        const data = await apiFetch(BASE_URL + '/api/exercise/categories');
        if (data.categories && data.categories.length > 0) {
            defaultTypes = data.categories.map(c => c.name);
        }
    } catch (e) {
        console.error('Failed to load exercise categories', e);
    }

    function renderOptions(filterText = '') {
        const query = filterText.trim().toLowerCase();
        let html = '';
        
        // Filter the default list
        const filtered = defaultTypes.filter(t => t.toLowerCase().includes(query));
        
        if (filtered.length > 0) {
            html = filtered.map(t => {
                const isActive = input.value.trim() === t;
                return `
                    <div class="dropdown-item ${isActive ? 'active' : ''}" data-value="${escHtml(t)}">
                        <span class="workout-type-badge" data-wtype="${escHtml(t)}">${escHtml(t)}</span>
                        ${isActive ? '<span class="dropdown-item-check">✓</span>' : ''}
                    </div>
                `;
            }).join('');
        } else if (query) {
            // Show option to use the custom typed value if nothing matched
            html = `
                <div class="dropdown-item custom-val" data-value="${escHtml(filterText.trim())}">
                    <span class="text-xs text-muted" style="margin-right: var(--space-2)">ใช้:</span>
                    <span class="workout-type-badge" style="background: var(--color-surface-2); color: var(--color-text);">${escHtml(filterText.trim())}</span>
                </div>
            `;
        } else {
            // Show all
            html = defaultTypes.map(t => {
                const isActive = input.value.trim() === t;
                return `
                    <div class="dropdown-item ${isActive ? 'active' : ''}" data-value="${escHtml(t)}">
                        <span class="workout-type-badge" data-wtype="${escHtml(t)}">${escHtml(t)}</span>
                        ${isActive ? '<span class="dropdown-item-check">✓</span>' : ''}
                    </div>
                `;
            }).join('');
        }
        
        menu.innerHTML = html;
        
        // Attach click listeners to options
        menu.querySelectorAll('.dropdown-item').forEach(item => {
            item.addEventListener('mousedown', function(e) {
                // Use mousedown instead of click to fire before input blur
                e.preventDefault();
                const val = this.getAttribute('data-value');
                input.value = val;
                closeDropdown();
                input.dispatchEvent(new Event('input'));
            });
        });
    }

    function openDropdown() {
        dropdown.classList.add('open');
        renderOptions(input.value);
    }

    function closeDropdown() {
        dropdown.classList.remove('open');
        // Clear focus highlighting
        menu.querySelectorAll('.dropdown-item').forEach(item => item.classList.remove('focused'));
    }

    // Toggle dropdown when input focused or clicked
    input.addEventListener('focus', openDropdown);
    input.addEventListener('click', (e) => {
        e.stopPropagation();
        openDropdown();
    });

    // Handle caret click
    const caret = dropdown.querySelector('.dropdown-caret');
    if (caret) {
        caret.style.cursor = 'pointer';
        caret.addEventListener('mousedown', (e) => {
            e.preventDefault();
            if (dropdown.classList.contains('open')) {
                closeDropdown();
            } else {
                input.focus();
            }
        });
    }

    // Filter list as user types
    input.addEventListener('input', () => {
        if (!dropdown.classList.contains('open')) {
            openDropdown();
        } else {
            renderOptions(input.value);
        }
    });

    // Close when input blurs (with a slight delay to allow item clicks, though mousedown preventDefault handles most)
    input.addEventListener('blur', () => {
        setTimeout(closeDropdown, 180);
    });

    // Close when clicking outside
    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target)) {
            closeDropdown();
        }
    });

    // Handle keys: Escape to close, ArrowDown / ArrowUp to navigate, Enter to select
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeDropdown();
            input.blur();
        } else if (e.key === 'ArrowDown') {
            if (!dropdown.classList.contains('open')) {
                openDropdown();
            } else {
                const items = menu.querySelectorAll('.dropdown-item');
                if (items.length > 0) {
                    let activeIndex = Array.from(items).findIndex(item => item.classList.contains('focused'));
                    if (activeIndex !== -1) items[activeIndex].classList.remove('focused');
                    
                    activeIndex = (activeIndex + 1) % items.length;
                    items[activeIndex].classList.add('focused');
                    items[activeIndex].scrollIntoView({ block: 'nearest' });
                }
            }
            e.preventDefault();
        } else if (e.key === 'ArrowUp') {
            if (dropdown.classList.contains('open')) {
                const items = menu.querySelectorAll('.dropdown-item');
                if (items.length > 0) {
                    let activeIndex = Array.from(items).findIndex(item => item.classList.contains('focused'));
                    if (activeIndex !== -1) items[activeIndex].classList.remove('focused');
                    
                    activeIndex = (activeIndex - 1 + items.length) % items.length;
                    items[activeIndex].classList.add('focused');
                    items[activeIndex].scrollIntoView({ block: 'nearest' });
                }
            }
            e.preventDefault();
        } else if (e.key === 'Enter') {
            if (dropdown.classList.contains('open')) {
                const focusedItem = menu.querySelector('.dropdown-item.focused');
                if (focusedItem) {
                    const val = focusedItem.getAttribute('data-value');
                    input.value = val;
                    closeDropdown();
                    input.dispatchEvent(new Event('input'));
                    e.preventDefault();
                } else {
                    closeDropdown();
                }
            }
        }
    });
}
