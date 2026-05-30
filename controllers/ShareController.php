<?php
// =====================================================
// controllers/ShareController.php
// =====================================================

require_once ROOT . '/models/File.php';
require_once ROOT . '/models/FileShare.php';

class ShareController
{
    // ------------------------------------------------------------------ //
    // Public share page (no login required)
    // ------------------------------------------------------------------ //
    public function viewShare(string $token): void
    {
        $share = FileShare::getByToken($token);

        if (!$share || !FileShare::isValid($share)) {
            $pageTitle = 'ลิงก์หมดอายุ';
            http_response_code(410);
            require ROOT . '/views/share/expired.php';
            return;
        }

        $file = FileModel::getByIdPublic((int)$share['file_id']);
        if (!$file) {
            $pageTitle = 'ไม่พบไฟล์';
            http_response_code(404);
            require ROOT . '/views/share/expired.php';
            return;
        }

        // For folders: list children
        $children = [];
        if ($file['type'] === 'folder') {
            $children = FileModel::listFolderPublic((int)$file['id']);
        }

        $pageTitle = 'แชร์: ' . $file['name'];
        require ROOT . '/views/share/index.php';
    }

    // ------------------------------------------------------------------ //
    // Public file download via share token
    // ------------------------------------------------------------------ //
    public function publicDownload(string $token, string $fileId): never
    {
        $share = FileShare::getByToken($token);

        if (!$share || !FileShare::isValid($share) || $share['permission'] !== 'download') {
            Response::abort(403, 'ไม่มีสิทธิ์ดาวน์โหลด');
        }

        $fid  = (int)$fileId;
        $file = FileModel::getByIdPublic($fid);

        // File must be within the shared folder or be the shared file itself
        if (!$file || $file['type'] !== 'file') {
            Response::abort(404, 'ไม่พบไฟล์');
        }

        // If the share is a file share, only allow that file
        $sharedFile = FileModel::getByIdPublic((int)$share['file_id']);
        if ($sharedFile['type'] === 'file' && $fid !== (int)$share['file_id']) {
            Response::abort(403, 'ไม่มีสิทธิ์ดาวน์โหลด');
        }

        // If the share is a folder share, verify file is in that folder
        if ($sharedFile['type'] === 'folder' && (int)$file['parent_id'] !== (int)$share['file_id']) {
            Response::abort(403, 'ไม่มีสิทธิ์ดาวน์โหลด');
        }

        $fullPath = UPLOAD_DIR . $file['file_path'];
        if (!$file['file_path'] || !file_exists($fullPath)) {
            Response::abort(404, 'ไม่พบไฟล์บนเซิร์ฟเวอร์');
        }

        $filename = addslashes($file['name']);
        header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($fullPath);
        exit;
    }

    // ------------------------------------------------------------------ //
    // API: list all shares for logged-in user
    // ------------------------------------------------------------------ //
    public function apiList(): void
    {
        $userId = Auth::userId();
        $shares = FileShare::listByUser($userId);
        Response::json(['shares' => $shares]);
    }

    // ------------------------------------------------------------------ //
    // API: create a new share link
    // ------------------------------------------------------------------ //
    public function apiCreate(): void
    {
        $userId     = Auth::userId();
        $fileId     = (int)Request::input('file_id', 0);
        $label      = trim(Request::input('label', ''));
        $permission = Request::input('permission', 'view');
        $expiresAt  = Request::input('expires_at', null);

        if (!$fileId) Response::json(['error' => 'กรุณาเลือกไฟล์หรือโฟลเดอร์'], 422);
        if (!in_array($permission, ['view', 'download'])) Response::json(['error' => 'สิทธิ์ไม่ถูกต้อง'], 422);

        // Verify file belongs to this user
        $file = FileModel::getById($fileId, $userId);
        if (!$file) Response::json(['error' => 'ไม่พบไฟล์'], 404);

        // Validate expires_at format
        $expiresClean = null;
        if ($expiresAt && $expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if (!$ts || $ts <= time()) Response::json(['error' => 'วันหมดอายุต้องเป็นอนาคต'], 422);
            $expiresClean = date('Y-m-d H:i:s', $ts);
        }

        $token = FileShare::create($userId, $fileId, $label, $permission, $expiresClean);
        $link  = APP_URL . '/share/' . $token;

        Response::json(['ok' => true, 'token' => $token, 'link' => $link], 201);
    }

    // ------------------------------------------------------------------ //
    // API: update a share link
    // ------------------------------------------------------------------ //
    public function apiUpdate(string $id): void
    {
        $userId     = Auth::userId();
        $shareId    = (int)$id;
        $label      = trim(Request::input('label', ''));
        $permission = Request::input('permission', 'view');
        $expiresAt  = Request::input('expires_at', null);

        if (!in_array($permission, ['view', 'download'])) Response::json(['error' => 'สิทธิ์ไม่ถูกต้อง'], 422);

        $expiresClean = null;
        if ($expiresAt && $expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if (!$ts) Response::json(['error' => 'รูปแบบวันที่ไม่ถูกต้อง'], 422);
            $expiresClean = date('Y-m-d H:i:s', $ts);
        }

        $ok = FileShare::update($shareId, $userId, $label, $permission, $expiresClean);
        if (!$ok) Response::json(['error' => 'ไม่พบลิงก์แชร์'], 404);

        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ //
    // API: delete a share link
    // ------------------------------------------------------------------ //
    public function apiDelete(string $id): void
    {
        $userId  = Auth::userId();
        $shareId = (int)$id;

        $ok = FileShare::delete($shareId, $userId);
        if (!$ok) Response::json(['error' => 'ไม่พบลิงก์แชร์'], 404);

        Response::json(['ok' => true]);
    }
}
