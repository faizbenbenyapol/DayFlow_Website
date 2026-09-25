// =====================================================
// settings-two-factor.js — the two-factor card on the settings page
// =====================================================

/* =====================================================
   Two-factor authentication
   Three states share one card: off, mid-enrolment, on.
===================================================== */
(function initTwoFactor() {
    const card = document.getElementById('tfaOff');
    if (!card) return;

    const $id = id => document.getElementById(id);
    const show = (id, visible) => { const el = $id(id); if (el) el.style.display = visible ? 'block' : 'none'; };

    function setBadge(text, kind) {
        const badge = $id('tfaBadge');
        if (!badge) return;
        badge.textContent = text;
        badge.className = 'badge ' + (kind || 'badge-gray');
    }

    function renderState(status) {
        show('tfaOff', !status.enabled && !status.pending);
        show('tfaEnrol', status.pending);
        show('tfaOn', status.enabled);

        if (status.enabled) {
            setBadge('เปิดใช้งาน', 'badge-success');
            const left = $id('tfaCodesLeft');
            if (left) left.textContent = status.recovery_codes_left;
        } else if (status.pending) {
            setBadge('กำลังตั้งค่า', 'badge-warning');
        } else {
            setBadge('ปิดอยู่', 'badge-gray');
        }
    }

    async function refresh() {
        try {
            renderState(await apiFetch(BASE_URL + '/api/settings/two-factor'));
        } catch {
            setBadge('โหลดสถานะไม่สำเร็จ', 'badge-danger');
        }
    }

    function renderQr(uri) {
        const target = $id('tfaQr');
        if (!target) return;
        if (typeof qrcode === 'undefined') {
            // The secret below the QR is still enough to finish setup by hand.
            target.innerHTML = '<p class="text-xs text-muted">แสดง QR ไม่ได้ กรุณากรอกรหัสด้านล่างในแอปแทน</p>';
            return;
        }
        const qr = qrcode(0, 'M');
        qr.addData(uri);
        qr.make();
        target.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0 });
    }

    function showRecoveryCodes(codes) {
        const list = $id('tfaRecoveryList');
        if (!list) return;
        list.textContent = codes.join('\n');
        show('tfaRecovery', true);
    }

    $id('btnTfaBegin')?.addEventListener('click', async function () {
        const password = $id('tfaBeginPassword').value;
        if (!password) { toast('กรุณากรอกรหัสผ่าน', 'danger'); return; }

        try {
            const data = await apiFetch(BASE_URL + '/api/settings/two-factor/begin', {
                method: 'POST', body: JSON.stringify({ password })
            });
            $id('tfaBeginPassword').value = '';
            $id('tfaSecret').textContent = data.secret;
            renderQr(data.uri);
            show('tfaOff', false);
            show('tfaEnrol', true);
            setBadge('กำลังตั้งค่า', 'badge-warning');
            $id('tfaConfirmCode').focus();
        } catch (err) {
            toast(err.message || 'เริ่มตั้งค่าไม่สำเร็จ', 'danger');
        }
    });

    $id('btnTfaConfirm')?.addEventListener('click', async function () {
        const code = $id('tfaConfirmCode').value.trim();
        if (!code) { toast('กรุณากรอกรหัสจากแอป', 'danger'); return; }

        try {
            const data = await apiFetch(BASE_URL + '/api/settings/two-factor/confirm', {
                method: 'POST', body: JSON.stringify({ code })
            });
            $id('tfaConfirmCode').value = '';
            showRecoveryCodes(data.recovery_codes || []);
            await refresh();
            toast('เปิดใช้งานการยืนยันสองชั้นแล้ว');
        } catch (err) {
            toast(err.message || 'รหัสไม่ถูกต้อง', 'danger');
        }
    });

    $id('btnTfaCancel')?.addEventListener('click', async function () {
        // Enrolment was never confirmed, so nothing is protecting the account
        // yet; the password the user just typed is enough to back out.
        const password = prompt('ยืนยันรหัสผ่านเพื่อยกเลิกการตั้งค่า');
        if (!password) return;
        try {
            await apiFetch(BASE_URL + '/api/settings/two-factor', {
                method: 'DELETE', body: JSON.stringify({ password })
            });
            show('tfaEnrol', false);
            show('tfaRecovery', false);
            await refresh();
        } catch (err) {
            toast(err.message || 'ยกเลิกไม่สำเร็จ', 'danger');
        }
    });

    $id('btnTfaRegenerate')?.addEventListener('click', async function () {
        const password = $id('tfaManagePassword').value;
        if (!password) { toast('กรุณากรอกรหัสผ่าน', 'danger'); return; }
        if (!await confirmAction('รหัสสำรองชุดเดิมจะใช้ไม่ได้อีก ต้องการสร้างชุดใหม่?', 'สร้างใหม่')) return;

        try {
            const data = await apiFetch(BASE_URL + '/api/settings/two-factor/recovery', {
                method: 'POST', body: JSON.stringify({ password })
            });
            $id('tfaManagePassword').value = '';
            showRecoveryCodes(data.recovery_codes || []);
            await refresh();
            toast('สร้างรหัสสำรองชุดใหม่แล้ว');
        } catch (err) {
            toast(err.message || 'สร้างรหัสสำรองไม่สำเร็จ', 'danger');
        }
    });

    $id('btnTfaDisable')?.addEventListener('click', async function () {
        const password = $id('tfaManagePassword').value;
        if (!password) { toast('กรุณากรอกรหัสผ่าน', 'danger'); return; }
        if (!await confirmAction('บัญชีจะกลับไปใช้แค่รหัสผ่าน ต้องการปิดการยืนยันสองชั้น?', 'ปิดใช้งาน')) return;

        try {
            await apiFetch(BASE_URL + '/api/settings/two-factor', {
                method: 'DELETE', body: JSON.stringify({ password })
            });
            $id('tfaManagePassword').value = '';
            show('tfaRecovery', false);
            await refresh();
            toast('ปิดการยืนยันสองชั้นแล้ว');
        } catch (err) {
            toast(err.message || 'ปิดใช้งานไม่สำเร็จ', 'danger');
        }
    });

    $id('btnTfaCopyCodes')?.addEventListener('click', async function () {
        const text = $id('tfaRecoveryList').textContent;
        try {
            await navigator.clipboard.writeText(text);
            toast('คัดลอกรหัสสำรองแล้ว');
        } catch {
            toast('คัดลอกไม่สำเร็จ กรุณาเลือกข้อความแล้วคัดลอกเอง', 'danger');
        }
    });

    refresh();
})();
