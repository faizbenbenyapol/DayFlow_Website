<div class="page-header">
    <div>
        <h1 class="page-title">ย้ายไฟล์</h1>
        <div class="text-xs text-muted">ส่งไฟล์ข้ามอุปกรณ์ด้วยรหัส 6 หลัก — เหมือน Send Anywhere</div>
    </div>
</div>

<!-- Tab bar -->
<div class="tf-tabs" id="tfTabs">
    <button type="button" class="tf-tab active" data-tab="send">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9l20-7z"/></svg>
        ส่ง
    </button>
    <button type="button" class="tf-tab" data-tab="receive">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
        รับ
    </button>
    <button type="button" class="tf-tab" data-tab="history">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        ประวัติ
    </button>
</div>

<div class="tf-panels">

<!-- ═══════════════════════════════════════════════════
     TAB: ส่ง (Send)
═══════════════════════════════════════════════════ -->
<section class="tf-panel active" data-panel="send">

    <!-- Step 1: Select files -->
    <div class="card tf-card" id="sendStep1">
        <div class="card-body">
            <div class="tf-hero-icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9l20-7z"/></svg>
            </div>
            <h2 class="tf-section-title">ส่งไฟล์</h2>
            <p class="tf-section-desc">เลือกไฟล์ที่ต้องการส่ง แล้วรับรหัส 6 หลักเพื่อแชร์</p>

            <div class="tf-drop-zone" id="sendDrop" data-target="sendInput">
                <div class="tf-drop-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                </div>
                <div class="tf-drop-text">ลากไฟล์มาวางที่นี่</div>
                <div class="tf-drop-or">หรือ</div>
                <label for="sendInput" class="btn btn-ghost btn-sm tf-browse-btn">เลือกไฟล์</label>
                <input type="file" id="sendInput" multiple hidden>
                <div class="tf-drop-hint">รองรับทุกประเภทไฟล์ สูงสุด 1 GB ต่อไฟล์</div>
            </div>

            <div id="sendFileList" class="tf-file-list"></div>

            <div class="tf-options" id="sendOptions" style="display:none">
                <div class="tf-option-row">
                    <label class="tf-option-label">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        หมดอายุใน
                    </label>
                    <span class="tf-expiry-fixed">10 นาที</span>
                </div>
            </div>

            <div class="tf-actions" id="sendActions" style="display:none">
                <button class="btn btn-primary btn-lg tf-send-btn" id="btnSend">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9l20-7z"/></svg>
                    ส่งไฟล์
                </button>
            </div>
        </div>
    </div>

    <!-- Step 2: Show code (hidden initially) -->
    <div class="card tf-card tf-code-card" id="sendStep2" style="display:none">
        <div class="card-body">
            <div class="tf-code-display">
                <div class="tf-code-ring" id="codeRing">
                    <svg class="tf-countdown-svg" viewBox="0 0 200 200">
                        <circle class="tf-countdown-bg" cx="100" cy="100" r="90"/>
                        <circle class="tf-countdown-fg" cx="100" cy="100" r="90" id="countdownCircle"/>
                    </svg>
                    <div class="tf-code-inner">
                        <div class="tf-code-label">รหัสส่งไฟล์</div>
                        <div class="tf-code-digits" id="codeDigits"></div>
                        <div class="tf-code-timer" id="codeTimer"></div>
                    </div>
                </div>
            </div>

            <div class="tf-code-actions">
                <button class="btn btn-ghost tf-action-btn" id="btnCopyCode">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                    คัดลอกรหัส
                </button>
                <button class="btn btn-ghost tf-action-btn" id="btnCopyLink">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    คัดลอกลิงก์
                </button>
            </div>

            <div class="tf-qr-section" id="qrSection">
                <div class="tf-qr-label">สแกน QR Code เพื่อดาวน์โหลด</div>
                <div class="tf-qr-wrap" id="qrWrap"></div>
            </div>

            <div class="tf-files-summary" id="codeFilesSummary"></div>

            <div class="tf-step2-actions">
                <button class="btn btn-ghost tf-new-btn" id="btnNewTransfer">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                    ส่งไฟล์ใหม่
                </button>
                <button class="btn btn-ghost tf-cancel-btn" id="btnCancelTransfer">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/></svg>
                    ยกเลิกการส่ง
                </button>
            </div>
        </div>
    </div>

    <!-- Upload progress -->
    <div class="tf-progress-overlay" id="uploadOverlay" style="display:none">
        <div class="tf-progress-card">
            <div class="tf-spinner"></div>
            <div class="tf-progress-text">กำลังอัปโหลด...</div>
        </div>
    </div>

</section>

<!-- ═══════════════════════════════════════════════════
     TAB: รับ (Receive)
═══════════════════════════════════════════════════ -->
<section class="tf-panel" data-panel="receive">

    <div class="card tf-card">
        <div class="card-body">
            <div class="tf-hero-icon tf-hero-receive">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
            </div>
            <h2 class="tf-section-title">รับไฟล์</h2>
            <p class="tf-section-desc">ใส่รหัส 6 หลักที่ได้รับจากผู้ส่ง</p>

            <div class="tf-code-input-wrap">
                <input type="text" class="tf-code-input" id="receiveCode" maxlength="6" placeholder="000000" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
            </div>

            <div class="tf-actions">
                <button class="btn btn-primary btn-lg tf-receive-btn" id="btnReceive" disabled>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    รับไฟล์
                </button>
            </div>

            <!-- Receive result -->
            <div class="tf-receive-result" id="receiveResult" style="display:none">
                <div class="tf-result-header">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span>พบไฟล์!</span>
                </div>
                <div class="tf-result-files" id="receiveFiles"></div>
                <div class="tf-result-meta" id="receiveMeta"></div>
                <a class="btn btn-primary btn-lg tf-download-btn" id="receiveDownloadBtn" href="#" target="_blank">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    ดาวน์โหลดเลย
                </a>
            </div>
        </div>
    </div>

</section>

<!-- ═══════════════════════════════════════════════════
     TAB: ประวัติ (History)
═══════════════════════════════════════════════════ -->
<section class="tf-panel" data-panel="history">

    <div class="card tf-card">
        <div class="card-body">
            <h2 class="tf-section-title" style="margin-bottom: 1rem;">ประวัติการส่ง</h2>
            <div id="historyList" class="tf-history-list">
                <div class="tf-empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="opacity:.3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <p>ยังไม่มีประวัติการส่ง</p>
                </div>
            </div>
        </div>
    </div>

</section>

</div><!-- /.tf-panels -->
