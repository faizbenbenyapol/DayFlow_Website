// =====================================================
// shares.js — Share Links management (settings tab)
// =====================================================
(function () {
    'use strict';

    const $ = s => document.querySelector(s);
    let editingShareId = null;

    // ---- Helpers ----
    function fmtDate(d) {
        if (!d) return null;
        return new Date(d).toLocaleString('th-TH');
    }
    function isExpired(d) {
        if (!d) return false;
        return new Date(d) < new Date();
    }

    // ---- Load shares list ----
    async function loadShares() {
        const tbody = document.getElementById('sharesTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5"><span class="skel skel-w-52"></span></td></tr>';
        try {
            const data = await apiFetch(BASE_URL + '/api/shares');
            const shares = data.shares || [];
            if (!shares.length) {
                tbody.innerHTML = '<tr><td colspan="5"><div class="share-empty">ยังไม่มีลิงก์แชร์ ไปที่หน้า <a href="' + BASE_URL + '/files">ไฟล์</a> กดปุ่มตัวเลือกของไฟล์แล้วเลือก "แชร์ลิงก์"</div></td></tr>';
                return;
            }
            tbody.innerHTML = shares.map(s => {
                const link = BASE_URL + '/share/' + s.token;
                const expired = isExpired(s.expires_at);
                const exp = s.expires_at
                    ? `<span class="${expired ? 'share-expired' : 'share-no-expiry'}">${expired ? 'หมดอายุแล้ว' : fmtDate(s.expires_at)}</span>`
                    : '<span class="share-no-expiry">ไม่มีกำหนด</span>';

                return `<tr>
                    <td>
                        <div class="share-name">${escHtml(s.label || s.file_name)}</div>
                        <div class="share-sub">${s.file_type === 'folder' ? 'โฟลเดอร์' : 'ไฟล์'} · ${escHtml(s.file_name)}</div>
                    </td>
                    <td>
                        <a class="share-link-url" href="${escHtml(link)}" target="_blank" rel="noopener">${escHtml(link)}</a>
                    </td>
                    <td><span class="badge ${s.permission === 'download' ? 'badge-dark' : 'badge-gray'}">${s.permission === 'download' ? 'ดาวน์โหลด' : 'ดูอย่างเดียว'}</span></td>
                    <td>${exp}</td>
                    <td>
                        <div class="share-actions">
                            <button class="btn btn-sm" data-act="copy" data-link="${escHtml(link)}" title="คัดลอกลิงก์">คัดลอก</button>
                            <button class="btn btn-sm" data-act="edit" data-id="${s.id}" data-file-id="${s.file_id}"
                                data-label="${escHtml(s.label)}" data-perm="${escHtml(s.permission)}" data-exp="${escHtml(s.expires_at||'')}">แก้ไข</button>
                            <button class="btn btn-sm btn-danger" data-act="del" data-id="${s.id}">ลบ</button>
                        </div>
                    </td>
                </tr>`;
            }).join('');

            // Bind actions
            tbody.querySelectorAll('[data-act]').forEach(btn => {
                const act = btn.dataset.act;
                if (act === 'copy') btn.addEventListener('click', () => { navigator.clipboard?.writeText(btn.dataset.link).then(() => toast('คัดลอกแล้ว')).catch(() => toast('คัดลอกไม่สำเร็จ', 'danger')); });
                if (act === 'edit') btn.addEventListener('click', () => openEditModal(btn));
                if (act === 'del')  btn.addEventListener('click', () => deleteShare(parseInt(btn.dataset.id)));
            });
        } catch { toast('โหลดรายการแชร์ไม่สำเร็จ', 'danger'); }
    }

    // ---- Edit share modal ----
    function openEditModal(btn) {
        editingShareId = parseInt(btn.dataset.id);
        $('#smLabel').value = btn.dataset.label || '';
        $('#smPermission').value = btn.dataset.perm || 'view';
        if (btn.dataset.exp) {
            try {
                const d = new Date(btn.dataset.exp);
                const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                $('#smExpires').value = local;
            } catch { $('#smExpires').value = ''; }
        } else {
            $('#smExpires').value = '';
        }
        openModal('shareModalOverlay');
    }

    function closeShareModal() {
        closeModal('shareModalOverlay');
        editingShareId = null;
    }

    // ---- Save (update only) ----
    async function saveShare() {
        if (!editingShareId) return;
        const label      = $('#smLabel').value.trim();
        const permission = $('#smPermission').value;
        const expires    = $('#smExpires').value;
        try {
            await apiFetch(BASE_URL + '/api/shares/' + editingShareId, {
                method: 'PUT',
                body: JSON.stringify({ label, permission, expires_at: expires || null })
            });
            closeShareModal();
            toast('บันทึกแล้ว');
            loadShares();
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    // ---- Delete share ----
    async function deleteShare(id) {
        if (!await confirmAction('ลบลิงก์แชร์นี้?', 'ลบ')) return;
        try {
            await apiFetch(BASE_URL + '/api/shares/' + id, { method: 'DELETE' });
            toast('ลบแล้ว');
            loadShares();
        } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
    }

    // ---- Init ----
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('btnSaveShare')?.addEventListener('click', saveShare);

        // Load when the topic is first shown
        onSettingsTab('shares', loadShares);
    });
})();
