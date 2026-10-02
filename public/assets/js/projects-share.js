/* =====================================================
   projects-share.js — the public link to a project, and its guests
   Loaded after projects.js on the projects page; shares its state.
===================================================== */

function loadProjectShareSettings() {
    if (!activeProjectData || !activeProjectData.project) return;
    const p = activeProjectData.project;
    const toggle = document.getElementById('shareLinkToggle');
    const details = document.getElementById('shareLinkDetails');
    const roleSelect = document.getElementById('shareLinkRole');
    const urlInput = document.getElementById('shareLinkUrl');

    if (p.share_token) {
        toggle.checked = true;
        details.hidden = false;
        roleSelect.value = p.share_role || 'Viewer';
        urlInput.value = BASE_URL + '/project/shared/' + p.share_token;
    } else {
        toggle.checked = false;
        details.hidden = true;
        roleSelect.value = 'Viewer';
        urlInput.value = '';
    }
}

async function togglePublicShare() {
    if (!activeProjectId) return;
    const toggle = document.getElementById('shareLinkToggle');
    try {
        if (toggle.checked) {
            const res = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/share', {
                method: 'POST',
                body: JSON.stringify({ share_role: document.getElementById('shareLinkRole').value })
            });
            activeProjectData.project.share_token = res.share_token;
            activeProjectData.project.share_role = res.share_role;
            toast('เปิดลิงก์แล้ว ใครมีลิงก์ก็เข้าได้');
        } else {
            await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/share', { method: 'DELETE' });
            activeProjectData.project.share_token = null;
            activeProjectData.project.share_role = 'Viewer';
            toast('ปิดลิงก์แล้ว');
        }
        loadProjectShareSettings();
    } catch (err) {
        toggle.checked = !toggle.checked;     // not saved: put the switch back
        toast(err.message || 'ทำไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

async function updateShareRole() {
    if (!activeProjectId) return;
    try {
        const res = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/share', {
            method: 'POST',
            body: JSON.stringify({ share_role: document.getElementById('shareLinkRole').value })
        });
        activeProjectData.project.share_token = res.share_token;
        activeProjectData.project.share_role = res.share_role;
        toast('เปลี่ยนสิทธิ์ของลิงก์แล้ว');
        loadProjectShareSettings();
    } catch (err) {
        toast(err.message || 'เปลี่ยนสิทธิ์ไม่สำเร็จ', 'danger');
    }
}

async function copyShareUrl() {
    const urlInput = document.getElementById('shareLinkUrl');
    if (!urlInput.value) return;
    try {
        await navigator.clipboard.writeText(urlInput.value);
        toast('คัดลอกลิงก์แล้ว');
    } catch {
        urlInput.select();
        toast('คัดลอกอัตโนมัติไม่ได้ เลือกข้อความไว้แล้ว กด Ctrl+C', 'danger');
    }
}

/* ── A guest's display name ── */
function changeGuestName() {
    document.getElementById('guestNameInput').value = CURRENT_GUEST_NAME ? CURRENT_GUEST_NAME.replace(' (ผู้เยี่ยมชม)', '') : '';
    formErrorLine('guestNameError', '');
    openModal('guestNameModal');
    document.getElementById('guestNameInput').focus();
}

async function saveGuestName(event) {
    event.preventDefault();
    const name = document.getElementById('guestNameInput').value.trim();
    if (!name) { formErrorLine('guestNameError', 'ใส่ชื่อของคุณ'); return; }

    try {
        await apiFetch(BASE_URL + '/api/projects/guest-name', { method: 'POST', body: JSON.stringify({ name }) });
        window.location.reload();             // the session and the chat take the new name
    } catch (err) {
        formErrorLine('guestNameError', err.message || 'เปลี่ยนชื่อไม่สำเร็จ ลองอีกครั้ง');
    }
}
