<?php
// =====================================================
// controllers/StockScreenshotController.php — the portfolio screenshot
// =====================================================

class StockScreenshotController
{
    /** Image types a screenshot may be, with the extension each is stored under. */
    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function apiList(): void
    {
        Response::json(['screenshots' => Stock::listScreenshots(Auth::userId())]);
    }

    /** Stores a new screenshot in place of the previous one: a user keeps a single image. */
    public function apiUpload(): void
    {
        $userId = Auth::userId();
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'อัปโหลดไฟล์ไม่สำเร็จ'], 400);
        }

        // The browser's Content-Type and filename are the uploader's to choose,
        // so both come from the bytes instead: an .html sent as image/png was
        // stored as .html and rendered as a page of this site.
        $mimeType = self::detectedImageMime($file['tmp_name']);
        if ($mimeType === null || @getimagesize($file['tmp_name']) === false) {
            Response::json(['error' => 'อนุญาตเฉพาะไฟล์รูปภาพ (JPG, PNG, WEBP, GIF) เท่านั้น'], 422);
        }
        if ($file['size'] > MAX_UPLOAD_BYTES) {
            Response::json(['error' => 'ขนาดไฟล์เกินที่กำหนด (สูงสุด ' . formatBytes(MAX_UPLOAD_BYTES) . ')'], 422);
        }

        foreach (Stock::listScreenshots($userId) as $old) {
            Stock::deleteScreenshot((int)$old['id'], $userId);
            self::unlinkUpload($old['file_path']);
        }

        $subDir  = 'stocks/' . $userId;
        $relPath = $subDir . '/' . uuid4() . '.' . self::IMAGE_TYPES[$mimeType];
        if (!is_dir(UPLOAD_DIR . $subDir)) mkdir(UPLOAD_DIR . $subDir, 0755, true);

        if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $relPath)) {
            Response::json(['error' => 'บันทึกไฟล์ไม่สำเร็จ'], 500);
        }

        $id = Stock::createScreenshot(
            $userId, $file['name'], $relPath, $file['size'], $mimeType, Request::input('description', '')
        );
        Response::json(['ok' => true, 'screenshot' => Stock::getScreenshotById($id, $userId)], 201);
    }

    /**
     * GET /api/stocks/screenshots/{id}/image
     *
     * uploads/ is closed to direct requests, so the image is streamed from
     * here, scoped to its owner (or a share link covering stocks). The type
     * is re-detected rather than read from the row: screenshots stored before
     * uploads were checked carry whatever the uploader claimed.
     */
    public function apiImage(string $id): void
    {
        $scr = Stock::getScreenshotById((int)$id, Auth::userId());
        if (!$scr) Response::json(['error' => 'ไม่พบรูปภาพ'], 404);

        $rootPath = realpath(UPLOAD_DIR);
        $fullPath = realpath(UPLOAD_DIR . $scr['file_path']);
        if (!$rootPath || !$fullPath || !is_file($fullPath)
            || strncmp($fullPath, $rootPath . DIRECTORY_SEPARATOR, strlen($rootPath . DIRECTORY_SEPARATOR)) !== 0) {
            Response::json(['error' => 'ไม่พบรูปภาพ'], 404);
        }

        $mimeType = self::detectedImageMime($fullPath);
        if ($mimeType === null) Response::json(['error' => 'ไม่พบรูปภาพ'], 404);

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($fullPath));
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        readfile($fullPath);
        exit;
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        $scr    = Stock::getScreenshotById((int)$id, $userId);
        if (!$scr) Response::json(['error' => 'ไม่พบรูปภาพ'], 404);

        Stock::deleteScreenshot((int)$id, $userId);
        self::unlinkUpload($scr['file_path']);
        Response::json(['ok' => true]);
    }

    /** The file's real type when it is one of IMAGE_TYPES, otherwise null. */
    private static function detectedImageMime(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? finfo_file($finfo, $path) : false;
        if ($finfo) finfo_close($finfo);
        return is_string($mime) && isset(self::IMAGE_TYPES[$mime]) ? $mime : null;
    }

    private static function unlinkUpload(string $relPath): void
    {
        $fullPath = UPLOAD_DIR . $relPath;
        if (is_file($fullPath)) @unlink($fullPath);
    }
}
