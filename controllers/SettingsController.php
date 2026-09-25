<?php
// =====================================================
// controllers/SettingsController.php — the settings page, profile, password, appearance and Telegram
// =====================================================

// Devices and two-factor sign-in are in AccountSecurityController; backup,
// restore and account deletion in AccountDataController.

class SettingsController
{
    public function index(): void
    {
        $pageTitle   = 'ตั้งค่า';
        $pageScript  = 'settings';
        $pageStyle   = 'settings';
        $pageStyleExtra = 'shares';
        $loadQrLib   = true; // the two-factor setup renders an otpauth QR code

        $userId   = Auth::userId();
        $user     = User::findById($userId);
        $settings = User::getSettings($userId);

        require_once ROOT . '/models/DashboardLayout.php';
        $layout   = DashboardLayout::getForUser($userId);

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/settings/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function apiGet(): void
    {
        $userId   = Auth::userId();
        $user     = User::findById($userId);
        $settings = User::getSettings($userId);
        $settings['telegram_bot_token'] = !empty($settings['telegram_bot_token']) ? '••••••••' : '';
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

        $hash = User::passwordHash($userId);
        if ($hash === null || !User::verifyPassword($current, $hash)) {
            Response::json(['error' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'], 401);
        }

        User::updatePassword($userId, $newPw);
        // A password change invalidates every remembered login on other devices.
        RememberToken::revokeAll($userId);
        setcookie(RememberToken::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        Response::json(['ok' => true]);
    }

    public function apiTheme(): void
    {
        $userId = Auth::userId();
        $theme  = Request::input('theme', 'light');

        if (!in_array($theme, User::THEMES, true)) {
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
        $order  = Request::input('order', []);

        if (!is_array($menus) || !is_array($order)) {
            Response::json(['error' => 'รูปแบบข้อมูลไม่ถูกต้อง'], 422);
        }

        $allowedMenus = ['projects', 'tasks', 'notes', 'planner', 'focus', 'exercise', 'food-notes', 'finance', 'subscriptions', 'stocks', 'ai', 'file-tools', 'transfer', 'files', 'quick-notes', 'bookmarks'];
        $menus = array_map('strval', $menus);
        $order = array_map('strval', $order);
        $hiddenMenus = [];
        foreach ($allowedMenus as $m) {
            if (!in_array($m, $menus)) {
                $hiddenMenus[] = $m;
            }
        }

        $normalizedOrder = [];
        foreach ($order as $menu) {
            if (in_array($menu, $allowedMenus, true) && !in_array($menu, $normalizedOrder, true)) {
                $normalizedOrder[] = $menu;
            }
        }
        foreach ($allowedMenus as $menu) {
            if (!in_array($menu, $normalizedOrder, true)) {
                $normalizedOrder[] = $menu;
            }
        }

        $json = json_encode($hiddenMenus);
        $orderJson = json_encode($normalizedOrder);
        $db = DB::conn();
        try {
            $db->beginTransaction();
            User::updateHiddenMenus($userId, $json);
            User::updateMenuOrder($userId, $orderJson);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::json(['error' => 'บันทึกการตั้งค่าเมนูไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'], 500);
        }

        Response::json(['ok' => true, 'hidden_menus' => $hiddenMenus, 'menu_order' => $normalizedOrder]);
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

        if (empty($botToken)) {
            $saved = User::getSettings(Auth::userId());
            $botToken = User::decryptTelegramToken($saved['telegram_bot_token'] ?? '');
        }

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
        Response::json(['error' => 'งานแจ้งเตือนทำงานผ่าน Docker worker เท่านั้น'], 410);
    }
}
