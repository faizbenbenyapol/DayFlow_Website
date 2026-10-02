/* =====================================================
   transfer.js — send a file to another device

   Send: pick files, upload, get a six-digit code and a QR code that last ten
   minutes. Receive: type the code, download. History: earlier transfers.
   The size limit comes from the server (data-max-bytes), not from this file.
===================================================== */

(function () {
    'use strict';

    let selectedFiles = [];
    let currentTransfer = null;     // { id, code, download_url, expires_at, ... }
    let countdownInterval = null;

    const panels = document.querySelector('.tf-panels');
    const MAX_BYTES = Number(panels.dataset.maxBytes) || 20 * 1024 * 1024;
    const MAX_LABEL = panels.dataset.maxLabel || '20 MB';

    const $ = id => document.getElementById(id);
    const tabs = Array.from(document.querySelectorAll('#tfTabs .tab'));

    const sendDrop = $('sendDrop');
    const sendInput = $('sendInput');
    const sendFileList = $('sendFileList');
    const sendActions = $('sendActions');
    const btnSend = $('btnSend');
    const sendStep1 = $('sendStep1');
    const sendStep2 = $('sendStep2');

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

    /* ── Choosing files ── */
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

    function addFiles(fileList) {
        showError('sendError', '');
        const tooBig = [];
        for (const file of fileList) {
            if (file.size > MAX_BYTES) { tooBig.push(file.name); continue; }
            if (!selectedFiles.some(f => f.name === file.name && f.size === file.size)) selectedFiles.push(file);
        }
        if (tooBig.length) showError('sendError', 'ไฟล์เหล่านี้ใหญ่เกิน ' + MAX_LABEL + ' จึงไม่ถูกเพิ่ม: ' + tooBig.join(', '));
        renderFileList();
    }

    function renderFileList() {
        if (!selectedFiles.length) {
            sendFileList.innerHTML = '';
            sendActions.hidden = true;
            return;
        }
        sendActions.hidden = false;

        const total = selectedFiles.reduce((sum, f) => sum + f.size, 0);
        sendFileList.innerHTML = '<ul class="ruled-list">' + selectedFiles.map((f, i) =>
            '<li class="ruled-row"><span class="grow"><span class="title">' + escHtml(f.name) + '</span>'
            + '<span class="meta">' + formatSize(f.size) + '</span></span>'
            + '<button type="button" class="icon-btn sm" data-index="' + i + '" aria-label="เอาออก: ' + escHtml(f.name) + '"><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></button></li>'
        ).join('') + '</ul><p class="tf-files-summary">' + selectedFiles.length + ' ไฟล์ · รวม ' + formatSize(total) + '</p>';
    }

    sendFileList.addEventListener('click', e => {
        const btn = e.target.closest('[data-index]');
        if (!btn) return;
        selectedFiles.splice(Number(btn.dataset.index), 1);
        renderFileList();
    });

    /* ── Sending ── */
    btnSend.addEventListener('click', async () => {
        if (!selectedFiles.length) return;
        showError('sendError', '');

        const formData = new FormData();
        selectedFiles.forEach(f => formData.append('files[]', f));
        formData.append('expiry', '10');

        btnSend.disabled = true;
        btnSend.textContent = 'กำลังอัปโหลด…';
        try {
            currentTransfer = await apiFetch(BASE_URL + '/api/transfer/send', { method: 'POST', body: formData });
            showCodeStep(currentTransfer);
            toast('ส่งไฟล์แล้ว แชร์รหัสให้ผู้รับ');
        } catch (err) {
            showError('sendError', err.message || 'ส่งไฟล์ไม่สำเร็จ ลองอีกครั้ง');
        } finally {
            btnSend.disabled = false;
            btnSend.textContent = 'ส่งไฟล์และรับรหัส';
        }
    });

    function resetToStepOne() {
        sendStep1.hidden = false;
        sendStep2.hidden = true;
        selectedFiles = [];
        renderFileList();
        currentTransfer = null;
        if (countdownInterval) clearInterval(countdownInterval);
    }

    $('btnNewTransfer').addEventListener('click', resetToStepOne);

    $('btnCancelTransfer').addEventListener('click', async () => {
        if (!currentTransfer || !currentTransfer.id) return;
        if (!await confirmAction('ไฟล์ทั้งหมดจะถูกลบทันที และรหัสนี้จะใช้ไม่ได้อีก', 'ยกเลิกการส่ง', 'ยกเลิกการส่งนี้?')) return;
        try {
            await apiFetch(BASE_URL + '/api/transfer/' + currentTransfer.id, { method: 'DELETE' });
            toast('ยกเลิกการส่งแล้ว');
            resetToStepOne();
        } catch (err) {
            toast(err.message || 'ยกเลิกไม่สำเร็จ ลองอีกครั้ง', 'danger');
        }
    });

    function showCodeStep(data) {
        sendStep1.hidden = true;
        sendStep2.hidden = false;
        $('codeDigits').textContent = data.code;
        $('codeFilesSummary').textContent = data.files_count + ' ไฟล์ · ' + formatSize(data.total_size);
        generateQR(data.download_url);
        startCountdown(data.expires_at);
    }

    function startCountdown(expiresAt) {
        if (countdownInterval) clearInterval(countdownInterval);
        const expires = new Date(expiresAt).getTime();
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

    /* ── Receiving ── */
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
            showReceiveResult(data);
        } catch (err) {
            $('receiveResult').hidden = true;
            showError('receiveError', err.message || 'ไม่พบรหัสนี้ ตรวจรหัสกับผู้ส่ง รหัสหมดอายุใน 10 นาที');
        } finally {
            btnReceive.textContent = 'ค้นหาไฟล์';
            btnReceive.disabled = receiveCode.value.length !== 6;
        }
    });

    function showReceiveResult(data) {
        $('receiveResult').hidden = false;
        $('receiveFiles').innerHTML = data.files.map(f =>
            '<li class="ruled-row"><span class="grow"><span class="title">' + escHtml(f.name) + '</span></span>'
            + '<span class="side">' + formatSize(f.size) + '</span></li>'
        ).join('');
        $('receiveMeta').textContent = data.files_count + ' ไฟล์ · รวม ' + formatSize(data.total_size);
        $('receiveDownloadBtn').href = data.download_url;
    }

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
                + '<p class="empty-state-text">ไฟล์ที่ส่งแล้วจะแสดงรหัส ขนาด และจำนวนครั้งที่ถูกดาวน์โหลดที่นี่</p></div>';
            return;
        }

        historyList.innerHTML = '<ul class="ruled-list">' + transfers.map(t => {
            const files = JSON.parse(t.files_json || '[]');
            const names = files.map(f => f.name).join(', ');
            const expired = !!t.is_expired;
            return '<li class="ruled-row tf-history-row' + (expired ? ' is-expired' : '') + '">'
                + '<span class="tf-history-code">' + escHtml(t.code) + '</span>'
                + '<span class="grow"><span class="title" title="' + escHtml(names) + '">' + files.length + ' ไฟล์ · ' + formatSize(t.total_size) + '</span>'
                + '<span class="meta">ดาวน์โหลด ' + t.download_count + ' ครั้ง · ' + (expired ? 'หมดอายุ ' : 'หมดอายุ ') + escHtml(formatDateTime(t.expires_at)) + '</span></span>'
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
        if (!await confirmAction('ไฟล์ของรายการนี้จะถูกลบถาวร', 'ลบรายการ', 'ลบรายการนี้?')) return;
        try {
            await apiFetch(BASE_URL + '/api/transfer/' + del.dataset.delete, { method: 'DELETE' });
            toast('ลบรายการแล้ว');
            loadHistory();
        } catch (err) {
            toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
        }
    });
})();
