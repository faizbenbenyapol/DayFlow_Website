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
        $menus = json_decode($share['menus'], true) ?: [];
        if (empty($menus)) {
            Response::abort(403, 'ไม่มีเมนูที่ถูกแชร์');
        }

        $firstMenu = $menus[0];
        Response::redirect('/' . $firstMenu);
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
        }

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

        // Validate expires_at format
        $expiresClean = null;
        if ($expiresAt && $expiresAt !== '') {
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
