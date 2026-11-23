<?php
// =====================================================
// controllers/AppShareController.php
// =====================================================

require_once ROOT . '/models/AppShare.php';

class AppShareController
{
    // ------------------------------------------------------------------ //
    // Public: Entry point for a share link
    // ------------------------------------------------------------------ //
    public function viewShared(string $token): void
    {
        $share = AppShare::getByToken($token);

        if (!$share || !AppShare::isValid($share)) {
            $pageTitle = 'ลิงก์หมดอายุ';
            http_response_code(410);
            require ROOT . '/views/share/expired.php';
            return;
        }

        // Set token in session
        $_SESSION['app_share_token'] = $token;

        // Redirect to the first allowed menu
        $menus = AppShare::sanitizeMenus(json_decode($share['menus'], true) ?: []);
        if (empty($menus)) {
            Response::abort(403, 'ไม่มีเมนูที่ถูกแชร์');
        }

        // Carry the token in the URL (not just the session) so that a link
        // saved as a mobile home-screen shortcut can restore share mode on
        // its own, even if the session cookie was cleared in the meantime.
        $firstMenu = $menus[0];
        Response::redirect('/' . $firstMenu . '?share=' . rawurlencode($token));
    }

    /**
     * manifest ของแอปลิงก์แชร์: ติดตั้งจากหน้าแชร์แล้วได้ไอคอน "DayFlow · <ชื่อลิงก์>"
     * ที่เปิดกลับมาที่ /shared/<token> เสมอ แม้ cookie ถูกล้าง (token อยู่ใน start_url)
     * id ต่างจากแอปหลัก ไอคอน DayFlow ของเจ้าของจึงอยู่คู่กันได้ไม่ทับกัน
     * ลิงก์ไม่มีหรือหมดอายุ: ตอบ 404 (ไอคอนที่ติดตั้งไปแล้วยังเปิดเจอหน้า "ลิงก์หมดอายุ" ของ viewShared)
     */
    public function manifest(string $token): void
    {
        $share = AppShare::getByToken($token);
        if (!$share || !AppShare::isValid($share)) {
            Response::json(['error' => 'not found'], 404);
        }

        // เริ่มจาก manifest ของแอปหลัก แล้วเปลี่ยนเฉพาะส่วนที่ทำให้เป็นแอปของลิงก์นี้
        $manifest = json_decode((string)file_get_contents(PUBLIC_ROOT . '/manifest.json'), true);
        $home = APP_URL . '/shared/' . rawurlencode($token);
        $manifest['id'] = $home;
        $manifest['name'] = 'DayFlow · ' . $share['label'];
        $manifest['short_name'] = $share['label'];
        $manifest['start_url'] = $home;
        $manifest['scope'] = APP_URL . '/';
        // path ของไอคอนใน manifest.json เป็นแบบสัมพัทธ์ ซึ่งจะชี้ผิดที่เมื่อเสิร์ฟจาก /shared/<token>/
        foreach ($manifest['icons'] as &$icon) {
            $icon['src'] = APP_URL . '/' . $icon['src'];
        }
        unset($icon);

        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: no-cache');
        echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ //
    // Public: Exit share mode
    // ------------------------------------------------------------------ //
    public function exitShare(): void
    {
        unset($_SESSION['app_share_token']);
        Response::redirect('/');
    }

    // ------------------------------------------------------------------ //
    // API: list all app shares for logged-in user
    // ------------------------------------------------------------------ //
    public function apiList(): void
    {
        $userId = Auth::userId();
        $shares = AppShare::listByUser($userId);
        
        // Decode JSON menus for frontend
        foreach ($shares as &$s) {
            $s['menus'] = json_decode($s['menus'], true) ?: [];
            // Decided here: expires_at is the server's wall clock, which a
            // browser in another time zone would read hours off.
            $s['is_expired'] = !empty($s['expires_at']) && strtotime($s['expires_at']) <= time();
        }
        unset($s);

        Response::json(['shares' => $shares]);
    }

    // ------------------------------------------------------------------ //
    // API: create a new app share link
    // ------------------------------------------------------------------ //
    public function apiCreate(): void
    {
        $userId     = Auth::userId();
        $label      = trim(Request::input('label', ''));
        $menus      = Request::input('menus', []);
        $expiresAt  = Request::input('expires_at', null);

        if (empty($label)) Response::json(['error' => 'กรุณาระบุชื่อลิงก์แชร์'], 422);
        if (empty($menus) || !is_array($menus)) Response::json(['error' => 'กรุณาเลือกอย่างน้อย 1 เมนู'], 422);
        $menus = AppShare::sanitizeMenus($menus);
        if (empty($menus)) Response::json(['error' => 'เมนูที่เลือกไม่ถูกต้อง'], 422);

        // Validate expires_at format
        $expiresClean = null;
        if ($expiresAt) {
            $ts = strtotime($expiresAt);
            if (!$ts || $ts <= time()) Response::json(['error' => 'วันหมดอายุต้องเป็นอนาคต'], 422);
            $expiresClean = date('Y-m-d H:i:s', $ts);
        }

        $token = AppShare::create($userId, $label, $menus, $expiresClean);
        $link  = APP_URL . '/shared/' . $token;

        Response::json(['ok' => true, 'token' => $token, 'link' => $link], 201);
    }

    // ------------------------------------------------------------------ //
    // API: delete an app share link
    // ------------------------------------------------------------------ //
    public function apiDelete(string $id): void
    {
        $userId  = Auth::userId();
        $shareId = (int)$id;

        $ok = AppShare::delete($shareId, $userId);
        if (!$ok) Response::json(['error' => 'ไม่พบลิงก์แชร์'], 404);

        Response::json(['ok' => true]);
    }
}
