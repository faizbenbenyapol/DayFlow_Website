<?php
// =====================================================
// controllers/FileController.php
// =====================================================

require_once ROOT . '/models/File.php';
require_once ROOT . '/core/FileStore.php';

class FileController
{
    /**
     * ตอบ error ของ Google Drive เป็นภาษาไทยที่บอกว่าทำอะไรต่อ (ไม่ใช่ข้อความดิบของ Google) แล้วจบคำขอ
     * สิทธิ์หลุดถูกบันทึกให้เมนูไฟล์ขึ้นแถบ "เชื่อมใหม่" (EDGE-11)
     */
    private function driveFailed(FileStore $store, DriveError $error): never
    {
        $store->noteFailure($error);
        Response::json(['error' => match ($error->kind) {
            DriveError::AUTH      => $store->isDisconnected()
                ? 'ไฟล์นี้อยู่ใน Google Drive ที่ยกเลิกการเชื่อมแล้ว เชื่อมบัญชีเดิมในหน้าตั้งค่าก่อน'
                : 'การเชื่อม Google Drive หลุด กดเชื่อมใหม่ในหน้าตั้งค่า',
            DriveError::QUOTA     => 'Google Drive เต็ม ลบไฟล์ใน Drive หรือเพิ่มพื้นที่ก่อน',
            DriveError::NOT_FOUND => 'ไฟล์นี้ถูกลบจาก Google Drive แล้ว',
            default               => 'ติดต่อ Google Drive ไม่ได้ตอนนี้ ลองอีกครั้ง',
        }, 'drive' => $error->kind], $error->kind === DriveError::OTHER ? 502 : 409);
    }

    private function validateName(mixed $name, string $kind = 'ไฟล์'): string
    {
        $name = trim(basename((string)$name));
        if ($name === '' || $name === '.' || $name === '..' || mb_strlen($name) > 255) {
            Response::json(['error' => 'ชื่อ' . $kind . 'ไม่ถูกต้อง'], 422);
        }
        return $name;
    }

    private function validateParent(int $userId, ?int $parentId): void
    {
        if ($parentId === null) return;
        $parent = FileModel::getById($parentId, $userId);
        if (!$parent || $parent['type'] !== 'folder') Response::json(['error' => 'โฟลเดอร์ปลายทางไม่ถูกต้อง'], 422);
    }

    public function index(): void
    {
        $pageTitle  = 'ไฟล์';
        $pageStyle  = 'files';
        $pageScript = 'files';
        $parentId   = null;

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/files/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function folder(string $id): void
    {
        $pageTitle  = 'ไฟล์';
        $pageStyle  = 'files';
        $pageScript = 'files';
        $parentId   = (int)$id;

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/files/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    // --- API ---

    public function apiList(): void
    {
        $userId   = Auth::userId();
        $parentId = Request::query('parent_id');
        $parentId = ($parentId !== null && $parentId !== '') ? (int)$parentId : null;

        $files       = FileModel::listFolder($userId, $parentId);
        $breadcrumbs = $parentId ? FileModel::getBreadcrumbs($parentId, $userId) : [];
        $link        = DriveLink::find($userId);

        Response::json(['files' => $files, 'breadcrumbs' => $breadcrumbs, 'drive' => [
            'state'  => $link['state'] ?? 'none',
            'usable' => DriveLink::isUsable($link),
        ]]);
    }

    public function apiFolder(): void
    {
        $userId   = Auth::userId();
        $name     = Request::input('name', '');
        $parentId = Request::input('parent_id');
        $parentId = ($parentId !== null && $parentId !== '') ? (int)$parentId : null;

        if (empty($name)) Response::json(['error' => 'กรุณากรอกชื่อโฟลเดอร์'], 422);

        $name = $this->validateName($name, 'โฟลเดอร์');
        $this->validateParent($userId, $parentId);
        $store = FileStore::forUser($userId);
        try {
            $id = $store->createFolder($name, $parentId);
        } catch (DriveError $e) {
            $this->driveFailed($store, $e);
        }
        Response::json(['ok' => true, 'id' => $id], 201);
    }

    public function apiUpload(): void
    {
        $userId   = Auth::userId();
        $parentId = Request::input('parent_id');
        $parentId = ($parentId !== null && $parentId !== '') ? (int)$parentId : null;

        $this->validateParent($userId, $parentId);

        if (empty($_FILES['file'])) {
            Response::json(['error' => 'ไม่พบไฟล์'], 422);
        }

        $file = $_FILES['file'];
        if (!is_uploaded_file($file['tmp_name'])) {
            Response::json(['error' => 'ไฟล์อัปโหลดไม่ถูกต้อง'], 422);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'อัปโหลดไม่สำเร็จ: ' . $file['error']], 422);
        }

        if ($file['size'] > MAX_UPLOAD_BYTES) {
            Response::json(['error' => 'ไฟล์ขนาดใหญ่เกินกว่าที่กำหนด (' . formatBytes(MAX_UPLOAD_BYTES) . ')'], 422);
        }

        // Validate MIME type using finfo (not trusting client)
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']) ?: 'application/octet-stream';
        finfo_close($finfo);

        // Block dangerous types
        $blocked = ['application/x-php', 'text/x-php', 'application/x-httpd-php',
                    'application/x-executable', 'text/html'];
        if (in_array($mimeType, $blocked)) {
            Response::json(['error' => 'ประเภทไฟล์นี้ไม่อนุญาต'], 422);
        }

        // Get original name safely
        $origName = basename($file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // Block script extensions regardless of MIME
        $blockedExt = ['php','php3','php4','php5','php7','php8','phtml','phar',
                       'cgi','pl','py','sh','rb','asp','aspx','jsp'];
        if (in_array($ext, $blockedExt)) {
            Response::json(['error' => 'นามสกุลไฟล์นี้ไม่อนุญาต'], 422);
        }

        // เก็บบนเซิร์ฟเวอร์ หรือใน Google Drive ของผู้ใช้ถ้าเชื่อมอยู่ (REQ-21, REQ-23)
        $store = FileStore::forUser($userId);
        try {
            $id = $store->upload($file['tmp_name'], $origName, $mimeType, (int)$file['size'], $parentId);
        } catch (DriveError $e) {
            $this->driveFailed($store, $e);
        } catch (RuntimeException) {
            Response::json(['error' => 'บันทึกไฟล์ไม่สำเร็จ'], 500);
        }
        Response::json(['ok' => true, 'id' => $id], 201);
    }

    public function apiRename(string $id): void
    {
        $userId  = Auth::userId();
        $fileId  = (int)$id;
        $newName = Request::input('name', '');

        if (!$newName) Response::json(['error' => 'กรุณากรอกชื่อใหม่'], 422);

        $newName = $this->validateName($newName, 'ไฟล์');
        $file = FileModel::getById($fileId, $userId);
        if (!$file) Response::json(['error' => 'ไม่พบไฟล์'], 404);

        $store = FileStore::forUser($userId);
        try {
            $store->rename($file, $newName);
        } catch (DriveError $e) {
            $this->driveFailed($store, $e);
        }
        Response::json(['ok' => true]);
    }

    public function apiMove(string $id): void
    {
        $userId      = Auth::userId();
        $fileId      = (int)$id;
        $rawParent   = Request::input('parent_id');
        $newParentId = ($rawParent !== null && $rawParent !== '') ? (int)$rawParent : null;
        $this->validateParent($userId, $newParentId);

        $file = FileModel::getById($fileId, $userId);
        if (!$file) Response::json(['error' => 'ไม่พบไฟล์'], 404);

        $store = FileStore::forUser($userId);
        try {
            $ok = $store->move($file, $newParentId);
        } catch (DriveError $e) {
            $this->driveFailed($store, $e);
        }
        if (!$ok) Response::json(['error' => 'ย้ายโฟลเดอร์ไปไว้ในตัวเองหรือโฟลเดอร์ย่อยของมันไม่ได้'], 422);

        Response::json(['ok' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        $fileId = (int)$id;

        $file = FileModel::getById($fileId, $userId);
        if (!$file) Response::json(['error' => 'ไม่พบไฟล์'], 404);

        $store = FileStore::forUser($userId);
        try {
            $store->delete($file);
        } catch (DriveError $e) {
            $this->driveFailed($store, $e);
        }
        Response::json(['ok' => true]);
    }

    public function apiDownload(string $id): never
    {
        $userId = Auth::userId();
        $fileId = (int)$id;

        $file = FileModel::getById($fileId, $userId);
        if (!$file || $file['type'] !== 'file' || (!$file['file_path'] && !$file['drive_id'])) {
            Response::abort(404, 'ไม่พบไฟล์');
        }

        FileStore::send($file, [
            'Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'),
            'Content-Disposition: ' . contentDisposition((string)$file['name']),
            'X-Content-Type-Options: nosniff',
            'Cache-Control: no-store',
        ]);
    }

    public function apiFoldersList(): void
    {
        $userId  = Auth::userId();
        $folders = FileModel::allFolders($userId);
        Response::json(['folders' => $folders]);
    }
}
