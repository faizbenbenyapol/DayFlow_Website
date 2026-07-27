<?php
// =====================================================
// core/Response.php — HTTP Response Helpers
// =====================================================

class Response
{
    /**
     * Send JSON response and exit
     */
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    /**
     * Redirect and exit
     */
    public static function redirect(string $path): void
    {
        $url = (strpos($path, 'http') === 0) ? $path : APP_URL . $path;
        header('Location: ' . $url);
        exit;
    }

    /**
     * Render a view file with data
     */
    public static function view(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $file = dirname(__DIR__) . '/views/' . $template . '.php';
        if (!file_exists($file)) {
            http_response_code(404);
            include dirname(__DIR__) . '/views/errors/404.php';
            exit;
        }
        include $file;
    }

    /**
     * Abort with error (JSON for API, page for browser)
     */
    public static function abort(int $status, string $message = ''): void
    {
        if (Request::isApi()) {
            self::json(['error' => $message ?: 'Error ' . $status], $status);
        }
        http_response_code($status);

        $theme = 'light';
        $isReadOnly = false;
        $sharedMenus = [];
        if (class_exists('Auth')) {
            $theme = Auth::theme();
            $isReadOnly = Auth::isReadOnly();
            $sharedMenus = $isReadOnly ? Auth::getSharedMenus() : [];
        }

        if (empty($message)) {
            if ($status === 403) {
                $message = 'ไม่มีสิทธิ์เข้าถึงเมนูนี้';
            } elseif ($status === 404) {
                $message = 'ไม่พบหน้าที่ต้องการ';
            } else {
                $message = 'เกิดข้อผิดพลาดในการประมวลผล';
            }
        }
        
        $menuLabels = [
            'tasks' => 'งาน', 
            'notes' => 'โน้ต', 
            'planner' => 'แพลนเนอร์',
            'exercise' => 'ออกกำลังกาย', 
            'food-notes' => 'อาหาร',
            'finance' => 'การเงิน', 
            'subscriptions' => 'แจ้งเตือน', 
            'stocks' => 'หุ้น',
            'files' => 'ไฟล์', 
            'ai' => 'ผู้ช่วยอัจฉริยะ', 
            'file-tools' => 'เครื่องมือจัดการไฟล์',
            'transfer' => 'ย้ายไฟล์'
        ];
        ?>
<!doctype html>
<html lang="th" data-theme="<?= h($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title><?= h((string)$status) ?> — <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-bg: #ffffff;
            --color-surface: #f8f9fa;
            --color-border: #eaeaea;
            --color-text: #1d1d1f;
            --color-muted: #6e6e73;
            --color-primary: #1d1d1f;
            --color-primary-rgb: 29, 29, 31;
            --radius-lg: 16px;
            --font: 'Inter', 'IBM Plex Sans Thai', -apple-system, sans-serif;
            --transition: 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        [data-theme="dark"] {
            --color-bg: #0a0a0a;
            --color-surface: #121212;
            --color-border: #1f1f1f;
            --color-text: #f5f5f7;
            --color-muted: #86868b;
            --color-primary: #f5f5f7;
            --color-primary-rgb: 245, 245, 247;
        }
        body {
            background-color: var(--color-bg);
            color: var(--color-text);
            font-family: var(--font);
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100dvh;
            padding: 24px;
            box-sizing: border-box;
            line-height: 1.6;
        }
        .error-card {
            background-color: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 48px 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.05);
            animation: fadeIn 0.4s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .shared-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(var(--color-primary-rgb), 0.06);
            color: var(--color-primary);
            padding: 6px 14px;
            border-radius: 99px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 24px;
        }
        .error-code {
            font-size: 4.5rem;
            font-weight: 700;
            margin: 0 0 12px 0;
            line-height: 1;
            color: var(--color-primary);
            letter-spacing: -0.02em;
        }
        .error-message {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--color-text);
        }
        .error-desc {
            font-size: 0.95rem;
            color: var(--color-muted);
            margin-bottom: 36px;
            max-width: 380px;
            margin-left: auto;
            margin-right: auto;
        }
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            font-size: 0.95rem;
            font-weight: 500;
            border-radius: 10px;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            font-family: var(--font);
            border: 1px solid transparent;
        }
        .btn-primary {
            background-color: var(--color-primary);
            color: var(--color-bg);
            border-color: var(--color-primary);
        }
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        .btn-secondary {
            background-color: transparent;
            color: var(--color-text);
            border-color: var(--color-border);
        }
        .btn-secondary:hover {
            background-color: rgba(var(--color-primary-rgb), 0.04);
            border-color: var(--color-primary);
        }
        .allowed-menus {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px dashed var(--color-border);
            text-align: left;
        }
        .allowed-menus-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--color-muted);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .menu-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .menu-tag {
            background-color: var(--color-bg);
            border: 1px solid var(--color-border);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            color: var(--color-text);
            text-decoration: none;
            transition: var(--transition);
        }
        .menu-tag:hover {
            border-color: var(--color-primary);
            background-color: rgba(var(--color-primary-rgb), 0.02);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <?php if ($isReadOnly): ?>
            <div class="shared-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                โหมดแชร์ดูอย่างเดียว
            </div>
        <?php endif; ?>
        
        <div class="error-code"><?= h((string)$status) ?></div>
        <div class="error-message"><?= h($message) ?></div>
        
        <p class="error-desc">
            <?php if ($status === 403 && $isReadOnly): ?>
                ขออภัย ลิงก์ที่แชร์นี้ไม่ได้รับอนุญาตให้เข้าถึงเมนูนี้ ระบบเปิดให้เข้าชมเฉพาะบางหน้าตามที่ผู้แชร์กำหนดไว้เท่านั้น
            <?php elseif ($status === 403): ?>
                ขออภัย คุณไม่มีสิทธิ์ในการเข้าถึงส่วนนี้ของระบบ
            <?php elseif ($status === 404): ?>
                ไม่พบหน้าเว็บที่คุณกำลังเรียกหา กรุณาตรวจสอบลิงก์หรือเส้นทางอีกครั้ง
            <?php else: ?>
                มีข้อผิดพลาดไม่คาดคิดเกิดขึ้นในการประมวลผลคำขอของคุณ
            <?php endif; ?>
        </p>
        
        <div class="btn-group">
            <?php if ($isReadOnly): ?>
                <a href="<?= APP_URL ?>/exit-share" class="btn btn-primary">
                    <?= !empty($_SESSION['user_id']) ? 'กลับหน้าหลักของคุณ' : 'ออกจากโหมดแชร์ / กลับหน้าหลัก' ?>
                </a>
            <?php else: ?>
                <a href="<?= APP_URL ?>/" class="btn btn-primary">กลับหน้าหลัก</a>
            <?php endif; ?>
            <button onclick="history.back()" class="btn btn-secondary">ย้อนกลับหน้าเดิม</button>
        </div>

        <?php if ($isReadOnly && !empty($sharedMenus)): ?>
            <div class="allowed-menus">
                <div class="allowed-menus-title">เมนูที่ลิงก์นี้เปิดให้เข้าชมได้:</div>
                <div class="menu-tags">
                    <?php foreach ($sharedMenus as $m): ?>
                        <a href="<?= APP_URL ?>/<?= h($m) ?>" class="menu-tag">
                            <?= h($menuLabels[$m] ?? $m) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
        <?php
        exit;
    }
}
