<?php
// =====================================================
// controllers/FileController.php
// =====================================================

require_once ROOT . '/models/File.php';

class FileController
{
    private function validateName($name, string $kind = 'ไฟล์'): string
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

        Response::json(['files' => $files, 'breadcrumbs' => $breadcrumbs]);
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
        $id = FileModel::createFolder($userId, $name, $parentId);
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

        // Store with UUID filename
        $storageName = uuid4() . ($ext ? '.' . $ext : '');
        $subDir      = $userId . '/' . date('Y-m');
        $fullDir     = UPLOAD_DIR . $subDir;

        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $fullPath    = $fullDir . '/' . $storageName;
        $relPath     = $subDir  . '/' . $storageName;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            Response::json(['error' => 'บันทึกไฟล์ไม่สำเร็จ'], 500);
        }

        $id = FileModel::createFile($userId, $origName, $mimeType, $relPath, $file['size'], $parentId);
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

        FileModel::rename($fileId, $userId, $newName);
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

        $ok = FileModel::move($fileId, $userId, $newParentId);
        if (!$ok) Response::json(['error' => 'ไม่สามารถย้ายได้ (ตรวจสอบ loop ของโฟลเดอร์)'], 422);

        Response::json(['ok' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        $fileId = (int)$id;

        $file = FileModel::getById($fileId, $userId);
        if (!$file) Response::json(['error' => 'ไม่พบไฟล์'], 404);

        FileModel::delete($fileId, $userId);
        Response::json(['ok' => true]);
    }

    public function apiDownload(string $id): never
    {
        $userId = Auth::userId();
        $fileId = (int)$id;

        $file = FileModel::getById($fileId, $userId);
        if (!$file || $file['type'] !== 'file' || !$file['file_path']) {
            Response::abort(404, 'ไม่พบไฟล์');
        }

        $rootPath = realpath(UPLOAD_DIR);
        $fullPath = realpath(UPLOAD_DIR . $file['file_path']);
        if (!$rootPath || !$fullPath || !is_file($fullPath)
            || strncmp($fullPath, $rootPath . DIRECTORY_SEPARATOR, strlen($rootPath . DIRECTORY_SEPARATOR)) !== 0) {
            Response::abort(404, 'ไม่พบไฟล์บนเซิร์ฟเวอร์');
        }

        // Stream file
        $filename = addslashes($file['name']);
        header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        readfile($fullPath);
        exit;
    }

    public function apiFoldersList(): void
    {
        $userId  = Auth::userId();
        $folders = FileModel::allFolders($userId);
        Response::json(['folders' => $folders]);
    }
}
