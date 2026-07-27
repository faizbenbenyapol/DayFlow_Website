const habitsState = { items: [] };

async function loadHabits() {
    const grid = document.getElementById('habitsGrid');
    try {
        const data = await apiFetch(BASE_URL + '/api/habits');
        habitsState.items = data.habits || [];
        renderHabits();
    } catch (e) {
        grid.innerHTML = '<div class="empty-state">โหลดข้อมูลไม่สำเร็จ ลองใหม่อีกครั้ง</div>';
    }
}

function renderHabits() {
    const grid = document.getElementById('habitsGrid');
    const items = habitsState.items;
    const done = items.filter(h => Number(h.completed_today) === 1).length;
    document.getElementById('habitsSummary').textContent = items.length ? `วันนี้ทำสำเร็จ ${done} จาก ${items.length} นิสัย` : '';
    if (!items.length) {
        grid.innerHTML = '<div class="empty-state">ยังไม่มีนิสัย เริ่มจากสิ่งเล็ก ๆ ที่อยากทำทุกวัน</div>';
        return;
    }
    grid.innerHTML = items.map(h => `
        <article class="habit-card ${Number(h.completed_today) ? 'is-complete' : ''}" style="--habit-color:${escHtml(h.color)}">
            <div class="habit-card-top">
                <span class="habit-dot" aria-hidden="true"></span>
                <button class="habit-edit" type="button" data-edit-habit="${h.id}" aria-label="แก้ไข ${escHtml(h.name)}">แก้ไข</button>
            </div>
            <h2>${escHtml(h.name)}</h2>
            <p class="text-muted">เป้าหมาย ${h.target_days} วัน/สัปดาห์ · สำเร็จ ${h.completed_30d} วันใน 30 วันที่ผ่านมา</p>
            <button class="habit-check ${Number(h.completed_today) ? 'checked' : ''}" type="button" data-toggle-habit="${h.id}" aria-pressed="${Number(h.completed_today) ? 'true' : 'false'}">
                <span aria-hidden="true">${Number(h.completed_today) ? '✓' : '○'}</span> ${Number(h.completed_today) ? 'ทำวันนี้แล้ว' : 'ทำสำเร็จวันนี้'}
            </button>
        </article>`).join('');
}

function openHabit(item = null) {
    document.getElementById('habitModal').hidden = false;
    document.getElementById('habitModalTitle').textContent = item ? 'แก้ไขนิสัย' : 'เพิ่มนิสัย';
    document.getElementById('habitId').value = item?.id || '';
    document.getElementById('habitName').value = item?.name || '';
    document.getElementById('habitTarget').value = item?.target_days || 7;
    document.getElementById('habitColor').value = item?.color || '#6366f1';
    document.getElementById('habitName').focus();
}

function closeHabit() { document.getElementById('habitModal').hidden = true; }

document.getElementById('habitAddBtn')?.addEventListener('click', () => openHabit());
document.querySelectorAll('[data-close-habit]').forEach(el => el.addEventListener('click', closeHabit));
document.getElementById('habitForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('habitId').value;
    const body = { name: document.getElementById('habitName').value.trim(), target_days: Number(document.getElementById('habitTarget').value), color: document.getElementById('habitColor').value };
    try {
        await apiFetch(BASE_URL + (id ? '/api/habits/' + id : '/api/habits'), { method: id ? 'PUT' : 'POST', body: JSON.stringify(body) });
        closeHabit(); await loadHabits();
    } catch (e) { if (typeof showToast === 'function') showToast(e.message, 'error'); }
});

document.getElementById('habitsGrid')?.addEventListener('click', async e => {
    const toggle = e.target.closest('[data-toggle-habit]');
    const edit = e.target.closest('[data-edit-habit]');
    try {
        if (toggle) { await apiFetch(BASE_URL + '/api/habits/' + toggle.dataset.toggleHabit + '/toggle', { method: 'POST' }); await loadHabits(); }
        if (edit) openHabit(habitsState.items.find(h => String(h.id) === edit.dataset.editHabit));
    } catch (err) { if (typeof showToast === 'function') showToast(err.message, 'error'); }
});

loadHabits();
