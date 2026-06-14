/* =====================================================
   transfer.js — File Transfer (Send-Anywhere style)
===================================================== */

(function () {
    'use strict';

    // ─── State ───
    let selectedFiles = [];
    let currentTransfer = null; // { code, token, download_url, expires_at }
    let countdownInterval = null;

    // ─── DOM refs ───
    const tabs = document.querySelectorAll('.tf-tab');
    const panels = document.querySelectorAll('.tf-panel');

    // Send
    const sendDrop = document.getElementById('sendDrop');
    const sendInput = document.getElementById('sendInput');
    const sendFileList = document.getElementById('sendFileList');
    const sendOptions = document.getElementById('sendOptions');
    const sendActions = document.getElementById('sendActions');
    const btnSend = document.getElementById('btnSend');
    const btnCancelTransfer = document.getElementById('btnCancelTransfer');
    const sendStep1 = document.getElementById('sendStep1');
    const sendStep2 = document.getElementById('sendStep2');
    const uploadOverlay = document.getElementById('uploadOverlay');

    // Code display
    const codeDigits = document.getElementById('codeDigits');
    const codeTimer = document.getElementById('codeTimer');
    const countdownCircle = document.getElementById('countdownCircle');
    const codeFilesSummary = document.getElementById('codeFilesSummary');
    const qrWrap = document.getElementById('qrWrap');

    // Receive
    const receiveCode = document.getElementById('receiveCode');
    const btnReceive = document.getElementById('btnReceive');
    const receiveResult = document.getElementById('receiveResult');
    const receiveFiles = document.getElementById('receiveFiles');
    const receiveMeta = document.getElementById('receiveMeta');
    const receiveDownloadBtn = document.getElementById('receiveDownloadBtn');

    // History
    const historyList = document.getElementById('historyList');

    // ─── Inject SVG gradient for countdown ───
    const svgNS = 'http://www.w3.org/2000/svg';
    const countdownSvg = document.querySelector('.tf-countdown-svg');
    if (countdownSvg) {
        const defs = document.createElementNS(svgNS, 'defs');
        const grad = document.createElementNS(svgNS, 'linearGradient');
        grad.id = 'countdownGrad';
        grad.setAttribute('x1', '0%');
        grad.setAttribute('y1', '0%');
        grad.setAttribute('x2', '100%');
        grad.setAttribute('y2', '100%');
        const stop1 = document.createElementNS(svgNS, 'stop');
        stop1.setAttribute('offset', '0%');
        stop1.setAttribute('stop-color', '#6366f1');
        const stop2 = document.createElementNS(svgNS, 'stop');
        stop2.setAttribute('offset', '100%');
        stop2.setAttribute('stop-color', '#8b5cf6');
        grad.appendChild(stop1);
        grad.appendChild(stop2);
        defs.appendChild(grad);
        countdownSvg.insertBefore(defs, countdownSvg.firstChild);
    }

    // ─── Tab switching ───
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;
            tabs.forEach(t => t.classList.remove('active'));
            panels.forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            const panel = document.querySelector(`.tf-panel[data-panel="${target}"]`);
            if (panel) panel.classList.add('active');

            if (target === 'history') loadHistory();
            if (target === 'receive') {
                receiveCode.focus();
            }
        });
    });

    // ─── Drag & Drop ───
    if (sendDrop) {
        sendDrop.addEventListener('click', (e) => {
            if (e.target.tagName !== 'LABEL' && e.target.tagName !== 'INPUT') {
                sendInput.click();
            }
        });

        sendDrop.addEventListener('dragover', (e) => {
            e.preventDefault();
            sendDrop.classList.add('drag-over');
        });

        sendDrop.addEventListener('dragleave', () => {
            sendDrop.classList.remove('drag-over');
        });

        sendDrop.addEventListener('drop', (e) => {
            e.preventDefault();
            sendDrop.classList.remove('drag-over');
            addFiles(e.dataTransfer.files);
        });

        sendInput.addEventListener('change', () => {
            addFiles(sendInput.files);
            sendInput.value = '';
        });
    }

    function addFiles(fileList) {
        const MAX_SIZE = 2 * 1024 * 1024 * 1024; // 2 GB
        for (const file of fileList) {
            if (file.size > MAX_SIZE) {
                toast(`ไฟล์ "${file.name}" ขนาดเกิน 1 GB`, 'error');
                continue;
            }
            // Check for duplicates
            const exists = selectedFiles.some(f => f.name === file.name && f.size === file.size);
            if (!exists) {
                selectedFiles.push(file);
            }
        }
        renderFileList();
    }

    function removeFile(index) {
        selectedFiles.splice(index, 1);
        renderFileList();
    }

    function renderFileList() {
        if (!sendFileList) return;

        if (selectedFiles.length === 0) {
            sendFileList.innerHTML = '';
            sendOptions.style.display = 'none';
            sendActions.style.display = 'none';
            return;
        }

        sendOptions.style.display = 'block';
        sendActions.style.display = 'block';

        let totalSize = selectedFiles.reduce((sum, f) => sum + f.size, 0);

        let html = selectedFiles.map((f, i) => `
            <div class="tf-file-item">
                <div class="tf-file-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div class="tf-file-info">
                    <div class="tf-file-name">${escapeHtml(f.name)}</div>
                    <div class="tf-file-size">${formatSize(f.size)}</div>
                </div>
                <button class="tf-file-remove" onclick="window._tfRemoveFile(${i})" title="ลบ">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        `).join('');

        html += `
            <div class="tf-file-total">
                <span>${selectedFiles.length} ไฟล์</span>
                <span>รวม ${formatSize(totalSize)}</span>
            </div>
        `;

        sendFileList.innerHTML = html;
    }

    // Expose remove function globally
    window._tfRemoveFile = removeFile;

    // ─── Send ───
    if (btnSend) {
        btnSend.addEventListener('click', async () => {
            if (selectedFiles.length === 0) return;

            const formData = new FormData();
            selectedFiles.forEach(f => formData.append('files[]', f));
            formData.append('expiry', '10');

            // Add CSRF
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) formData.append('_csrf_token', csrfMeta.content);

            uploadOverlay.style.display = 'flex';

            try {
                const data = await apiFetch(BASE_URL + '/api/transfer/send', {
                    method: 'POST',
                    body: formData,
                });

                currentTransfer = data;
                showCodeStep(data);
                toast('ส่งไฟล์สำเร็จ! แชร์รหัสให้ผู้รับ', 'success');
            } catch (err) {
                toast(err.message || 'เกิดข้อผิดพลาด', 'error');
            } finally {
                uploadOverlay.style.display = 'none';
            }
        });
    }

    if (btnCancelTransfer) {
        btnCancelTransfer.addEventListener('click', async () => {
            if (!currentTransfer || !currentTransfer.id) return;

            const ok = await confirmAction('ยกเลิกการส่งนี้และลบไฟล์ทั้งหมดทันที?', 'ยกเลิกการส่ง');
            if (!ok) return;

            try {
                await apiFetch(BASE_URL + '/api/transfer/' + currentTransfer.id, {
                    method: 'DELETE'
                });
                toast('ยกเลิกการส่งเรียบร้อยแล้ว', 'success');
                
                // Back to step 1
                sendStep1.style.display = 'block';
                sendStep2.style.display = 'none';
                selectedFiles = [];
                renderFileList();
                currentTransfer = null;
                if (countdownInterval) clearInterval(countdownInterval);
            } catch (err) {
                toast(err.message || 'ไม่สามารถยกเลิกการส่งได้', 'error');
            }
        });
    }

    function showCodeStep(data) {
        sendStep1.style.display = 'none';
        sendStep2.style.display = 'block';

        // Display code
        codeDigits.textContent = data.code;

        // Files summary
        const totalSizeStr = formatSize(data.total_size);
        codeFilesSummary.textContent = `${data.files_count} ไฟล์ · ${totalSizeStr}`;

        // Generate QR Code
        generateQR(data.download_url);

        // Start countdown
        startCountdown(data.expires_at);
    }

    // ─── Countdown Timer ───
    function startCountdown(expiresAt) {
        if (countdownInterval) clearInterval(countdownInterval);

        const expiresTime = new Date(expiresAt).getTime();
        const startTime = Date.now();
        const totalDuration = expiresTime - startTime;
        const circumference = 2 * Math.PI * 90; // radius 90

        function update() {
            const now = Date.now();
            const remaining = Math.max(0, expiresTime - now);
            const elapsed = now - startTime;
            const progress = Math.min(1, elapsed / totalDuration);

            // Update countdown circle
            if (countdownCircle) {
                countdownCircle.style.strokeDashoffset = (progress * circumference).toString();
            }

            // Update timer text
            const mins = Math.floor(remaining / 60000);
            const secs = Math.floor((remaining % 60000) / 1000);
            const timeStr = `${mins}:${secs.toString().padStart(2, '0')}`;
            codeTimer.textContent = `เหลือ ${timeStr}`;

            // Add urgency styling when < 1 minute
            if (remaining < 60000) {
                codeTimer.classList.add('tf-urgent');
            } else {
                codeTimer.classList.remove('tf-urgent');
            }

            if (remaining <= 0) {
                clearInterval(countdownInterval);
                codeTimer.textContent = 'หมดอายุแล้ว';
                codeTimer.classList.add('tf-urgent');
            }
        }

        update();
        countdownInterval = setInterval(update, 1000);
    }

    // ─── QR Code ───
    function generateQR(url) {
        if (!qrWrap) return;
        qrWrap.innerHTML = '';

        if (typeof qrcode === 'undefined') {
            qrWrap.innerHTML = '<div style="font-size:.8rem;color:var(--color-muted)">QR Code library ไม่พร้อมใช้งาน</div>';
            return;
        }

        try {
            const qr = qrcode(0, 'M');
            qr.addData(url);
            qr.make();
            qrWrap.innerHTML = qr.createSvgTag(5, 0);
        } catch (e) {
            qrWrap.innerHTML = '<div style="font-size:.8rem;color:var(--color-muted)">ไม่สามารถสร้าง QR Code</div>';
        }
    }

    // ─── Copy buttons ───
    const btnCopyCode = document.getElementById('btnCopyCode');
    const btnCopyLink = document.getElementById('btnCopyLink');

    if (btnCopyCode) {
        btnCopyCode.addEventListener('click', () => {
            if (currentTransfer) {
                copyToClipboard(currentTransfer.code);
                toast('คัดลอกรหัสแล้ว', 'success');
            }
        });
    }

    if (btnCopyLink) {
        btnCopyLink.addEventListener('click', () => {
            if (currentTransfer) {
                copyToClipboard(currentTransfer.download_url);
                toast('คัดลอกลิงก์แล้ว', 'success');
            }
        });
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }
    }

    // ─── New Transfer ───
    const btnNewTransfer = document.getElementById('btnNewTransfer');
    if (btnNewTransfer) {
        btnNewTransfer.addEventListener('click', () => {
            sendStep1.style.display = 'block';
            sendStep2.style.display = 'none';
            selectedFiles = [];
            renderFileList();
            currentTransfer = null;
            if (countdownInterval) clearInterval(countdownInterval);
        });
    }

    // ─── Receive ───
    if (receiveCode) {
        receiveCode.addEventListener('input', (e) => {
            // Allow only digits
            e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
            btnReceive.disabled = e.target.value.length !== 6;
        });

        receiveCode.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && receiveCode.value.length === 6) {
                btnReceive.click();
            }
        });
    }

    if (btnReceive) {
        btnReceive.addEventListener('click', async () => {
            const code = receiveCode.value.trim();
            if (code.length !== 6) return;

            btnReceive.disabled = true;
            btnReceive.innerHTML = '<span class="tf-spinner" style="width:18px;height:18px;border-width:2px;margin:0"></span> กำลังค้นหา...';

            try {
                const data = await apiFetch(BASE_URL + '/api/transfer/receive', {
                    method: 'POST',
                    body: JSON.stringify({ code }),
                });

                showReceiveResult(data);
            } catch (err) {
                toast(err.message || 'ไม่พบรหัสนี้', 'error');
                receiveResult.style.display = 'none';
            } finally {
                btnReceive.disabled = false;
                btnReceive.innerHTML = `
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    รับไฟล์
                `;
                btnReceive.disabled = receiveCode.value.length !== 6;
            }
        });
    }

    function showReceiveResult(data) {
        receiveResult.style.display = 'block';

        // File list
        receiveFiles.innerHTML = data.files.map(f => `
            <div class="tf-result-file">
                <div class="tf-result-file-icon">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div class="tf-result-file-name">${escapeHtml(f.name)}</div>
                <div class="tf-result-file-size">${formatSize(f.size)}</div>
            </div>
        `).join('');

        // Meta
        receiveMeta.textContent = `${data.files_count} ไฟล์ · รวม ${formatSize(data.total_size)}`;

        // Download button
        receiveDownloadBtn.href = data.download_url;
    }

    // ─── History ───
    async function loadHistory() {
        try {
            const data = await apiFetch(BASE_URL + '/api/transfer');
            renderHistory(data.transfers || []);
        } catch (err) {
            historyList.innerHTML = '<div class="tf-empty-state"><p>ไม่สามารถโหลดประวัติได้</p></div>';
        }
    }

    function renderHistory(transfers) {
        if (transfers.length === 0) {
            historyList.innerHTML = `
                <div class="tf-empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="opacity:.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <p>ยังไม่มีประวัติการส่ง</p>
                </div>
            `;
            return;
        }

        historyList.innerHTML = transfers.map(t => {
            const files = JSON.parse(t.files_json || '[]');
            const fileNames = files.map(f => f.name).join(', ');
            const isExpired = t.is_expired;
            const statusBadge = isExpired
                ? '<span class="tf-history-badge tf-badge-expired">หมดอายุ</span>'
                : '<span class="tf-history-badge tf-badge-active">ใช้งานได้</span>';

            return `
                <div class="tf-history-item ${isExpired ? 'tf-expired' : ''}">
                    <div class="tf-history-code">${escapeHtml(t.code)}</div>
                    <div class="tf-history-info">
                        <div class="tf-history-files" title="${escapeHtml(fileNames)}">${files.length} ไฟล์ · ${formatSize(t.total_size)}</div>
                        <div class="tf-history-meta">
                            <span>
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                ดาวน์โหลด ${t.download_count} ครั้ง
                            </span>
                            <span>
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                ${formatDateTime(t.expires_at)}
                            </span>
                        </div>
                    </div>
                    ${statusBadge}
                    <div class="tf-history-actions">
                        ${!isExpired ? `<button class="btn btn-ghost btn-sm" onclick="window._tfCopyHistoryCode('${escapeHtml(t.code)}')" title="คัดลอกรหัส">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        </button>` : ''}
                        <button class="btn btn-ghost btn-sm" onclick="window._tfDeleteTransfer(${t.id})" title="ลบ" style="color:#ef4444;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    window._tfCopyHistoryCode = function (code) {
        copyToClipboard(code);
        toast('คัดลอกรหัส ' + code + ' แล้ว', 'success');
    };

    window._tfDeleteTransfer = async function (id) {
        const ok = await confirmAction('ลบรายการนี้? ไฟล์จะถูกลบถาวร', 'ลบ');
        if (!ok) return;

        try {
            await apiFetch(BASE_URL + '/api/transfer/' + id, { method: 'DELETE' });
            toast('ลบเรียบร้อย', 'success');
            loadHistory();
        } catch (err) {
            toast(err.message || 'ลบไม่สำเร็จ', 'error');
        }
    };

    // ─── Helpers ───
    function formatSize(bytes) {
        bytes = Number(bytes);
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})();
