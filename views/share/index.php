<?php
// =====================================================
// views/share/index.php
// A file or folder shared by link: no sign-in, nothing to change. Set by
// ShareController::viewShare: $share, $file, $children, $pageTitle.
// =====================================================

$outsideStyles = ['outside'];
$canDownload = $share['permission'] === 'download';
$isFolder = $file['type'] !== 'file';
$thaiMonthsShort = [1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

$downloadUrl = fn(int $id): string => APP_URL . '/share/' . rawurlencode((string)$share['token']) . '/download/' . $id;

// The kind of a file as a short tag: its extension, as the files page shows it.
$extension = static function (string $name): string {
    $dot = strrpos($name, '.');
    return $dot > 0 ? strtoupper(substr($name, $dot + 1, 4)) : 'FILE';
};

$expiry = null;
if (!empty($share['expires_at'])) {
    $when = new DateTime($share['expires_at']);
    $expiry = $when->format('j') . ' ' . $thaiMonthsShort[(int)$when->format('n')] . ' ' . ((int)$when->format('Y') + 543) . ' เวลา ' . $when->format('H:i') . ' น.';
}

$isImage = !$isFolder && $file['mime_type'] && str_starts_with($file['mime_type'], 'image/');

require ROOT . '/views/partials/outside-head.php';
?>
</head>
<body class="pub-page">
<?php require ROOT . '/views/partials/icons.php'; ?>

<!-- What this page is, always on top: a thin bar of ink. -->
<div class="pub-bar" role="note">
    <span class="pub-bar-brand">DayFlow</span>
    <span>ไฟล์ที่แชร์ให้คุณ · <?= $canDownload ? 'ดูและดาวน์โหลดได้' : 'ดูอย่างเดียว' ?></span>
</div>

<main class="pub">
    <header class="page-head pub-head">
        <div>
            <h1 class="pub-name"><?= h($file['name']) ?></h1>
            <div class="sub">
                <?php if ($isFolder): ?>
                    โฟลเดอร์ · <?= count($children) ?> รายการ
                <?php else: ?>
                    <?= $file['file_size'] ? h(formatBytes((int)$file['file_size'])) : '' ?>
                    <?php if ($file['mime_type']): ?> · <?= h($file['mime_type']) ?><?php endif; ?>
                <?php endif; ?>
                <?php if ($share['label']): ?> · <?= h($share['label']) ?><?php endif; ?>
            </div>
            <?php if ($expiry): ?>
                <p class="pub-expiry">ลิงก์นี้ใช้ได้ถึง <?= h($expiry) ?></p>
            <?php endif; ?>
        </div>
        <?php if (!$isFolder && $canDownload): ?>
            <div class="page-head-actions">
                <a class="btn btn-primary btn-lg" href="<?= h($downloadUrl((int)$file['id'])) ?>">ดาวน์โหลดไฟล์</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($isImage && $canDownload): ?>
        <!-- The picture is served by the download route, which a view-only link may not use. -->
        <img class="pub-image" src="<?= h($downloadUrl((int)$file['id'])) ?>" alt="<?= h($file['name']) ?>">
    <?php endif; ?>

    <?php if ($isFolder): ?>
        <?php if (empty($children)): ?>
            <div class="empty-state">
                <p class="empty-state-title">โฟลเดอร์นี้ยังว่างอยู่</p>
                <p class="empty-state-text">เจ้าของยังไม่ได้ใส่ไฟล์ลงในโฟลเดอร์นี้</p>
            </div>
        <?php else: ?>
            <ul class="ruled-list pub-list">
                <?php foreach ($children as $child): $childIsFile = $child['type'] === 'file'; ?>
                    <li class="ruled-row pub-row">
                        <span class="pub-kind" aria-hidden="true">
                            <?php if ($childIsFile): ?>
                                <span class="pub-ext"><?= h($extension((string)$child['name'])) ?></span>
                            <?php else: ?>
                                <svg class="icon"><use href="#i-files"/></svg>
                            <?php endif; ?>
                        </span>
                        <span class="grow pub-file"><?= h($child['name']) ?><?php if (!$childIsFile): ?><span class="sr-only"> (โฟลเดอร์)</span><?php endif; ?></span>
                        <span class="pub-size num"><?= $childIsFile && $child['file_size'] ? h(formatBytes((int)$child['file_size'])) : '' ?></span>
                        <?php if ($canDownload && $childIsFile): ?>
                            <a class="btn btn-sm" href="<?= h($downloadUrl((int)$child['id'])) ?>" aria-label="ดาวน์โหลด <?= h($child['name']) ?>">ดาวน์โหลด</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <p class="pub-foot">แชร์โดยเจ้าของบัญชี DayFlow · เปิดดูได้โดยไม่ต้องเข้าสู่ระบบ</p>
</main>
</body>
</html>
