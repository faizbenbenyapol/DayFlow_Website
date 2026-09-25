/* =====================================================
   projects-share.js — the public link to a project, and its guests
   Loaded after projects.js on the projects page; shares its state.
===================================================== */

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

