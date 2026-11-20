// =====================================================
// transfer-receive.js — ปุ่มคัดลอกในหน้ารับไฟล์สาธารณะ (views/transfer/receive.php)
// ปุ่มที่มี data-copy-from="<id>" คัดลอกข้อความของ element นั้น แล้วบอกผลบนปุ่มเอง
// (หน้านอกแอปไม่มี toast ของแอป)
// =====================================================
(function () {
    'use strict';

    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-copy-from]');
        if (!button) return;
        const source = document.getElementById(button.dataset.copyFrom);
        if (!source) return;
        const label = button.textContent;
        try {
            await navigator.clipboard.writeText(source.innerText);
            button.textContent = 'คัดลอกแล้ว';
        } catch {
            button.textContent = 'คัดลอกไม่ได้ เลือกข้อความแล้วคัดลอกเอง';
        }
        setTimeout(() => { button.textContent = label; }, 2000);
    });
})();
