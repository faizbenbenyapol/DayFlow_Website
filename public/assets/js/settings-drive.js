// =====================================================
// settings-drive.js — ตั้งค่า > Google Drive (เฟส 4 REQ-20, REQ-22, EDGE-12)
// แสดงหนึ่งในสี่แบบของ #driveBox ตามสถานะจาก /api/drive และย้ายไฟล์เดิมต่อเองระหว่างเปิดหน้านี้
// (ปิดหน้าไปแล้ว cron ย้ายต่อทุก 5 นาที) โหลดต่อจาก settings.js เป็น classic script
// =====================================================
(function () {
    'use strict';

    const box = document.getElementById('driveBox');
    if (!box) return;
    const $ = id => document.getElementById(id);

    /** ข้อความหลังกลับจากหน้าขออนุญาตของ Google (?drive=...) */
    const FLASH = {
        connected: ['alert-success', 'เชื่อม Google Drive แล้ว'],
        denied: ['alert-warning', 'ยังไม่ได้เชื่อม: กดยกเลิกที่หน้าของ Google'],
        failed: ['alert-danger', 'เชื่อม Google Drive ไม่สำเร็จ ลองอีกครั้ง'],
        'other-account': ['alert-danger', 'มีไฟล์อยู่ใน Google Drive ของบัญชีที่เคยเชื่อมไว้ เชื่อมด้วยบัญชีเดิมเพื่อเปิดไฟล์เหล่านั้น'],
    };

    let migrating = false;

    /** แสดงเฉพาะแบบที่ระบุ @param {'off'|'none'|'revoked'|'on'} name */
    function show(name) {
        box.querySelectorAll('[data-drive]').forEach(part => { part.hidden = part.dataset.drive !== name; });
        box.removeAttribute('aria-busy');
    }

    /**
     * วาดตามสถานะ แล้วย้ายต่อถ้ายังเหลือ
     * @param {{configured: boolean, state: string, email: ?string, folder: ?string, pending: number, done: number}} s
     */
    function render(s) {
        if (!s.configured) return show('off');
        if (s.state === 'revoked') return show('revoked');
        if (s.state === 'none' || s.state === 'disconnected') {
            $('driveNoneNote').textContent = s.state === 'disconnected' && s.done > 0
                ? 'เคยเชื่อมกับ ' + s.email + ' ไฟล์ ' + s.done + ' ไฟล์ยังอยู่ใน Google Drive เชื่อมบัญชีเดิมเพื่อเปิดอีกครั้ง'
                : 'เชื่อมแล้ว ไฟล์ที่มีอยู่จะย้ายขึ้น Drive ให้เอง และไฟล์ใหม่จะไปเก็บใน Drive';
            return show('none');
        }
        show('on');
        $('driveEmail').textContent = s.email;
        $('driveFolder').textContent = s.folder + ' (ใน My Drive)';
        $('driveFull').hidden = s.state !== 'paused_full';

        // แถบขึ้นเฉพาะระหว่างย้าย: ย้ายครบแล้ว (connected) ไฟล์ที่หายจากดิสก์ไปก่อนไม่ทำให้แถบค้าง
        const total = s.done + s.pending;
        const moving = (s.state === 'migrating' || s.state === 'paused_full') && s.pending > 0;
        $('driveProgress').hidden = !moving;
        if (moving) {
            const percent = total > 0 ? Math.floor(s.done / total * 100) : 0;
            $('driveProgressFill').style.width = percent + '%';
            $('driveProgressBar').setAttribute('aria-valuenow', String(percent));
            const progress = s.done + ' จาก ' + total + ' ไฟล์';
            $('driveProgressLabel').textContent = s.state === 'paused_full' ? 'ย้ายไฟล์เดิมขึ้น Drive แล้ว ' + progress
                : s.error ? 'ย้ายแล้ว ' + progress + ' บางไฟล์ย้ายไม่สำเร็จ ระบบจะลองใหม่ให้เองทุก 5 นาที'
                : 'กำลังย้ายไฟล์เดิมขึ้น Drive ' + progress + ' ปิดหน้านี้ได้ ระบบย้ายต่อให้เอง';
        }
        // มีไฟล์ล้มในรอบนี้: ไม่ยิงซ้ำทันที (จะล้มซ้ำวนไม่จบ) ปล่อยให้ cron ลองใหม่
        if (s.state === 'migrating' && s.pending > 0 && !s.error) migrateMore();
    }

    /** เรียกย้ายต่อทีละช่วงจนครบหรือสถานะเปลี่ยน (ทำทีละคำขอ ไม่ซ้อนกัน) */
    async function migrateMore() {
        if (migrating) return;
        migrating = true;
        try {
            render(await apiFetch(BASE_URL + '/api/drive/migrate', { method: 'POST', body: '{}' }));
        } catch {
            $('driveProgressLabel').textContent = 'ย้ายไฟล์สะดุด ระบบจะลองต่อให้เองภายหลัง';
        } finally {
            migrating = false;
        }
    }

    // ข้อความหลังกลับจาก Google เป็นของเหตุการณ์นั้น ทำอะไรต่อแล้วต้องหายไป ไม่งั้นค้านกับสถานะใหม่
    box.addEventListener('click', event => {
        if (event.target.closest('button, a')) $('driveFlash').hidden = true;
    });

    $('btnDriveResume').addEventListener('click', async () => {
        $('btnDriveResume').disabled = true;
        try {
            render(await apiFetch(BASE_URL + '/api/drive/resume', { method: 'POST', body: '{}' }));
        } catch (err) {
            toast(err.message || 'ลองต่อไม่สำเร็จ', 'danger');
        } finally {
            $('btnDriveResume').disabled = false;
        }
    });

    $('btnDriveDisconnect').addEventListener('click', async () => {
        const ok = await confirmAction('ไฟล์ที่อยู่ใน Google Drive ยังอยู่ครบ แต่เปิดจาก DayFlow ไม่ได้จนกว่าจะเชื่อมบัญชีเดิมกลับมา ไฟล์ใหม่จะเก็บบนเซิร์ฟเวอร์', 'ยกเลิกการเชื่อม', 'ยกเลิกการเชื่อม Google Drive?');
        if (!ok) return;
        try {
            render(await apiFetch(BASE_URL + '/api/drive/disconnect', { method: 'POST', body: '{}' }));
        } catch (err) {
            toast(err.message || 'ยกเลิกไม่สำเร็จ ลองอีกครั้ง', 'danger');
        }
    });

    // ข้อความหลังกลับจาก Google แล้วล้างพารามิเตอร์ออกจาก URL กดรีเฟรชจะได้ไม่ขึ้นซ้ำ
    const result = new URLSearchParams(location.search).get('drive');
    if (FLASH[result]) {
        const flash = $('driveFlash');
        flash.classList.add(FLASH[result][0]);
        flash.textContent = FLASH[result][1];
        flash.hidden = false;
        history.replaceState(null, '', location.pathname + location.hash);
    }

    apiFetch(BASE_URL + '/api/drive').then(render).catch(() => show('off'));
})();
