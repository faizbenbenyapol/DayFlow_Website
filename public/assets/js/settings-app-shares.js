// =====================================================
// settings-app-shares.js — links that share whole menus (settings, "แชร์เมนู")
// =====================================================
(function () {
    'use strict';
    const $ = s => document.querySelector(s);
    const $$ = s => Array.from(document.querySelectorAll(s));

    const MENU_NAMES = {
        'tasks': 'งาน', 'notes': 'โน้ต', 'planner': 'แพลนเนอร์',
        'exercise': 'ออกกำลังกาย', 'food-notes': 'อาหาร',
        'finance': 'รายรับรายจ่าย', 'subscriptions': 'รายจ่ายประจำ', 'stocks': 'หุ้น'
    };

    const fmtDate = d => new Date(d).toLocaleString('th-TH');
    const isExpired = d => !!d && new Date(d) < new Date();

    function showError(message) {
        const line = $('#appShareError');
        line.textContent = message;
        line.hidden = message === '';
    }

    async function loadAppShares() {
        const tbody = $('#appSharesTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5"><span class="skel skel-w-52"></span></td></tr>';
        try {
            const res = await apiFetch(BASE_URL + '/api/app-shares');
            const shares = res.shares || [];
            if (!shares.length) {
                tbody.innerHTML = '<tr><td colspan="5"><div class="share-empty">ยังไม่มีลิงก์แชร์เมนู สร้างได้จากฟอร์มด้านบน</div></td></tr>';
                return;
            }
            tbody.innerHTML = shares.map(s => {
                const link = BASE_URL + '/shared/' + s.token;
                const expired = isExpired(s.expires_at);
                const expiry = s.expires_at
                    ? '<span class="' + (expired ? 'share-expired' : 'share-no-expiry') + '">' + (expired ? 'หมดอายุแล้ว' : escHtml(fmtDate(s.expires_at))) + '</span>'
                    : '<span class="share-no-expiry">ไม่มีกำหนด</span>';
                const menus = (s.menus || []).map(m => MENU_NAMES[m] || m).join(', ');

                return '<tr>'
                    + '<td><div class="share-name">' + escHtml(s.label) + '</div></td>'
                    + '<td><span class="share-sub">' + escHtml(menus) + '</span></td>'
                    + '<td><a class="share-link-url" href="' + escHtml(link) + '" target="_blank" rel="noopener">' + escHtml(link) + '</a></td>'
                    + '<td>' + expiry + '</td>'
                    + '<td><div class="share-actions">'
                    + '<button type="button" class="btn btn-sm btn-copy-app" data-link="' + escHtml(link) + '">คัดลอก</button>'
                    + '<button type="button" class="btn btn-sm btn-danger btn-del-app" data-id="' + s.id + '">ลบ</button>'
                    + '</div></td></tr>';
            }).join('');

            $$('.btn-copy-app').forEach(btn => btn.addEventListener('click', () => {
                navigator.clipboard?.writeText(btn.dataset.link).then(() => toast('คัดลอกลิงก์แล้ว')).catch(() => toast('คัดลอกไม่สำเร็จ', 'danger'));
            }));
            $$('.btn-del-app').forEach(btn => btn.addEventListener('click', async () => {
                if (!await confirmAction('คนที่มีลิงก์นี้จะเปิดดูไม่ได้อีก', 'ลบลิงก์', 'ลบลิงก์แชร์นี้?')) return;
                try {
                    await apiFetch(BASE_URL + '/api/app-shares/' + btn.dataset.id, { method: 'DELETE' });
                    toast('ลบลิงก์แล้ว');
                    loadAppShares();
                } catch (err) { toast(err.message || 'ลบไม่สำเร็จ', 'danger'); }
            }));
        } catch {
            tbody.innerHTML = '<tr><td colspan="5"><div class="alert alert-danger" role="alert">โหลดรายการแชร์ไม่สำเร็จ <button type="button" class="btn btn-sm" id="btnRetryAppShares">ลองอีกครั้ง</button></div></td></tr>';
            $('#btnRetryAppShares')?.addEventListener('click', loadAppShares);
        }
    }

    async function createAppShare() {
        const label = $('#asNewLabel').value.trim();
        const expires_at = $('#asNewExpires').value;
        const menus = $$('input[name="as_menus[]"]:checked').map(cb => cb.value);

        if (!label) { showError('ตั้งชื่อลิงก์ก่อน'); $('#asNewLabel').focus(); return; }
        if (!menus.length) { showError('เลือกเมนูที่จะแชร์อย่างน้อย 1 เมนู'); return; }
        showError('');

        try {
            await apiFetch(BASE_URL + '/api/app-shares', {
                method: 'POST',
                body: JSON.stringify({ label, expires_at: expires_at || null, menus })
            });
            $('#asNewLabel').value = '';
            $('#asNewExpires').value = '';
            $$('input[name="as_menus[]"]').forEach(cb => { cb.checked = false; });
            toast('สร้างลิงก์แล้ว');
            loadAppShares();
        } catch (err) { showError(err.message || 'สร้างลิงก์ไม่สำเร็จ ลองอีกครั้ง'); }
    }

    document.addEventListener('DOMContentLoaded', () => {
        $('#btnCreateAppShare')?.addEventListener('click', createAppShare);
        onSettingsTab('app-shares', loadAppShares);
    });
})();
