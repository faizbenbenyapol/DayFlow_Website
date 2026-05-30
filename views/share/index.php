<!DOCTYPE html>
<html lang="th" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? 'ไฟล์แชร์') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #f8f9fb;
            --surface: #fff;
            --border: #e5e7eb;
            --text: #111827;
            --muted: #6b7280;
            --accent: #2563eb;
            --radius: 10px;
            --shadow: 0 2px 12px rgba(0,0,0,.08);
        }
        [data-theme="dark"] {
            --bg: #111827; --surface: #1f2937; --border: #374151;
            --text: #f9fafb; --muted: #9ca3af;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        .share-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .share-brand { font-weight: 600; font-size: 1rem; color: var(--muted); }
        .share-title-row { display: flex; align-items: center; gap: .75rem; }
        .share-badge {
            font-size: .72rem;
            padding: 2px 8px;
            border-radius: 99px;
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 500;
            white-space: nowrap;
        }
        .share-badge.download { background: #d1fae5; color: #065f46; }

        .share-container { max-width: 860px; margin: 2rem auto; padding: 0 1rem; }

        .share-info-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: var(--shadow);
        }
        .share-info-icon { font-size: 2.5rem; flex-shrink: 0; }
        .share-info-name { font-weight: 600; font-size: 1.1rem; word-break: break-word; }
        .share-info-meta { font-size: .8rem; color: var(--muted); margin-top: 2px; }
        .share-dl-btn {
            margin-left: auto;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            padding: .55rem 1.25rem;
            font-size: .875rem;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: .5rem;
            text-decoration: none;
            flex-shrink: 0;
        }
        .share-dl-btn:hover { opacity: .9; }

        /* Folder listing */
        .file-list { display: flex; flex-direction: column; gap: 2px; }
        .file-row {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: .75rem 1rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            font-size: .875rem;
        }
        .file-row-icon { font-size: 1.3rem; flex-shrink: 0; }
        .file-row-name { flex: 1; word-break: break-word; }
        .file-row-meta { font-size: .75rem; color: var(--muted); flex-shrink: 0; }
        .file-row-dl {
            background: none; border: 1px solid var(--border); border-radius: 6px;
            padding: 3px 10px; font-size: .78rem; cursor: pointer; color: var(--accent);
            text-decoration: none; white-space: nowrap;
        }
        .file-row-dl:hover { background: #eff6ff; }

        .share-footer {
            text-align: center;
            color: var(--muted);
            font-size: .75rem;
            margin-top: 2rem;
            padding-bottom: 2rem;
        }
        <?php if (isset($share['expires_at']) && $share['expires_at']): ?>
        .share-expiry {
            background: #fef9c3;
            border: 1px solid #fde68a;
            color: #92400e;
            border-radius: var(--radius);
            padding: .6rem 1rem;
            font-size: .8rem;
            margin-bottom: 1rem;
        }
        <?php endif; ?>
    </style>
</head>
<body>

<div class="share-header">
    <span class="share-brand">ระบบจัดการไฟล์ · ลิงก์แชร์</span>
    <div class="share-title-row">
        <span class="share-badge <?= $share['permission'] === 'download' ? 'download' : '' ?>">
            <?= $share['permission'] === 'download' ? 'ดาวน์โหลดได้' : 'ดูอย่างเดียว' ?>
        </span>
        <?php if ($share['label']): ?>
            <span style="font-size:.85rem;color:var(--muted)"><?= h($share['label']) ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="share-container">

    <?php if (isset($share['expires_at']) && $share['expires_at']): ?>
    <div class="share-expiry">
        หมดอายุ: <?= h(date('d/m/Y H:i', strtotime($share['expires_at']))) ?>
    </div>
    <?php endif; ?>

    <?php if ($file['type'] === 'file'): ?>
    <!-- Single file share -->
    <div class="share-info-card">
        <div class="share-info-icon"><?= getShareIcon($file['mime_type']) ?></div>
        <div>
            <div class="share-info-name"><?= h($file['name']) ?></div>
            <div class="share-info-meta">
                <?= $file['file_size'] ? formatBytes((int)$file['file_size']) : '' ?>
                <?php if ($file['mime_type']): ?> · <?= h($file['mime_type']) ?><?php endif; ?>
            </div>
        </div>
        <?php if ($share['permission'] === 'download'): ?>
        <a class="share-dl-btn" href="<?= APP_URL ?>/share/<?= h($share['token']) ?>/download/<?= (int)$file['id'] ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            ดาวน์โหลด
        </a>
        <?php endif; ?>
    </div>

    <?php if ($file['mime_type'] && str_starts_with($file['mime_type'], 'image/')): ?>
        <div style="text-align:center;margin-bottom:1.5rem">
            <img src="<?= APP_URL ?>/share/<?= h($share['token']) ?>/download/<?= (int)$file['id'] ?>"
                 alt="<?= h($file['name']) ?>"
                 style="max-width:100%;max-height:600px;border-radius:var(--radius);box-shadow:var(--shadow)">
        </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Folder share -->
    <div class="share-info-card">
        <div class="share-info-icon">📁</div>
        <div>
            <div class="share-info-name"><?= h($file['name']) ?></div>
            <div class="share-info-meta"><?= count($children) ?> รายการ</div>
        </div>
    </div>

    <div class="file-list">
        <?php if (empty($children)): ?>
        <div style="text-align:center;padding:2rem;color:var(--muted);font-size:.875rem">โฟลเดอร์ว่างเปล่า</div>
        <?php else: ?>
        <?php foreach ($children as $child): ?>
        <div class="file-row">
            <div class="file-row-icon"><?= getShareIcon($child['mime_type'] ?? null, $child['type']) ?></div>
            <div class="file-row-name"><?= h($child['name']) ?></div>
            <div class="file-row-meta"><?= $child['file_size'] ? formatBytes((int)$child['file_size']) : '' ?></div>
            <?php if ($share['permission'] === 'download' && $child['type'] === 'file'): ?>
            <a class="file-row-dl" href="<?= APP_URL ?>/share/<?= h($share['token']) ?>/download/<?= (int)$child['id'] ?>">ดาวน์โหลด</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="share-footer">ลิงก์นี้แชร์โดยเจ้าของบัญชี · ดูได้โดยไม่ต้องเข้าสู่ระบบ</div>
</div>

<?php
function getShareIcon(?string $mime, string $type = 'file'): string {
    if ($type === 'folder') return '📁';
    if (!$mime) return '📄';
    if (str_starts_with($mime, 'image/')) return '🖼️';
    if (str_starts_with($mime, 'video/')) return '🎬';
    if (str_starts_with($mime, 'audio/')) return '🎵';
    if (str_contains($mime, 'pdf'))   return '📕';
    if (str_contains($mime, 'word'))  return '📘';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) return '📗';
    if (str_contains($mime, 'zip') || str_contains($mime, 'compressed'))    return '🗜️';
    return '📄';
}
?>
</body>
</html>
