<?php
// =====================================================
// views/transfer/index.php — send a file to another device
//
// เลือกไฟล์หรือพิมพ์ข้อความ เลือกเวลาหมดอายุ แล้วได้รหัส 6 หลักกับ QR อีกเครื่องใส่รหัส
// ที่ /transfer/receive (ไม่ต้องมีบัญชี) assets/js/transfer.js อัปโหลดทีละชิ้นผ่าน /api/transfer/*
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>ส่งไฟล์</h1>
        <p class="sub">ส่งไฟล์ ข้อความ หรือลิงก์ข้ามอุปกรณ์ด้วยรหัส 6 หลัก หมดเวลาแล้วลบเอง</p>
    </div>
</div>

<div class="tabs" id="tfTabs" role="tablist" aria-label="ส่ง รับ หรือดูประวัติ">
    <button type="button" class="tab" role="tab" aria-selected="true" data-tab="send">ส่ง</button>
    <button type="button" class="tab" role="tab" aria-selected="false" data-tab="receive">รับ</button>
    <button type="button" class="tab" role="tab" aria-selected="false" data-tab="history">ประวัติ</button>
</div>

<div class="tf-panels" data-max-bytes="<?= (int)FileTransfer::MAX_TOTAL_BYTES ?>" data-max-message="<?= FileTransfer::MAX_MESSAGE_CHARS ?>">

<!-- Send -->
<section class="tf-panel" data-panel="send">
    <div id="sendStep1">
        <button type="button" class="tf-drop" id="sendDrop">
            <svg class="icon" aria-hidden="true"><use href="#i-transfer"/></svg>
            <span class="tf-drop-text">ลากไฟล์มาวางที่นี่ หรือกดเพื่อเลือกไฟล์</span>
            <span class="tf-drop-hint">ทุกประเภทไฟล์ กี่ไฟล์ก็ได้ รวมไม่เกิน 10 GB</span>
        </button>
        <input type="file" id="sendInput" multiple hidden aria-label="เลือกไฟล์ที่จะส่ง">

        <div id="sendFileList" aria-live="polite"></div>

        <div class="form-group tf-message-field">
            <label class="form-label" for="sendMessage">ข้อความหรือลิงก์ <span class="form-hint">ไม่บังคับ</span></label>
            <textarea class="form-control" id="sendMessage" rows="3" maxlength="<?= FileTransfer::MAX_MESSAGE_CHARS ?>" placeholder="เช่น ลิงก์ หรือรายละเอียดที่อยากให้ผู้รับอ่าน"></textarea>
        </div>

        <fieldset class="form-group tf-expiry-field">
            <legend class="form-label" id="sendExpiryLabel">หมดอายุหลังส่งเสร็จ</legend>
            <div class="seg-pair" role="radiogroup" aria-labelledby="sendExpiryLabel">
                <?php foreach (FileTransfer::EXPIRY_CHOICES as $i => $minutes): ?>
                <label><input type="radio" name="sendExpiry" value="<?= $minutes ?>"<?= $i === 0 ? ' checked' : '' ?>><span><?= $minutes ?> นาที</span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="tf-actions">
            <button class="btn btn-primary" type="button" id="btnSend" disabled>ส่งและรับรหัส</button>
        </div>
        <p class="form-error" id="sendError" role="alert" hidden></p>
    </div>

    <!-- ระหว่างอัปโหลด: ไฟล์ใหญ่ใช้เวลานาน จึงบอกความคืบหน้าและยกเลิกได้ (REQ-17) -->
    <div id="sendProgress" class="tf-progress" hidden>
        <p class="tf-progress-label" id="sendProgressLabel" aria-live="polite">กำลังเตรียมส่ง…</p>
        <div class="progress" role="progressbar" aria-labelledby="sendProgressLabel" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="sendProgressBar">
            <div class="progress-bar" id="sendProgressFill"></div>
        </div>
        <p class="tf-files-summary" id="sendProgressNote"></p>
        <div class="tf-actions">
            <button class="btn" type="button" id="btnAbortSend">ยกเลิกการส่ง</button>
        </div>
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
        <p class="tf-files-summary">ผู้รับที่ไม่มีบัญชี ใส่รหัสได้ที่ <a href="<?= h(APP_URL) ?>/transfer/receive"><?= h(preg_replace('#^https?://#', '', APP_URL)) ?>/transfer/receive</a></p>

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
</section>

<!-- History -->
<section class="tf-panel" data-panel="history" hidden>
    <div id="historyList" aria-busy="true">
        <div class="skel-row"><span class="skel skel-w-60"></span></div>
        <div class="skel-row"><span class="skel skel-w-45"></span></div>
    </div>
</section>

</div>
