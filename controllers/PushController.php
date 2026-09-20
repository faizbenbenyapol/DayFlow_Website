<?php
// =====================================================
// controllers/PushController.php — browser notifications
//
// Pushes carry no payload, so the service worker calls apiPending() to find
// out what to show. That keeps notification text off the push service and
// means a stale push never displays out-of-date content.
// =====================================================

class PushController
{
    public function apiConfig(): void
    {
        Response::json([
            'enabled'    => WebPush::isConfigured(),
            'public_key' => WebPush::isConfigured() ? WebPush::publicKey() : null,
            'devices'    => PushSubscription::countForUser(Auth::userId()),
        ]);
    }

    public function apiSubscribe(): void
    {
        if (!WebPush::isConfigured()) {
            Response::json(['error' => 'ระบบยังไม่ได้ตั้งค่าการแจ้งเตือน'], 503);
        }

        $userId   = Auth::userId();
        $endpoint = (string)Request::rawInput('endpoint', '');
        $keys     = Request::json()['keys'] ?? [];

        $p256dh = (string)($keys['p256dh'] ?? '');
        $auth   = (string)($keys['auth'] ?? '');

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            Response::json(['error' => 'ข้อมูลการสมัครรับแจ้งเตือนไม่ครบ'], 422);
        }
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://')) {
            Response::json(['error' => 'ปลายทางการแจ้งเตือนไม่ถูกต้อง'], 422);
        }
        if (strlen($endpoint) > 500) {
            Response::json(['error' => 'ปลายทางการแจ้งเตือนยาวเกินไป'], 422);
        }

        PushSubscription::store(
            $userId,
            $endpoint,
            mb_substr($p256dh, 0, 255),
            mb_substr($auth, 0, 255),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        Response::json(['ok' => true, 'devices' => PushSubscription::countForUser($userId)]);
    }

    public function apiUnsubscribe(): void
    {
        $userId   = Auth::userId();
        $endpoint = (string)Request::rawInput('endpoint', '');

        if ($endpoint === '') {
            Response::json(['error' => 'ไม่พบปลายทางการแจ้งเตือน'], 422);
        }

        PushSubscription::deleteByEndpoint($userId, $endpoint);
        Response::json(['ok' => true, 'devices' => PushSubscription::countForUser($userId)]);
    }

    /**
     * What the service worker should show right now.
     *
     * Deliberately the same ground the Telegram notifications cover: work due
     * today or overdue, subscriptions about to renew, and today's events.
     */
    public function apiPending(): void
    {
        Response::json(['items' => NotificationDigest::pendingFor(Auth::userId())]);
    }

    /**
     * Sends a wake-up to this account's browsers so the user can confirm the
     * setup works without waiting for the cron run.
     */
    public function apiTest(): void
    {
        if (!WebPush::isConfigured()) {
            Response::json(['error' => 'ระบบยังไม่ได้ตั้งค่าการแจ้งเตือน'], 503);
        }

        $userId = Auth::userId();
        if (PushSubscription::countForUser($userId) === 0) {
            Response::json(['error' => 'ยังไม่ได้เปิดการแจ้งเตือนบนอุปกรณ์นี้'], 422);
        }

        $result = PushSubscription::notifyUser($userId);
        if ($result['sent'] === 0) {
            Response::json([
                'error' => 'ส่งแจ้งเตือนไม่สำเร็จ' . ($result['removed'] > 0 ? ' (ลบอุปกรณ์ที่หมดอายุแล้ว)' : ''),
            ], 502);
        }

        Response::json(['ok' => true] + $result);
    }
}
