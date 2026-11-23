/* =====================================================
   transfer.js — ส่งไฟล์ ข้อความ หรือลิงก์ข้ามอุปกรณ์ (views/transfer/index.php)

   ส่ง: เลือกไฟล์และ/หรือพิมพ์ข้อความ เลือกเวลาหมดอายุ → /api/transfer/start
        → ส่งไฟล์ทีละชิ้น (chunk) ลองใหม่เองเมื่อเน็ตหลุด → /finish → รหัส 6 หลักกับ QR
   รับ: ใส่รหัสแล้วพาไปหน้ารับไฟล์ /transfer/download/{token} ที่เดียวกับที่ QR พาไป
   ประวัติ: การส่งก่อน ๆ ของบัญชีนี้
   ขนาดรวมสูงสุดมาจากเซิร์ฟเวอร์ (data-max-bytes) ไม่ใช่ตัวเลขในไฟล์นี้
===================================================== */

(function () {
    'use strict';

    /** ลองส่งชิ้นเดิมใหม่ได้กี่ครั้งเมื่อเน็ตหลุดหรือเซิร์ฟเวอร์ตอบ 5xx (EDGE-7) */
    const CHUNK_RETRIES = 3;
    /** รอก่อนลองใหม่ครั้งแรก แล้วเพิ่มเป็นสองเท่าทุกครั้ง: 1, 2, 4 วินาที */
    const RETRY_DELAY_MS = 1000;

    let selectedFiles = [];
    let currentTransfer = null;     // คำตอบของ finish (หรือ start ถ้าส่งแค่ข้อความ)
    let countdownInterval = null;
    let upload = null;              // { id, controller } ระหว่างอัปโหลด

    const panels = document.querySelector('.tf-panels');
    const MAX_BYTES = Number(panels.dataset.maxBytes);

    const $ = id => document.getElementById(id);
    const tabs = Array.from(document.querySelectorAll('#tfTabs .tab'));

    const sendDrop = $('sendDrop');
    const sendInput = $('sendInput');
    const sendFileList = $('sendFileList');
    const sendMessage = $('sendMessage');
    const btnSend = $('btnSend');
    const sendStep1 = $('sendStep1');
    const sendStep2 = $('sendStep2');
    const sendProgress = $('sendProgress');

    const receiveCode = $('receiveCode');
    const btnReceive = $('btnReceive');
    const historyList = $('historyList');

    /* ── Helpers ── */
    function formatSize(bytes) {
        bytes = Number(bytes);
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    }

    function showError(id, message) {
        const line = $(id);
        line.textContent = message;
        line.hidden = message === '';
    }

    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            return false;
        }
    }

    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const totalBytes = () => selectedFiles.reduce((sum, f) => sum + f.size, 0);

    /* ── Tabs ── */
    function selectTab(tab, focus) {
        const name = tab.dataset.tab;
        tabs.forEach(t => {
            t.setAttribute('aria-selected', String(t === tab));
            t.tabIndex = t === tab ? 0 : -1;
        });
        document.querySelectorAll('.tf-panel').forEach(p => { p.hidden = p.dataset.panel !== name; });
        if (focus) tab.focus();
        if (name === 'history') loadHistory();
        if (name === 'receive') receiveCode.focus();
    }

    tabs.forEach((tab, i) => {
        tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
        tab.addEventListener('click', () => selectTab(tab));
        tab.addEventListener('keydown', e => {
            const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
            if (!step) return;
            e.preventDefault();
            selectTab(tabs[(i + step + tabs.length) % tabs.length], true);
        });
    });

    /* ── Choosing what to send ── */
    sendDrop.addEventListener('click', () => sendInput.click());
    sendDrop.addEventListener('dragover', e => { e.preventDefault(); sendDrop.classList.add('drag-over'); });
    sendDrop.addEventListener('dragleave', () => sendDrop.classList.remove('drag-over'));
    sendDrop.addEventListener('drop', e => {
        e.preventDefault();
        sendDrop.classList.remove('drag-over');
        addFiles(e.dataTransfer.files);
    });
    sendInput.addEventListener('change', () => {
        addFiles(sendInput.files);
        sendInput.value = '';
    });
    sendMessage.addEventListener('input', syncSendButton);

    /** เพิ่มไฟล์ที่เลือก (ชื่อและขนาดซ้ำกันถือว่าไฟล์เดียวกัน) */
    function addFiles(fileList) {
        showError('sendError', '');
        for (const file of fileList) {
            if (!selectedFiles.some(f => f.name === file.name && f.size === file.size)) selectedFiles.push(file);
        }
        renderFileList();
    }

    function renderFileList() {
        syncSendButton();
        if (!selectedFiles.length) {
            sendFileList.innerHTML = '';
            return;
        }
        const total = totalBytes();
        sendFileList.innerHTML = '<ul class="ruled-list">' + selectedFiles.map((f, i) =>
            '<li class="ruled-row"><span class="grow"><span class="title">' + escHtml(f.name) + '</span>'
            + '<span class="meta">' + formatSize(f.size) + '</span></span>'
            + '<button type="button" class="icon-btn sm" data-index="' + i + '" aria-label="เอาออก: ' + escHtml(f.name) + '"><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></button></li>'
        ).join('') + '</ul><p class="tf-files-summary">' + selectedFiles.length + ' ไฟล์ · รวม ' + formatSize(total)
            + (total > MAX_BYTES ? ' · <strong class="tf-over">เกิน 10 GB เอาบางไฟล์ออกก่อนส่ง</strong>' : '') + '</p>';
    }

    /** ปุ่มส่งกดได้เมื่อมีไฟล์หรือข้อความ และไฟล์รวมไม่เกิน 10 GB (EDGE-6, EDGE-9) */
    function syncSendButton() {
        const hasSomething = selectedFiles.length > 0 || sendMessage.value.trim() !== '';
        btnSend.disabled = !hasSomething || totalBytes() > MAX_BYTES;
    }

    sendFileList.addEventListener('click', e => {
        const btn = e.target.closest('[data-index]');
        if (!btn) return;
        selectedFiles.splice(Number(btn.dataset.index), 1);
        renderFileList();
    });

    /* ── Sending ── */
    btnSend.addEventListener('click', async () => {
        showError('sendError', '');
        const expiry = Number(document.querySelector('input[name="sendExpiry"]:checked')?.value || 5);
        btnSend.disabled = true;
        try {
            const started = await apiFetch(BASE_URL + '/api/transfer/start', {
                method: 'POST',
                body: JSON.stringify({
                    files: selectedFiles.map(f => ({ name: f.name, size: f.size })),
                    message: sendMessage.value,
                    expiry_minutes: expiry,
                }),
            });
            // ส่งแค่ข้อความ: ได้รหัสทันที ไม่มีอะไรต้องอัปโหลด
            showCodeStep(started.code ? started : await uploadFiles(started));
        } catch (err) {
            showError('sendError', err.name === 'AbortError' ? 'ยกเลิกการส่งแล้ว ไฟล์ที่ส่งไปบางส่วนถูกลบทิ้ง' : (err.message || 'ส่งไม่สำเร็จ ลองอีกครั้ง'));
            showStep('form');
        } finally {
            upload = null;
            syncSendButton();
        }
    });

    /**
     * อัปโหลดทุกไฟล์ทีละชิ้นตามลำดับ แล้วปิดการส่ง
     * @param {{id: number, chunk_bytes: number}} started คำตอบของ /start
     * @returns {Promise<object>} คำตอบของ /finish (รหัส ลิงก์ เวลาที่เหลือ)
     * @throws Error ข้อความภาษาไทยเมื่อส่งไม่สำเร็จ การส่งที่ค้างถูกลบทิ้งก่อนโยน
     */
    async function uploadFiles(started) {
        upload = { id: started.id, controller: new AbortController() };
        const total = totalBytes();
        let sent = 0;
        showStep('progress');
        showProgress(0, total);
        try {
            for (const [index, file] of selectedFiles.entries()) {
                for (let offset = 0; offset < file.size; offset += started.chunk_bytes) {
                    const chunk = file.slice(offset, offset + started.chunk_bytes);
                    await sendChunk(started.id, index, offset, chunk, upload.controller.signal);
                    sent += chunk.size;
                    showProgress(sent, total);
                }
            }
            return await apiFetch(BASE_URL + '/api/transfer/' + started.id + '/finish', { method: 'POST', body: '{}' });
        } catch (err) {
            // ไม่ทิ้งชิ้นที่ส่งไปแล้วค้างบนเซิร์ฟเวอร์ (cron ก็ลบให้ภายในชั่วโมงอยู่แล้วถ้าตรงนี้ล้ม)
            apiFetch(BASE_URL + '/api/transfer/' + started.id, { method: 'DELETE' }).catch(() => {});
            throw err;
        }
    }

    /**
     * ส่งชิ้นหนึ่งไปที่ตำแหน่ง offset ของไฟล์ ลองใหม่เมื่อเน็ตหลุดหรือเซิร์ฟเวอร์ล่ม
     * ส่งชิ้นเดิมซ้ำได้ปลอดภัย เพราะเซิร์ฟเวอร์เขียนทับไบต์เดิมที่ offset เดิม
     * คำขอที่ผิดเอง (4xx) ไม่ลองใหม่ เพราะส่งกี่ครั้งก็ได้ผลเดิม
     */
    async function sendChunk(id, fileIndex, offset, chunk, signal) {
        for (let attempt = 0; ; attempt++) {
            try {
                await apiFetch(BASE_URL + '/api/transfer/' + id + '/chunk?file=' + fileIndex + '&offset=' + offset, {
                    method: 'POST',
                    body: chunk,
                    headers: { 'Content-Type': 'application/octet-stream' },
                    signal,
                });
                return;
            } catch (err) {
                const retryable = err.status === undefined || err.status >= 500;
                if (signal.aborted || !retryable) throw err;
                if (attempt >= CHUNK_RETRIES) throw new Error('เน็ตหลุดระหว่างส่ง ลองใหม่ ' + CHUNK_RETRIES + ' ครั้งแล้วไม่สำเร็จ กดส่งอีกครั้งเมื่อเน็ตกลับมา');
                $('sendProgressNote').textContent = 'เน็ตสะดุด กำลังลองส่งชิ้นนี้ใหม่ (ครั้งที่ ' + (attempt + 1) + ' จาก ' + CHUNK_RETRIES + ')';
                await sleep(RETRY_DELAY_MS * 2 ** attempt);
            }
        }
    }

    function showProgress(sent, total) {
        const percent = total > 0 ? Math.floor(sent / total * 100) : 100;
        $('sendProgressFill').style.width = percent + '%';
        $('sendProgressBar').setAttribute('aria-valuenow', String(percent));
        $('sendProgressLabel').textContent = 'กำลังส่ง ' + formatSize(sent) + ' จาก ' + formatSize(total) + ' (' + percent + '%)';
        $('sendProgressNote').textContent = 'อย่าปิดหน้านี้จนกว่าจะได้รหัส';
    }

    /** สลับระหว่างฟอร์ม แถบความคืบหน้า และหน้ารหัส */
    function showStep(step) {
        sendStep1.hidden = step !== 'form';
        sendProgress.hidden = step !== 'progress';
        sendStep2.hidden = step !== 'code';
    }

    $('btnAbortSend').addEventListener('click', () => upload?.controller.abort());

    // ปิดแท็บระหว่างอัปโหลด เบราว์เซอร์จะถามก่อน ไฟล์ใหญ่ส่งใหม่ใช้เวลานาน
    window.addEventListener('beforeunload', event => {
        if (upload) event.preventDefault();
    });

    function resetToStepOne() {
        showStep('form');
        selectedFiles = [];
        sendMessage.value = '';
        renderFileList();
        currentTransfer = null;
        if (countdownInterval) clearInterval(countdownInterval);
    }

    $('btnNewTransfer').addEventListener('click', resetToStepOne);

    $('btnCancelTransfer').addEventListener('click', async () => {
        if (!currentTransfer || !currentTransfer.id) return;
        if (!await confirmAction('ไฟล์และข้อความจะถูกลบทันที และรหัสนี้จะใช้ไม่ได้อีก', 'ยกเลิกการส่ง', 'ยกเลิกการส่งนี้?')) return;
        try {
            await apiFetch(BASE_URL + '/api/transfer/' + currentTransfer.id, { method: 'DELETE' });
            toast('ยกเลิกการส่งแล้ว');
            resetToStepOne();
        } catch (err) {
            toast(err.message || 'ยกเลิกไม่สำเร็จ ลองอีกครั้ง', 'danger');
        }
    });

    function showCodeStep(data) {
        currentTransfer = data;
        showStep('code');
        $('codeDigits').textContent = data.code;
        $('codeFilesSummary').textContent = (data.files.length ? data.files.length + ' ไฟล์ · ' + formatSize(data.total_size) : 'ข้อความ')
            + (data.files.length && data.message ? ' · พร้อมข้อความ' : '');
        generateQR(data.download_url);
        startCountdown(data.expires_in);
    }

    /** นับถอยหลังจากวินาทีที่เซิร์ฟเวอร์ให้มา ไม่ต้องเกี่ยวกับเขตเวลา */
    function startCountdown(expiresIn) {
        if (countdownInterval) clearInterval(countdownInterval);
        const expires = Date.now() + Number(expiresIn || 0) * 1000;
        const started = Date.now();
        const span = Math.max(1, expires - started);
        const bar = $('codeBar');
        const timer = $('codeTimer');

        function update() {
            const remaining = Math.max(0, expires - Date.now());
            bar.style.width = (remaining / span * 100) + '%';
            const mins = Math.floor(remaining / 60000);
            const secs = Math.floor((remaining % 60000) / 1000);
            timer.textContent = remaining > 0 ? 'เหลือ ' + mins + ':' + String(secs).padStart(2, '0') + ' นาที' : 'หมดอายุแล้ว';
            timer.classList.toggle('tf-urgent', remaining < 60000);
            if (remaining <= 0) clearInterval(countdownInterval);
        }
        update();
        countdownInterval = setInterval(update, 1000);
    }

    function generateQR(url) {
        const wrap = $('qrWrap');
        wrap.innerHTML = '';
        if (typeof qrcode === 'undefined') {
            wrap.innerHTML = '<p class="tf-qr-label">สร้าง QR Code ไม่ได้ ใช้รหัสหรือลิงก์แทน</p>';
            return;
        }
        try {
            const qr = qrcode(0, 'M');
            qr.addData(url);
            qr.make();
            wrap.innerHTML = qr.createSvgTag(5, 0);
        } catch {
            wrap.innerHTML = '<p class="tf-qr-label">สร้าง QR Code ไม่ได้ ใช้รหัสหรือลิงก์แทน</p>';
        }
    }

    $('btnCopyCode').addEventListener('click', async () => {
        if (!currentTransfer) return;
        toast(await copyToClipboard(currentTransfer.code) ? 'คัดลอกรหัสแล้ว' : 'คัดลอกไม่ได้ ลองเลือกรหัสแล้วคัดลอกเอง', 'success');
    });
    $('btnCopyLink').addEventListener('click', async () => {
        if (!currentTransfer) return;
        toast(await copyToClipboard(currentTransfer.download_url) ? 'คัดลอกลิงก์แล้ว' : 'คัดลอกไม่ได้ ลองอีกครั้ง', 'success');
    });

    /* ── Receiving: หารหัสแล้วไปหน้ารับไฟล์ หน้าเดียวกับที่ QR และลิงก์พาไป ── */
    receiveCode.addEventListener('input', e => {
        e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
        btnReceive.disabled = e.target.value.length !== 6;
    });

    $('receiveForm').addEventListener('submit', async e => {
        e.preventDefault();
        const code = receiveCode.value.trim();
        if (code.length !== 6) return;

        showError('receiveError', '');
        btnReceive.disabled = true;
        btnReceive.textContent = 'กำลังค้นหา…';
        try {
            const data = await apiFetch(BASE_URL + '/api/transfer/receive', { method: 'POST', body: JSON.stringify({ code }) });
            window.location.href = data.download_url;
        } catch (err) {
            showError('receiveError', err.message || 'ไม่พบรหัสนี้ ตรวจรหัสกับผู้ส่งอีกครั้ง');
            btnReceive.textContent = 'ค้นหาไฟล์';
            btnReceive.disabled = receiveCode.value.length !== 6;
        }
    });

    /* ── History ── */
    async function loadHistory() {
        try {
            const data = await apiFetch(BASE_URL + '/api/transfer');
            historyList.removeAttribute('aria-busy');
            renderHistory(data.transfers || []);
        } catch {
            historyList.removeAttribute('aria-busy');
            historyList.innerHTML = '<div class="alert alert-danger" role="alert">โหลดประวัติไม่สำเร็จ '
                + '<button type="button" class="btn btn-sm" data-history-retry>ลองอีกครั้ง</button></div>';
        }
    }

    function renderHistory(transfers) {
        if (!transfers.length) {
            historyList.innerHTML = '<div class="empty-state"><p class="empty-state-title">ยังไม่มีประวัติการส่ง</p>'
                + '<p class="empty-state-text">ไฟล์และข้อความที่ส่งแล้วจะแสดงรหัส ขนาด และจำนวนครั้งที่ถูกดาวน์โหลดที่นี่</p></div>';
            return;
        }

        historyList.innerHTML = '<ul class="ruled-list">' + transfers.map(t => {
            const files = JSON.parse(t.files_json || '[]');
            const names = files.map(f => f.name).join(', ');
            const what = files.length ? files.length + ' ไฟล์ · ' + formatSize(t.total_size) : 'ข้อความ: ' + (t.message || '').slice(0, 40);
            const expired = !!t.is_expired;
            return '<li class="ruled-row tf-history-row' + (expired ? ' is-expired' : '') + '">'
                + '<span class="tf-history-code">' + escHtml(t.code) + '</span>'
                + '<span class="grow"><span class="title" title="' + escHtml(names) + '">' + escHtml(what) + '</span>'
                + '<span class="meta">ดาวน์โหลด ' + t.download_count + ' ครั้ง · หมดอายุ ' + escHtml(formatDateTime(t.expires_at)) + '</span></span>'
                + '<span class="badge ' + (expired ? 'badge-gray' : 'badge-success') + '">' + (expired ? 'หมดอายุแล้ว' : 'ใช้งานได้') + '</span>'
                + (expired ? '' : '<button type="button" class="icon-btn sm" data-copy-code="' + escHtml(t.code) + '" aria-label="คัดลอกรหัส ' + escHtml(t.code) + '"><svg class="icon" aria-hidden="true"><use href="#i-all"/></svg></button>')
                + '<button type="button" class="icon-btn sm" data-delete="' + t.id + '" aria-label="ลบรายการรหัส ' + escHtml(t.code) + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
                + '</li>';
        }).join('') + '</ul>';
    }

    historyList.addEventListener('click', async e => {
        if (e.target.closest('[data-history-retry]')) { loadHistory(); return; }

        const copy = e.target.closest('[data-copy-code]');
        if (copy) {
            toast(await copyToClipboard(copy.dataset.copyCode) ? 'คัดลอกรหัส ' + copy.dataset.copyCode + ' แล้ว' : 'คัดลอกไม่ได้ ลองอีกครั้ง');
            return;
        }

        const del = e.target.closest('[data-delete]');
        if (!del) return;
        if (!await confirmAction('ไฟล์และข้อความของรายการนี้จะถูกลบถาวร', 'ลบรายการ', 'ลบรายการนี้?')) return;
        try {
            await apiFetch(BASE_URL + '/api/transfer/' + del.dataset.delete, { method: 'DELETE' });
            toast('ลบรายการแล้ว');
            loadHistory();
        } catch (err) {
            toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
        }
    });
})();
