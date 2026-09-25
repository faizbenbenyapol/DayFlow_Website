<?php
// =====================================================
// controllers/AccountDataController.php — personal backup and account deletion
// =====================================================

class AccountDataController
{
    public function apiExport(): void
    {
        $userId = Auth::userId();
        $user   = User::findById($userId);
        $data   = AccountData::export($userId);

        $username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $user['username'] ?? 'user');
        $filename = 'my-data-' . $username . '-' . date('Ymd-His') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: ' . contentDisposition($filename));
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function apiImport(): void
    {
        $userId = Auth::userId();

        if (empty($_FILES['file'])) {
            Response::json(['error' => 'ไม่พบไฟล์ที่อัปโหลด'], 422);
        }

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'การอัปโหลดไฟล์ล้มเหลว'], 422);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            Response::json(['error' => 'กรุณาอัปโหลดไฟล์รูปแบบ JSON เท่านั้น'], 422);
        }

        $content = file_get_contents($file['tmp_name']);
        $data = json_decode($content, true);

        if (!is_array($data)) {
            Response::json(['error' => 'ข้อมูลในไฟล์ JSON ไม่ถูกต้อง หรือเสียหาย'], 422);
        }

        try {
            $imported = AccountData::import($userId, $data);
            Response::json(['ok' => true, 'imported' => $imported, 'total' => array_sum($imported)]);
        } catch (\InvalidArgumentException $e) {
            // AccountData words these for the user; the cause goes to the log.
            if ($e->getPrevious()) error_log('Import rejected: ' . $e->getPrevious()->getMessage());
            Response::json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            Response::json(['error' => 'นำเข้าข้อมูลไม่สำเร็จ'], 500);
        }
    }

    public function apiDeleteAccount(): void
    {
        $userId   = Auth::userId();
        $password = Request::rawInput('password', '');
        $confirm  = Request::rawInput('confirm_text', '');

        if (empty($password)) {
            Response::json(['error' => 'กรุณากรอกรหัสผ่าน'], 422);
        }
        if ($confirm !== 'DELETE') {
            Response::json(['error' => 'กรุณาพิมพ์ DELETE เพื่อยืนยัน'], 422);
        }

        $hash = User::passwordHash($userId);
        if ($hash === null || !User::verifyPassword($password, $hash)) {
            Response::json(['error' => 'รหัสผ่านไม่ถูกต้อง'], 401);
        }

        User::deleteAccount($userId);
        Auth::logout();
        Response::json(['ok' => true]);
    }
}
