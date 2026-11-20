<?php
// =====================================================
// views/transfer/receive.php — หน้ารับไฟล์ ไม่ต้องมีบัญชี (REQ-18)
//
// FileTransferController::renderReceive ส่งมา:
//   $transfer  การส่งที่พร้อมรับ หรือ null = แสดงฟอร์มใส่รหัส
//   $code      รหัสที่พิมพ์มาแล้วหาไม่เจอ (ใส่กลับในช่อง)
//   $error     ข้อความบอกว่าทำไมหาไม่เจอ หรือ '' ถ้าไม่มี
// ปุ่มคัดลอกข้อความทำงานด้วย assets/js/transfer-receive.js (หน้านอกแอปห้ามมี inline script)
// =====================================================

$outsideStyles = ['outside', 'transfer'];
$files = $transfer ? (json_decode($transfer['files_json'], true) ?: []) : [];
$message = $transfer ? (string)($transfer['message'] ?? '') : '';
$base = $transfer ? APP_URL . '/transfer/download/' . rawurlencode((string)$transfer['token']) : '';

/**
 * ข้อความของผู้ส่งเป็น HTML ที่ปลอดภัย: escape ทั้งหมดก่อน แล้วค่อยทำ URL ให้เป็นลิงก์ที่กดได้
 * ตัดเครื่องหมายวรรคตอนท้าย URL ออก ("ดูที่ https://a.com." ลิงก์ไปที่ https://a.com)
 */
$linkify = static function (string $text): string {
    $html = preg_replace_callback(
        '#https?://[^\s<]+[^\s<.,;:!?)\]\'"]#u',
        fn(array $m): string => '<a href="' . $m[0] . '" target="_blank" rel="noopener noreferrer nofollow">' . $m[0] . '</a>',
        h($text)
    );
    return nl2br($html ?? h($text));
};

$extension = static function (string $name): string {
    $dot = strrpos($name, '.');
    return $dot > 0 ? strtoupper(substr($name, $dot + 1, 4)) : 'FILE';
};

require ROOT . '/views/partials/outside-head.php';
?>
</head>
<body class="pub-page">
<?php require ROOT . '/views/partials/icons.php'; ?>

<div class="pub-bar" role="note">
    <span class="pub-bar-brand">DayFlow</span>
    <span>รับไฟล์ · ไม่ต้องเข้าสู่ระบบ</span>
</div>

<main class="pub">
<?php if ($transfer === null): ?>
    <header class="page-head pub-head">
        <div>
            <h1 class="pub-name">รับไฟล์</h1>
            <p class="sub">ใส่รหัส 6 หลักที่ผู้ส่งให้มา</p>
        </div>
    </header>
    <form class="tf-receive-form" method="get" action="<?= h(APP_URL) ?>/transfer/receive">
        <label class="form-label" for="receiveCode">รหัส 6 หลัก</label>
        <input type="text" class="tf-code-input" id="receiveCode" name="code" value="<?= h($code) ?>" maxlength="6" placeholder="000000"
               inputmode="numeric" pattern="[0-9]{6}" autocomplete="off" required<?= $error !== '' ? ' aria-describedby="receiveError" aria-invalid="true"' : '' ?>>
        <?php if ($error !== ''): ?>
            <p class="form-error" id="receiveError" role="alert"><?= h($error) ?></p>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">ค้นหาไฟล์</button>
    </form>
<?php else: ?>
    <header class="page-head pub-head">
        <div>
            <h1 class="pub-name"><?= $files ? 'ไฟล์ที่ส่งให้คุณ' : 'ข้อความที่ส่งให้คุณ' ?></h1>
            <p class="sub">
                <?= $files ? count($files) . ' ไฟล์ · ' . h(formatBytes((int)$transfer['total_size'])) . ' · ' : '' ?>รหัส <?= h($transfer['code']) ?>
            </p>
            <p class="pub-expiry">ใช้ได้ถึง <?= h(date('H:i', strtotime($transfer['expires_at']))) ?> น. แล้วไฟล์จะถูกลบ</p>
        </div>
        <?php if (FileTransfer::canZip($files)): ?>
            <div class="page-head-actions">
                <a class="btn btn-primary" href="<?= h($base) ?>/all">ดาวน์โหลดทั้งหมดเป็น ZIP</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($message !== ''): ?>
        <section class="tf-message" aria-labelledby="tfMessageTitle">
            <div class="tf-message-head">
                <h2 id="tfMessageTitle">ข้อความ</h2>
                <button type="button" class="btn btn-sm" data-copy-from="tfMessageText">คัดลอกข้อความ</button>
            </div>
            <p class="tf-message-text" id="tfMessageText"><?= $linkify($message) ?></p>
        </section>
    <?php endif; ?>

    <?php if ($files): ?>
        <ul class="ruled-list pub-list">
            <?php foreach ($files as $i => $file): ?>
                <li class="ruled-row pub-row">
                    <span class="pub-kind" aria-hidden="true"><span class="pub-ext"><?= h($extension((string)$file['name'])) ?></span></span>
                    <span class="grow pub-file"><?= h($file['name']) ?></span>
                    <span class="pub-size num"><?= h(formatBytes((int)$file['size'])) ?></span>
                    <a class="btn btn-sm" href="<?= h($base . '/' . $i) ?>" aria-label="ดาวน์โหลด <?= h($file['name']) ?>">ดาวน์โหลด</a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($files) > 1 && !FileTransfer::canZip($files)): ?>
            <p class="pub-foot">ไฟล์ชุดนี้รวมเกิน 2 GB จึงดาวน์โหลดได้ทีละไฟล์</p>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

    <p class="pub-foot">ส่งผ่าน DayFlow · ไฟล์และข้อความถูกลบเองเมื่อหมดเวลา</p>
</main>
<script src="<?= APP_URL ?>/assets/js/transfer-receive.js?v=<?= @filemtime(PUBLIC_ROOT . '/assets/js/transfer-receive.js') ?>"></script>
</body>
</html>
