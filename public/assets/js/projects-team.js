/* =====================================================
   projects-team.js — members and the project chat
   Loaded after projects.js on the projects page; shares its state.
===================================================== */

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
                
                const init = escHtml(String(m.display_name || m.username || "").substring(0, 1).toUpperCase());
                
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
                            <span class="member-role-badge ${roleCls}">${escHtml(m.role)}</span>
                            ${showDelete ? `
                                <button type="button" class="btn-remove-member" data-act="removeMember" data-args="[${m.id}]" title="ลบสมาชิกออกจากกลุ่ม">&#215;</button>
                            ` : ''}
                            ${!isOwner && isMe && m.role !== 'Owner' ? `
                                <button type="button" class="btn btn-danger btn-xs" data-act="removeMember" data-args="[${m.id}]" style="padding:2px 8px; font-size:0.7rem; border-radius:var(--radius-md);">ออกจากโครงการ</button>
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

