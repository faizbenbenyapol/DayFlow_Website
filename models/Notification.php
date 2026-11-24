<?php
// =====================================================
// models/Notification.php — การแจ้งเตือนในแอป (กระดิ่ง) ของทุกเมนู
//
// send() เป็นทางเดียวที่สร้างการแจ้งเตือน: ไม่แจ้งเรื่องที่ผู้รับทำเอง (REQ-P10)
// บันทึกลงรายการในแอป แล้วส่งต่อ Telegram ตามที่ผู้รับเปิดไว้ และปลุกเบราว์เซอร์
// ที่สมัคร Push ไว้ ให้ service worker ไปดึงรายการจาก NotificationDigest
// =====================================================

require_once __DIR__ . '/PushSubscription.php';
require_once __DIR__ . '/../core/TelegramService.php';
require_once __DIR__ . '/../core/WebPush.php';

final class Notification
{
    /** เก็บไว้กี่วันก่อน cron ลบทิ้ง */
    public const KEEP_DAYS = 30;

    /** จำนวนรายการที่กระดิ่งแสดงต่อครั้ง */
    public const LIST_LIMIT = 30;

    /**
     * แจ้งเตือนผู้ใช้หนึ่งคน ทั้งในแอป Telegram และ Push
     *
     * @param int $toUserId ผู้รับ
     * @param int|null $actorId คนที่ทำให้เกิดเรื่องนี้ (null = ระบบ) ถ้าเท่ากับผู้รับจะไม่แจ้ง
     * @param string $kind ชนิด เช่น assign, mention, member_left
     * @param string $title ข้อความหลักที่แสดงในกระดิ่ง
     * @param string|null $body บรรทัดรอง เช่น ชื่อโปรเจค
     * @param string|null $url ที่ที่กดแล้วไป (path ภายในแอป เช่น /projects?project=3)
     * @return bool true เมื่อสร้างการแจ้งเตือน, false เมื่อข้ามเพราะผู้รับทำเอง
     * ผลข้างเคียง: INSERT notifications, ส่ง Telegram (ถ้าเปิด event project), ส่ง Web Push
     */
    public static function send(int $toUserId, ?int $actorId, string $kind, string $title, ?string $body = null, ?string $url = null): bool
    {
        if ($actorId !== null && $actorId === $toUserId) {
            return false;
        }

        DB::run(
            'INSERT INTO notifications (user_id, actor_id, kind, title, body, url) VALUES (?, ?, ?, ?, ?, ?)',
            [$toUserId, $actorId, $kind, mb_substr($title, 0, 255), $body !== null ? mb_substr($body, 0, 500) : null, $url]
        );

        // ช่องทางนอกแอปพังได้ (Telegram ล่ม, Push หมดอายุ) แต่ต้องไม่ทำให้การกระทำของผู้ใช้ล้ม
        try {
            $details = $body !== null && $body !== '' ? ['รายละเอียด' => htmlspecialchars($body)] : [];
            TelegramService::sendNotification($toUserId, 'project', TelegramService::formatMessage('🔔 ' . htmlspecialchars($title), $details));
        } catch (Throwable $e) {
            error_log('Notification telegram failed: ' . $e->getMessage());
        }
        try {
            if (WebPush::isConfigured()) {
                PushSubscription::notifyUser($toUserId);
            }
        } catch (Throwable $e) {
            error_log('Notification push failed: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * นับรายการที่ยังไม่อ่านของผู้ใช้ (query เดียว ใช้ index (user_id, read_at))
     *
     * @param int $userId ผู้ใช้
     * @return int จำนวนที่ยังไม่อ่าน
     */
    public static function unreadCount(int $userId): int
    {
        return (int)DB::run(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        )->fetchColumn();
    }

    /**
     * รายการล่าสุดของผู้ใช้ พร้อมชื่อคนที่ทำ
     *
     * @param int $userId ผู้ใช้
     * @return array<int, array<string, mixed>> id, kind, title, body, url, read (bool), created_at, actor_name
     */
    public static function recent(int $userId): array
    {
        $rows = DB::run(
            'SELECT n.id, n.kind, n.title, n.body, n.url, n.read_at, n.created_at,
                    COALESCE(NULLIF(u.display_name, \'\'), u.username) AS actor_name
             FROM notifications n
             LEFT JOIN users u ON u.id = n.actor_id
             WHERE n.user_id = ?
             ORDER BY n.id DESC
             LIMIT ' . self::LIST_LIMIT,
            [$userId]
        )->fetchAll();

        return array_map(static function (array $row): array {
            $row['read'] = $row['read_at'] !== null;
            unset($row['read_at']);
            return $row;
        }, $rows);
    }

    /**
     * ทำเครื่องหมายว่าอ่านแล้ว เฉพาะแถวของผู้ใช้คนนี้ id ของคนอื่นไม่มีผลและไม่บอกว่ามีอยู่
     *
     * @param int $userId ผู้ใช้
     * @param array<int, int>|null $ids รายการ id หรือ null = ทั้งหมด
     * @return int จำนวนแถวที่เปลี่ยน
     */
    public static function markRead(int $userId, ?array $ids): int
    {
        if ($ids === null) {
            return DB::run(
                'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL',
                [$userId]
            )->rowCount();
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) return 0;

        return DB::run(
            'UPDATE notifications SET read_at = NOW()
             WHERE user_id = ? AND read_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge([$userId], $ids)
        )->rowCount();
    }

    /**
     * รายการที่ยังไม่อ่านภายใน 10 นาที สำหรับ service worker แสดงตอนถูกปลุก
     *
     * @param int $userId ผู้ใช้
     * @return array<int, array{title: string, body: string, url: string, tag: string}>
     */
    public static function pushItems(int $userId): array
    {
        $rows = DB::run(
            'SELECT id, title, body, url FROM notifications
             WHERE user_id = ? AND read_at IS NULL AND created_at >= NOW() - INTERVAL 10 MINUTE
             ORDER BY id DESC LIMIT 3',
            [$userId]
        )->fetchAll();

        return array_map(static fn(array $row): array => [
            'title' => (string)$row['title'],
            'body'  => (string)($row['body'] ?? ''),
            'url'   => APP_URL . ($row['url'] ?: '/'),
            'tag'   => 'notif-' . (int)$row['id'],
        ], $rows);
    }

    /**
     * ลบรายการที่เก่ากว่า KEEP_DAYS (เรียกจาก cron)
     *
     * @return int จำนวนแถวที่ลบ
     */
    public static function purgeOld(): int
    {
        return DB::run(
            'DELETE FROM notifications WHERE created_at < NOW() - INTERVAL ' . self::KEEP_DAYS . ' DAY'
        )->rowCount();
    }
}
