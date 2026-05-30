<?php
// =====================================================
// controllers/FinanceController.php
// =====================================================

require_once ROOT . '/models/Finance.php';

class FinanceController
{
    public function index(): void
    {
        $pageTitle    = 'การเงิน';
        $pageStyle    = 'finance';
        $pageScript   = 'finance';
        $loadChartJs  = true;

        $userId = Auth::userId();
        $cats   = FinanceCategory::listForUser($userId);

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/finance/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function apiList(): void
    {
        $userId    = Auth::userId();
        $month     = Request::query('month', '');
        $type      = Request::query('type', '');
        $startDate = Request::query('start_date', '');
        $endDate   = Request::query('end_date', '');
        $data      = Finance::listForUser($userId, $month, $type, $startDate, $endDate);
        Response::json(['transactions' => $data]);
    }

    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $data   = $this->validateData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        $id  = Finance::create($userId, $data);
        $txn = Finance::getById($id, $userId);
        Response::json(['ok' => true, 'transaction' => $txn], 201);
    }

    public function apiUpdate(string $id): void
    {
        $userId = Auth::userId();
        $txnId  = (int)$id;
        $txn    = Finance::getById($txnId, $userId);
        if (!$txn) Response::json(['error' => 'ไม่พบรายการ'], 404);

        $data = $this->validateData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        Finance::update($txnId, $userId, $data);
        Response::json(['ok' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!Finance::delete((int)$id, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    public function apiSummary(): void
    {
        $userId = Auth::userId();
        $month  = Request::query('month', date('Y-m'));
        Response::json(Finance::getMonthlySummary($userId, $month));
    }

    public function apiChart(): void
    {
        $userId = Auth::userId();
        $year   = (int)Request::query('year', (int)date('Y'));
        Response::json(['chart' => Finance::getYearlyChart($userId, $year)]);
    }

    public function apiCategoriesList(): void
    {
        $userId = Auth::userId();
        Response::json(['categories' => FinanceCategory::listForUser($userId)]);
    }

    public function apiCategoryCreate(): void
    {
        $userId = Auth::userId();
        $name   = Request::input('name', '');
        $type   = Request::input('type', 'expense');

        if (!$name) Response::json(['error' => 'กรุณากรอกชื่อหมวดหมู่'], 422);

        $id = FinanceCategory::create($userId, $name, $type);
        Response::json(['ok' => true, 'id' => $id], 201);
    }

    public function apiCategoryUpdate(string $id): void
    {
        $userId = Auth::userId();
        $name   = Request::input('name', '');
        $type   = Request::input('type', 'expense');

        if (!$name) Response::json(['error' => 'กรุณากรอกชื่อหมวดหมู่'], 422);
        if (!in_array($type, ['income', 'expense'], true)) {
            Response::json(['error' => 'ประเภทไม่ถูกต้อง'], 422);
        }

        FinanceCategory::update((int)$id, $userId, $name, $type);
        Response::json(['ok' => true]);
    }

    public function apiCategoryDelete(string $id): void
    {
        $userId = Auth::userId();
        FinanceCategory::delete((int)$id, $userId);
        Response::json(['ok' => true]);
    }

    private function validateData(): array
    {
        $type   = Request::input('type', '');
        $amount = (float)Request::input('amount', 0);
        $date   = Request::input('txn_date', date('Y-m-d'));

        if (!in_array($type, ['income', 'expense'])) return ['error' => 'ประเภทไม่ถูกต้อง'];
        if ($amount <= 0) return ['error' => 'กรุณากรอกจำนวนเงิน'];
        if (!$date) return ['error' => 'กรุณากรอกวันที่'];

        return [
            'type'        => $type,
            'amount'      => $amount,
            'category_id' => (int)Request::input('category_id', 0),
            'description' => Request::input('description', ''),
            'txn_date'    => $date,
        ];
    }
}
