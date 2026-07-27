<?php
// =====================================================
// controllers/FileTransferController.php
// =====================================================

require_once ROOT . '/models/FileTransfer.php';

class FileTransferController
{
    // ------------------------------------------------------------------ //
    // Page: File Transfer UI
    // ------------------------------------------------------------------ //
    public function index(): void
    {
        // Cleanup expired transfers on page load (lightweight)
        FileTransfer::cleanup();

        $pageTitle  = 'ย้ายไฟล์';
        $pageStyle  = 'transfer';
        $pageScript = 'transfer';
        $loadQrLib  = true;

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/transfer/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    // ------------------------------------------------------------------ //
    // API: Upload files and create a transfer code
    // ------------------------------------------------------------------ //
    public function apiSend(): void
    {
        $userId = Auth::userId();

        if (empty($_FILES['files'])) {
            Response::json(['error' => 'ไม่พบไฟล์'], 422);
        }

        // Fixed expiry: 10 minutes
        $expiryMinutes = 10;

        $maxDownloads = max(0, min(10000, (int) Request::input('max_downloads', 0)));

        // Keep the transfer limit aligned with PHP's configured upload limit.
        // This prevents the UI/API from promising a size PHP will reject.
        $maxFileSize = MAX_UPLOAD_BYTES;

        $files = $_FILES['files'];
        $uploaded = [];
        $totalSize = 0;

        // Blocked extensions
        $blockedExt = ['php','php3','php4','php5','php7','php8','phtml','phar',
                       'cgi','pl','py','sh','rb','asp','aspx','jsp','exe','bat','cmd'];

        // Create a unique directory for this transfer
        $transferDir = 'transfers/' . $userId . '/' . date('Ymd') . '_' . bin2hex(random_bytes(8));
        $fullDir = UPLOAD_DIR . $transferDir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        // Handle both single and multiple file uploads
        $names    = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errors   = is_array($files['error']) ? $files['error'] : [$files['error']];
        $sizes    = is_array($files['size']) ? $files['size'] : [$files['size']];

        if (count($names) > 50) Response::json(['error' => 'ส่งไฟล์ได้ไม่เกิน 50 ไฟล์ต่อครั้ง'], 422);

        for ($i = 0; $i < count($names); $i++) {
            if ($errors[$i] !== UPLOAD_ERR_OK) continue;
            if (!is_uploaded_file($tmpNames[$i])) continue;

            $origName = basename($names[$i]);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (in_array($ext, $blockedExt)) {
                // Clean up any already-uploaded files
                foreach ($uploaded as $u) {
                    @unlink(UPLOAD_DIR . $u['path']);
                }
                @rmdir($fullDir);
                Response::json(['error' => 'ไม่อนุญาตนามสกุลไฟล์ ".' . h($ext) . '"'], 422);
            }

            if ($sizes[$i] > $maxFileSize) {
                foreach ($uploaded as $u) {
                    @unlink(UPLOAD_DIR . $u['path']);
                }
                @rmdir($fullDir);
                Response::json(['error' => 'ไฟล์ "' . h($origName) . '" ขนาดเกิน 1 GB'], 422);
            }

            // Detect MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmpNames[$i]);
            finfo_close($finfo);

            // Safe filename
            $safeName = bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
            $relPath = $transferDir . '/' . $safeName;
            $destPath = UPLOAD_DIR . $relPath;

            if (!move_uploaded_file($tmpNames[$i], $destPath)) {
                foreach ($uploaded as $u) {
                    @unlink(UPLOAD_DIR . $u['path']);
                }
                @rmdir($fullDir);
                Response::json(['error' => 'อัปโหลดไฟล์ไม่สำเร็จ'], 500);
            }

            $totalSize += $sizes[$i];
            $uploaded[] = [
                'name' => $origName,
                'path' => $relPath,
                'size' => $sizes[$i],
                'mime' => $mimeType ?: 'application/octet-stream',
            ];
        }

        if (empty($uploaded)) {
            @rmdir($fullDir);
            Response::json(['error' => 'ไม่มีไฟล์ที่อัปโหลดสำเร็จ'], 422);
        }

        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

        $transfer = FileTransfer::create(
            $userId,
            json_encode($uploaded, JSON_UNESCAPED_UNICODE),
            $totalSize,
            $expiresAt,
            $maxDownloads
        );

        $downloadUrl = APP_URL . '/transfer/download/' . $transfer['token'];

        Response::json([
            'ok'           => true,
            'id'           => $transfer['id'],
            'code'         => $transfer['code'],
            'token'        => $transfer['token'],
            'download_url' => $downloadUrl,
            'expires_at'   => $transfer['expires_at'],
            'files_count'  => count($uploaded),
            'total_size'   => $totalSize,
        ], 201);
    }

    // ------------------------------------------------------------------ //
    // API: List transfers for current user
    // ------------------------------------------------------------------ //
    public function apiList(): void
    {
        $userId = Auth::userId();
        $transfers = FileTransfer::listByUser($userId);

        // Add computed fields
        foreach ($transfers as &$t) {
            $t['is_expired'] = strtotime($t['expires_at']) <= time();
            $t['download_url'] = APP_URL . '/transfer/download/' . $t['token'];
        }

        Response::json(['transfers' => $transfers]);
    }

    // ------------------------------------------------------------------ //
    // API: Delete a transfer
    // ------------------------------------------------------------------ //
    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        $ok = FileTransfer::delete((int) $id, $userId);
        if (!$ok) Response::json(['error' => 'ไม่พบรายการ'], 404);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ //
    // API: Receive — lookup by code (no auth required)
    // ------------------------------------------------------------------ //
    public function apiReceive(): void
    {
        $code = trim(Request::input('code', ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            Response::json(['error' => 'กรุณาใส่รหัส 6 หลัก'], 422);
        }

        $transfer = FileTransfer::getByCode($code);

        if (!$transfer || !FileTransfer::isValid($transfer)) {
            Response::json(['error' => 'รหัสไม่ถูกต้อง หรือหมดอายุแล้ว'], 404);
        }

        $files = json_decode($transfer['files_json'], true) ?: [];
        $fileList = [];
        foreach ($files as $f) {
            $fileList[] = [
                'name' => $f['name'],
                'size' => $f['size'],
                'mime' => $f['mime'],
            ];
        }

        Response::json([
            'ok'           => true,
            'download_url' => APP_URL . '/transfer/download/' . $transfer['token'],
            'files'        => $fileList,
            'total_size'   => (int) $transfer['total_size'],
            'expires_at'   => $transfer['expires_at'],
            'files_count'  => count($fileList),
        ]);
    }

    // ------------------------------------------------------------------ //
    // Download files by token (no auth required)
    // ------------------------------------------------------------------ //
    public function download(string $token): void
    {
        $transfer = FileTransfer::getByToken($token);

        if (!$transfer || !FileTransfer::isValid($transfer)) {
            http_response_code(410);
            $pageTitle = 'ลิงก์หมดอายุ';
            // Simple error page
            echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
            echo '<title>ลิงก์หมดอายุ</title>';
            echo '<style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8f9fa;color:#1d1d1f;}';
            echo '.card{background:#fff;border-radius:16px;padding:48px 40px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06);}';
            echo '.code{font-size:3rem;font-weight:700;margin:0 0 12px;}';
            echo '.msg{color:#6e6e73;margin-bottom:24px;}';
            echo '.btn{display:inline-block;padding:12px 32px;background:#1d1d1f;color:#fff;border-radius:10px;text-decoration:none;font-weight:500;}</style>';
            echo '</head><body><div class="card"><div class="code">410</div>';
            echo '<div class="msg">ลิงก์นี้หมดอายุหรือไม่ถูกต้องแล้ว</div>';
            echo '<a href="' . h(APP_URL) . '/transfer" class="btn">ไปหน้าย้ายไฟล์</a>';
            echo '</div></body></html>';
            return;
        }

        $files = json_decode($transfer['files_json'], true) ?: [];

        // Increment download count
        FileTransfer::incrementDownload((int) $transfer['id']);

        if (count($files) === 1) {
            // Single file: stream directly
            $f = $files[0];
            $rootPath = realpath(UPLOAD_DIR);
            $fullPath = realpath(UPLOAD_DIR . $f['path']);
            if (!$rootPath || !$fullPath || !is_file($fullPath)
                || strncmp($fullPath, $rootPath . DIRECTORY_SEPARATOR, strlen($rootPath . DIRECTORY_SEPARATOR)) !== 0) {
                Response::abort(404, 'ไม่พบไฟล์บนเซิร์ฟเวอร์');
            }

            $filename = downloadFilename((string)$f['name'], 'download');
            header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('Content-Length: ' . filesize($fullPath));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            readfile($fullPath);
            exit;
        }

        // Multiple files: create a ZIP on-the-fly
        $tmpZip = tempnam(sys_get_temp_dir(), 'ft_dl_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Response::abort(500, 'ไม่สามารถสร้างไฟล์ ZIP ได้');
        }

        foreach ($files as $f) {
            $rootPath = realpath(UPLOAD_DIR);
            $fullPath = realpath(UPLOAD_DIR . $f['path']);
            if ($rootPath && $fullPath && is_file($fullPath)
                && strncmp($fullPath, $rootPath . DIRECTORY_SEPARATOR, strlen($rootPath . DIRECTORY_SEPARATOR)) === 0) {
                $zip->addFile($fullPath, $f['name']);
            }
        }
        $zip->close();

        $zipSize = filesize($tmpZip);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="transfer_' . $transfer['code'] . '.zip"');
        header('Content-Length: ' . $zipSize);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }
}
