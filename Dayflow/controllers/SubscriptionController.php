<?php
// =====================================================
// controllers/SubscriptionController.php
// =====================================================

require_once ROOT . '/models/Subscription.php';
require_once ROOT . '/core/TelegramService.php';

class SubscriptionController
{
    private const BILLING_CYCLES = ['weekly', 'monthly', 'yearly', 'one_time'];

    public function index(): void
    {
        $pageTitle  = 'การแจ้งเตือน';
        $pageStyle  = 'subscriptions';
        $pageScript = 'subscriptions';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/subscriptions/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function apiList(): void
    {
        $userId = Auth::userId();
        Response::json(['subscriptions' => Subscription::listForUser($userId)]);
    }

    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $data   = $this->validateData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        $id  = Subscription::create($userId, $data);
        $sub = Subscription::getById($id, $userId);
        
        $startDate = TelegramService::formatThaiDate($data['next_due_date']);
        $msg = TelegramService::formatMessage(
            "🔔 เพิ่มการแจ้งเตือนบิลใหม่",
            [
                'รายการ' => htmlspecialchars($data['name']),
                'เริ่มชำระ' => $startDate,
                'รอบบิล' => $data['billing_cycle'] == 'monthly' ? 'รายเดือน' : 'รายปี'
            ]
        );
        TelegramService::sendNotification($userId, 'subscription', $msg);

        Response::json(['ok' => true, 'subscription' => $sub], 201);
    }

    public function apiUpdate(string $id): void
    {
        $userId = Auth::userId();
        $subId  = (int)$id;
        $sub    = Subscription::getById($subId, $userId);
        if (!$sub) Response::json(['error' => 'ไม่พบรายการ'], 404);

        $data = $this->validateData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        Subscription::update($subId, $userId, $data);
        Response::json(['ok' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!Subscription::delete((int)$id, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    public function apiRenew(string $id): void
    {
        $userId = Auth::userId();
        if (!Subscription::renew((int)$id, $userId)) {
            Response::json(['error' => 'ต่ออายุไม่สำเร็จ'], 400);
        }
        Response::json(['ok' => true]);
    }

    private function validateData(): array
    {
        $name = Request::input('name', '');
        $date = Request::input('next_due_date', '');
        if (!$name) return ['error' => 'กรุณากรอกชื่อรายการ'];
        if (!$date) return ['error' => 'กรุณากรอกวันที่ครบกำหนดถัดไป'];

        $name = trim($name);
        $dateObj = DateTime::createFromFormat('Y-m-d', (string)$date);
        $amount = (float)Request::input('amount', 0);
        $cycle = Request::input('billing_cycle', 'monthly');
        $alert = (int)Request::input('alert_days', 3);
        if ($name === '' || mb_strlen($name) > 255 || !$dateObj || $dateObj->format('Y-m-d') !== $date) return ['error' => 'ข้อมูลการแจ้งเตือนไม่ถูกต้อง'];
        if ($amount < 0 || $amount > 999999999.99 || !is_finite($amount)) return ['error' => 'จำนวนเงินไม่ถูกต้อง'];
        if (!in_array($cycle, self::BILLING_CYCLES, true) || $alert < 0 || $alert > 365) return ['error' => 'รอบบิลหรือจำนวนวันแจ้งเตือนไม่ถูกต้อง'];

        return [
            'name'          => $name,
            'amount'        => $amount,
            'billing_cycle' => $cycle,
            'next_due_date' => $date,
            'alert_days'    => $alert,
            'is_active'     => (int)Request::input('is_active', 1),
            'notes'         => Request::input('notes', ''),
        ];
    }
}
