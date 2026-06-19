<?php
// =====================================================
// controllers/SettingsController.php
// =====================================================

require_once ROOT . '/models/User.php';

class SettingsController
{
    public function index(): void
    {
        $pageTitle   = 'ตั้งค่า';
        $pageScript  = 'settings';
        $pageStyle   = 'settings';
        $pageStyleExtra = 'shares';

        $userId   = Auth::userId();
        $user     = User::findById($userId);
        $settings = User::getSettings($userId);

        require_once ROOT . '/models/DashboardLayout.php';
        $layout   = DashboardLayout::getForUser($userId);

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/settings/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    // --- API ---

    public function apiGet(): void
    {
        $userId   = Auth::userId();
        $user     = User::findById($userId);
        $settings = User::getSettings($userId);
        Response::json(['user' => $user, 'settings' => $settings]);
    }

    public function apiProfile(): void
    {
        $userId      = Auth::userId();
        $displayName = Request::input('display_name', '');
        $email       = Request::input('email', '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['error' => 'รูปแบบอีเมลไม่ถูกต้อง'], 422);
        }

        if (User::emailExists($email, $userId)) {
            Response::json(['error' => 'อีเมลนี้ถูกใช้งานแล้ว'], 422);
        }

        User::updateProfile($userId, $displayName ?: 'ผู้ใช้งาน', $email);

        // Update session display name
        $_SESSION['display_name'] = $displayName ?: 'ผู้ใช้งาน';

        Response::json(['ok' => true]);
    }

    public function apiPassword(): void
    {
        $userId  = Auth::userId();
        $current = Request::rawInput('current_password', '');
        $newPw   = Request::rawInput('new_password', '');
        $confirm = Request::rawInput('confirm_password', '');

        if (empty($current) || empty($newPw) || empty($confirm)) {
            Response::json(['error' => 'กรุณากรอกข้อมูลให้ครบ'], 422);
        }

        if ($newPw !== $confirm) {
            Response::json(['error' => 'รหัสผ่านใหม่ไม่ตรงกัน'], 422);
        }

        if (strlen($newPw) < 8) {
            Response::json(['error' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'], 422);
        }

        $user = User::findById($userId);
        if (!$user || !User::verifyPassword($current, $user['password_hash'])) {
            Response::json(['error' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'], 401);
        }

        User::updatePassword($userId, $newPw);
        Response::json(['ok' => true]);
    }

    public function apiTheme(): void
    {
        $userId = Auth::userId();
        $theme  = Request::input('theme', 'light');

        if (!in_array($theme, ['light', 'dark', 'soft', 'lavender', 'ocean', 'peach'])) {
            Response::json(['error' => 'ธีมไม่ถูกต้อง'], 422);
        }

        User::updateTheme($userId, $theme);
        Response::json(['ok' => true, 'theme' => $theme]);
    }

    public function apiTimezone(): void
    {
        $userId = Auth::userId();
        $tz     = Request::input('timezone', '');

        // Validate against PHP's timezone list
        if (!$tz || !in_array($tz, timezone_identifiers_list(), true)) {
            Response::json(['error' => 'เขตเวลาไม่ถูกต้อง'], 422);
        }

        User::updateTimezone($userId, $tz);
        Response::json(['ok' => true, 'timezone' => $tz]);
    }

    public function apiMenus(): void
    {
        $userId = Auth::userId();
        $menus  = Request::input('menus', []);

        if (!is_array($menus)) {
            Response::json(['error' => 'รูปแบบข้อมูลไม่ถูกต้อง'], 422);
        }

        $allowedMenus = ['tasks', 'notes', 'planner', 'exercise', 'food-notes', 'finance', 'subscriptions', 'stocks', 'ai', 'file-tools', 'files', 'transfer', 'focus'];
        $hiddenMenus = [];
        foreach ($allowedMenus as $m) {
            if (!in_array($m, $menus)) {
                $hiddenMenus[] = $m;
            }
        }

        $json = json_encode($hiddenMenus);
        User::updateHiddenMenus($userId, $json);

        Response::json(['ok' => true, 'hidden_menus' => $hiddenMenus]);
    }

    public function apiTelegram(): void
    {
        $userId = Auth::userId();
        $botToken = Request::input('telegram_bot_token', '');
        $chatId = Request::input('telegram_chat_id', '');
        
        $notifyEvents = Request::input('telegram_notify_events', []);
        $jsonEvents = empty($notifyEvents) ? null : json_encode($notifyEvents);

        User::updateTelegramSettings($userId, $botToken, $chatId, $jsonEvents);
        Response::json(['ok' => true]);
    }

    public function apiTelegramTest(): void
    {
        $botToken = Request::input('telegram_bot_token', '');
        $chatId = Request::input('telegram_chat_id', '');

        if (empty($botToken) || empty($chatId)) {
            Response::json(['error' => 'กรุณากรอก Bot Token และ Chat ID ก่อนทดสอบ'], 422);
        }

        require_once ROOT . '/core/TelegramService.php';
        $msg = TelegramService::formatMessage(
            "✅ การทดสอบระบบแจ้งเตือน",
            [
                'บริการ' => 'Telegram Bot',
                'การเชื่อมต่อ' => 'สำเร็จ'
            ],
            'พร้อมใช้งาน'
        );
        $success = TelegramService::sendMessage($botToken, $chatId, $msg);

        if ($success) {
            Response::json(['ok' => true]);
        } else {
            Response::json(['error' => 'ส่งข้อความไม่สำเร็จ กรุณาตรวจสอบ Token และ Chat ID อีกครั้ง'], 500);
        }
    }

    public function apiCronTest(): void
    {
        ob_start();
        require_once ROOT . '/cron.php';
        $output = ob_get_clean();

        Response::json(['ok' => true, 'output' => $output]);
    }

    public function apiExport(): void
    {
        $userId = Auth::userId();
        $user   = User::findById($userId);
        $data   = User::exportAllData($userId);

        $username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $user['username'] ?? 'user');
        $filename = 'my-data-' . $username . '-' . date('Ymd-His') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
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

        if ($data === null) {
            Response::json(['error' => 'ข้อมูลในไฟล์ JSON ไม่ถูกต้อง หรือเสียหาย'], 422);
        }

        try {
            User::importAllData($userId, $data);
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            Response::json(['error' => 'นำเข้าข้อมูลไม่สำเร็จ: ' . $e->getMessage()], 500);
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

        $user = User::findById($userId);
        // findById doesn't return password_hash — refetch with email
        $full = User::findByEmail($user['email'] ?? '');
        if (!$full || !User::verifyPassword($password, $full['password_hash'])) {
            Response::json(['error' => 'รหัสผ่านไม่ถูกต้อง'], 401);
        }

        User::deleteAccount($userId);
        Auth::logout();
        Response::json(['ok' => true]);
    }
}
