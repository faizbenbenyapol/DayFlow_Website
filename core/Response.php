<?php
// =====================================================
// core/Response.php — HTTP Response Helpers
// =====================================================

class Response
{
    /**
     * Send JSON response and exit
     */
    public static function json($data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    /**
     * Redirect and exit
     */
    public static function redirect(string $path): never
    {
        $url = (strpos($path, 'http') === 0) ? $path : APP_URL . $path;
        header('Location: ' . $url);
        exit;
    }

    /**
     * Abort with error (JSON for API, page for browser)
     */
    public static function abort(int $status, string $message = ''): never
    {
        if (Request::isApi()) {
            self::json(['error' => $message ?: 'Error ' . $status], $status);
        }
        http_response_code($status);

        $signedIn = !empty($_SESSION['user_id']);
        $isReadOnly = false;
        $sharedMenus = [];
        // Someone who is not signed in has no saved preference: follow the device.
        $outsideTheme = 'auto';
        $outsideDayColor = 'on';
        if (class_exists('Auth')) {
            if ($signedIn) {
                $outsideTheme = Auth::theme();
                $outsideDayColor = Auth::appearance()['day_color'] ? 'on' : 'off';
            }
            $isReadOnly = Auth::isReadOnly();
            $sharedMenus = $isReadOnly ? Auth::getSharedMenus() : [];
        }

        if ($message === '') {
            $message = match (true) {
                $status === 403 => 'ไม่มีสิทธิ์เข้าถึงเมนูนี้',
                $status === 404 => 'ไม่พบหน้าที่ต้องการ',
                default         => 'เกิดข้อผิดพลาดในการประมวลผล',
            };
        }

        $text = match (true) {
            $status === 403 && $isReadOnly => 'ลิงก์ที่แชร์นี้เปิดให้ดูเฉพาะบางหน้าตามที่เจ้าของกำหนด หน้านี้ไม่อยู่ในรายการนั้น',
            $status === 403                => 'บัญชีนี้ไม่มีสิทธิ์ใช้ส่วนนี้ของระบบ',
            $status === 404                => 'ลิงก์อาจพิมพ์ผิด หรือหน้านี้ถูกย้ายหรือลบไปแล้ว',
            default                        => 'เกิดข้อผิดพลาดฝั่งเซิร์ฟเวอร์ ลองใหม่อีกครั้ง ถ้ายังเป็นอยู่ให้แจ้งผู้ดูแลระบบ',
        };

        if ($isReadOnly) {
            $primary = [$signedIn ? 'กลับหน้าของคุณ' : 'ออกจากโหมดแชร์', APP_URL . '/exit-share'];
        } elseif ($signedIn) {
            $primary = ['กลับหน้าวันนี้', APP_URL . '/'];
        } else {
            $primary = ['ไปหน้าเข้าสู่ระบบ', APP_URL . '/login'];
        }

        $statement = [
            'code'    => (string)$status,
            'title'   => $message,
            'text'    => $text,
            'note'    => $isReadOnly ? 'โหมดแชร์ · ดูอย่างเดียว' : '',
            'primary' => $primary,
            'menus'   => array_map(fn($m) => [AppMenus::label($m), APP_URL . '/' . $m], $sharedMenus),
        ];

        require ROOT . '/views/partials/statement.php';
        exit;
    }
}
