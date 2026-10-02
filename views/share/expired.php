<?php
// =====================================================
// views/share/expired.php
// A share link that cannot be opened: expired, switched off, or pointing at a
// file that is gone. The controller sets the status (410 or 404) and $pageTitle.
// =====================================================

$status = http_response_code();
$missing = $status === 404;

$statement = [
    'code'    => (string)$status,
    'title'   => $missing ? 'ไม่พบไฟล์ที่แชร์' : 'ลิงก์นี้ใช้ไม่ได้แล้ว',
    'text'    => $missing
        ? 'ไฟล์นี้อาจถูกลบไปแล้ว ขอลิงก์ใหม่จากคนที่ส่งให้คุณ'
        : 'ลิงก์อาจหมดอายุ เจ้าของอาจปิดการแชร์ หรือลิงก์พิมพ์ผิด ขอลิงก์ใหม่จากคนที่ส่งให้คุณ',
    'primary' => ['เปิด DayFlow', APP_URL . '/'],
    'back'    => false,
];

require ROOT . '/views/partials/statement.php';
