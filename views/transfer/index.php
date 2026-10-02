<?php
// =====================================================
// views/transfer/index.php — send a file to another device
//
// Pick files, get a six-digit code (and a QR code); the other device types the
// code and downloads. A transfer lasts ten minutes. assets/js/transfer.js runs
// it from /api/transfer/*.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>ส่งไฟล์</h1>
        <p class="sub">ส่งไฟล์ข้ามอุปกรณ์ด้วยรหัส 6 หลัก ใช้ได้ 10 นาทีแล้วไฟล์จะถูกลบ</p>
    </div>
</div>

<div class="tabs" id="tfTabs" role="tablist" aria-label="ส่ง รับ หรือดูประวัติ">
    <button type="button" class="tab" role="tab" aria-selected="true" data-tab="send">ส่ง</button>
    <button type="button" class="tab" role="tab" aria-selected="false" data-tab="receive">รับ</button>
    <button type="button" class="tab" role="tab" aria-selected="false" data-tab="history">ประวัติ</button>
</div>

<div class="tf-panels" data-max-bytes="<?= (int)MAX_UPLOAD_BYTES ?>" data-max-label="<?= h(formatBytes(MAX_UPLOAD_BYTES)) ?>">

<!-- Send -->
<section class="tf-panel" data-panel="send">
    <div id="sendStep1">
        <button type="button" class="tf-drop" id="sendDrop">
            <svg class="icon" aria-hidden="true"><use href="#i-transfer"/></svg>
            <span class="tf-drop-text">ลากไฟล์มาวางที่นี่ หรือกดเพื่อเลือกไฟล์</span>
            <span class="tf-drop-hint">ทุกประเภทไฟล์ ไม่เกิน <?= h(formatBytes(MAX_UPLOAD_BYTES)) ?> ต่อไฟล์</span>
        </button>
        <input type="file" id="sendInput" multiple hidden aria-label="เลือกไฟล์ที่จะส่ง">

        <div id="sendFileList" aria-live="polite"></div>

        <div class="tf-actions" id="sendActions" hidden>
            <button class="btn btn-primary" type="button" id="btnSend">ส่งไฟล์และรับรหัส</button>
            <span class="tf-expiry">รหัสหมดอายุใน 10 นาที</span>
        </div>
        <p class="form-error" id="sendError" role="alert" hidden></p>
    </div>

    <div id="sendStep2" hidden>
        <div class="tf-code">
            <p class="tf-code-label">รหัสส่งไฟล์</p>
            <div class="tf-code-digits" id="codeDigits" aria-live="polite"></div>
            <div class="progress tf-code-bar" aria-hidden="true"><div class="progress-bar" id="codeBar"></div></div>
            <p class="tf-code-timer" id="codeTimer" role="timer"></p>
        </div>

        <div class="tf-code-actions">
            <button class="btn" type="button" id="btnCopyCode">คัดลอกรหัส</button>
            <button class="btn" type="button" id="btnCopyLink">คัดลอกลิงก์</button>
        </div>

        <section class="tf-qr" id="qrSection" aria-label="รหัส QR">
            <p class="tf-qr-label">หรือสแกน QR Code เพื่อดาวน์โหลดทันที</p>
            <div class="tf-qr-wrap" id="qrWrap"></div>
        </section>

        <p class="tf-files-summary" id="codeFilesSummary"></p>

        <div class="tf-actions">
            <button class="btn" type="button" id="btnNewTransfer">ส่งไฟล์ชุดใหม่</button>
            <button class="btn btn-danger" type="button" id="btnCancelTransfer">ยกเลิกและลบไฟล์ทันที</button>
        </div>
    </div>
</section>

<!-- Receive -->
<section class="tf-panel" data-panel="receive" hidden>
    <form id="receiveForm" novalidate>
        <div class="form-group">
            <label class="form-label" for="receiveCode">รหัส 6 หลักจากผู้ส่ง</label>
            <input type="text" class="tf-code-input" id="receiveCode" maxlength="6" placeholder="000000" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
        </div>
        <button class="btn btn-primary" type="submit" id="btnReceive" disabled>ค้นหาไฟล์</button>
        <p class="form-error" id="receiveError" role="alert" hidden></p>
    </form>

    <div class="tf-result" id="receiveResult" hidden>
        <h2>พบไฟล์</h2>
        <ul class="ruled-list" id="receiveFiles"></ul>
        <p class="tf-files-summary" id="receiveMeta"></p>
        <a class="btn btn-primary" id="receiveDownloadBtn" href="#" target="_blank" rel="noopener">ดาวน์โหลดทั้งหมด</a>
    </div>
</section>

<!-- History -->
<section class="tf-panel" data-panel="history" hidden>
    <div id="historyList" aria-busy="true">
        <div class="skel-row"><span class="skel skel-w-60"></span></div>
        <div class="skel-row"><span class="skel skel-w-45"></span></div>
    </div>
</section>

</div>
