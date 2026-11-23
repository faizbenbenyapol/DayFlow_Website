// =====================================================
// settings-install.js — ปุ่มติดตั้งแอปในหน้าตั้งค่า > เกี่ยวกับแอป (REQ-15)
// เลือกแสดงหนึ่งในสี่แบบของ #installBox: ติดตั้งแล้ว, iPhone/iPad, ปุ่มติดตั้ง, เบราว์เซอร์ที่ติดตั้งไม่ได้
// โหลดต่อจาก settings.js เป็น classic script
// =====================================================
(function () {
    'use strict';

    const box = document.getElementById('installBox');
    if (!box) return;

    // event ที่ Chrome, Edge และ Android ส่งมาเมื่อติดตั้งได้ เก็บไว้เรียก prompt() ตอนผู้ใช้กดปุ่ม (ใช้ได้ครั้งเดียว)
    let installEvent = null;

    /** แสดงเฉพาะแบบที่ระบุ ซ่อนแบบอื่น @param {'installed'|'ios'|'prompt'|'unsupported'} state */
    function show(state) {
        box.querySelectorAll('[data-install]').forEach(part => { part.hidden = part.dataset.install !== state; });
    }

    /** เปิดจากไอคอนที่ติดตั้งแล้ว: Chrome/Edge ใช้ display-mode ส่วน Safari บน iOS ใช้ navigator.standalone */
    function runsInstalled() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    /** iPhone, iPad และ iPad ที่บอกตัวเองว่าเป็น Mac (iPadOS 13 ขึ้นไป) ซึ่งติดตั้งผ่านปุ่มแชร์ของ Safari เท่านั้น */
    function isAppleMobile() {
        return /iPhone|iPad|iPod/.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault(); // ไม่ให้แถบติดตั้งของเบราว์เซอร์เด้งเอง ให้ผู้ใช้กดจากปุ่มของเรา
        installEvent = event;
        // บน iPhone/iPad ทุกเบราว์เซอร์ติดตั้งผ่านปุ่มแชร์ของ Safari เท่านั้น วิธีกดจึงชนะปุ่มเสมอ
        if (!runsInstalled() && !isAppleMobile()) show('prompt');
    });
    window.addEventListener('appinstalled', () => {
        installEvent = null;
        show('installed');
    });

    document.getElementById('btnInstallApp')?.addEventListener('click', async () => {
        if (!installEvent) return;
        const pending = installEvent;
        installEvent = null;
        await pending.prompt();
        const { outcome } = await pending.userChoice;
        // ยกเลิกแล้ว event นี้ใช้ซ้ำไม่ได้ ซ่อนปุ่มไว้จนกว่าเบราว์เซอร์จะส่ง event ใหม่มา
        show(outcome === 'accepted' ? 'installed' : 'unsupported');
    });

    // แบบเริ่มต้น: ปุ่มติดตั้งจะขึ้นเองเมื่อ beforeinstallprompt มาถึง
    if (runsInstalled()) show('installed');
    else if (isAppleMobile()) show('ios');
    else show('unsupported');
})();
