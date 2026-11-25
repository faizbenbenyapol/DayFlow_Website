<?php
// =====================================================
// controllers/NotificationController.php — กระดิ่งแจ้งเตือนที่แถบบนของทุกหน้า
//
// ทุก endpoint อ่าน/แก้เฉพาะแถวของผู้ใช้ที่ล็อกอินอยู่ id ของคนอื่นไม่มีผลและไม่ตอบต่างกัน
// =====================================================

class NotificationController
{
    /**
     * GET /api/notifications — รายการล่าสุดพร้อมจำนวนที่ยังไม่อ่าน (ตอนเปิดกระดิ่ง)
     */
    public function apiList(): void
    {
        $userId = Auth::userId();
        Response::json([
            'unread' => Notification::unreadCount($userId),
            'items'  => Notification::recent($userId),
        ]);
    }

    /**
     * GET /api/notifications/unread — จำนวนที่ยังไม่อ่านอย่างเดียว (กระดิ่งเช็กทุก 60 วินาที)
     */
    public function apiUnread(): void
    {
        Response::json(['unread' => Notification::unreadCount(Auth::userId())]);
    }

    /**
     * POST /api/notifications/read — {"ids": [..]} อ่านบางรายการ หรือ {"all": true} อ่านทั้งหมด
     */
    public function apiRead(): void
    {
        $userId = Auth::userId();
        $payload = Request::json();

        if (!empty($payload['all'])) {
            Notification::markRead($userId, null);
        } elseif (isset($payload['ids']) && is_array($payload['ids'])) {
            Notification::markRead($userId, $payload['ids']);
        } else {
            Response::json(['error' => 'ระบุรายการที่อ่านแล้ว'], 422);
        }

        Response::json(['ok' => true, 'unread' => Notification::unreadCount($userId)]);
    }
}
