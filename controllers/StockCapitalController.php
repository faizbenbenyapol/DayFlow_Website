<?php
// =====================================================
// controllers/StockCapitalController.php — money moved into and out of the portfolio
// =====================================================

class StockCapitalController
{
    public function apiList(): void
    {
        // คนดูลิงก์แชร์เห็นยอดเงินลงทุนจากหน้าสรุปพอร์ตแล้ว รายการรายครั้งไม่เปิดให้ (CR-1)
        if (Auth::isReadOnly()) Response::json(['error' => 'ลิงก์แชร์ไม่แสดงรายการเงินลงทุน'], 403);
        Response::json(['flows' => Stock::listCapitalFlows(Auth::userId())]);
    }

    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $data   = $this->validate();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        $id   = Stock::createCapitalFlow($userId, $data);
        $flow = Stock::getCapitalFlowById($id, $userId);
        Response::json(['ok' => true, 'flow' => $flow], 201);
    }

    public function apiUpdate(string $id): void
    {
        $userId = Auth::userId();
        $flowId = (int)$id;
        $flow   = Stock::getCapitalFlowById($flowId, $userId);
        if (!$flow) Response::json(['error' => 'ไม่พบรายการ'], 404);
        self::refuseSaleLinked($flow);

        $data = $this->validate();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        Stock::updateCapitalFlow($flowId, $userId, $data);
        Response::json(['ok' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        $flow   = Stock::getCapitalFlowById((int)$id, $userId);
        if (!$flow) Response::json(['error' => 'ไม่พบรายการ'], 404);
        self::refuseSaleLinked($flow);

        Stock::deleteCapitalFlow((int)$id, $userId);
        Response::json(['ok' => true]);
    }

    /**
     * รายการถอนที่ระบบสร้างจากการขายหุ้น แก้หรือลบได้ที่รายการขายเท่านั้น
     * ไม่งั้นยอดถอนกับป้าย "ถอนออก" ของรายการขายจะไม่ตรงกัน ตอบ 409 แล้วจบ request
     */
    private static function refuseSaleLinked(array $flow): void
    {
        if (!empty($flow['sale_txn_id'])) {
            Response::json(['error' => 'รายการนี้มาจากการขายหุ้น แก้หรือลบที่รายการขายในแท็บซื้อขาย'], 409);
        }
    }

    private function validate(): array
    {
        $type     = Request::input('flow_type', 'deposit');
        $amount   = (float)Request::input('amount', 0);
        $currency = strtoupper(trim((string)Request::input('currency', 'THB')));
        $date     = Request::input('flow_date', date('Y-m-d'));
        $notes    = Request::input('notes', '');

        if (!in_array($type, ['deposit', 'withdrawal'], true)) {
            return ['error' => 'ประเภทรายการไม่ถูกต้อง'];
        }
        if ($amount <= 0) {
            return ['error' => 'กรุณากรอกจำนวนเงินที่มากกว่า 0'];
        }
        if (!in_array($currency, ['THB', 'USD'], true)) {
            return ['error' => 'สกุลเงินต้องเป็น THB หรือ USD เท่านั้น'];
        }
        if (!$date) {
            return ['error' => 'กรุณาเลือกวันที่'];
        }

        return [
            'flow_type' => $type,
            'amount'    => $amount,
            'currency'  => $currency,
            'flow_date' => $date,
            'notes'     => $notes !== '' ? $notes : null,
        ];
    }
}
