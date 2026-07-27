<?php
// =====================================================
// controllers/FoodNoteController.php
// =====================================================

require_once ROOT . '/models/FoodNote.php';

class FoodNoteController
{
    public function index(): void
    {
        $pageTitle  = 'บันทึกอาหาร / เครื่องดื่ม';
        $pageStyle  = 'food_notes';
        $pageScript = 'food_notes';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/food_notes/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function apiList(): void
    {
        $userId   = Auth::userId();
        $type     = Request::query('type', '');
        $reaction = Request::query('reaction', '');
        $items    = FoodNote::listForUser($userId, $type, $reaction);
        Response::json([
            'items'   => $items,
            'summary' => FoodNote::summary($userId),
        ]);
    }

    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $data   = $this->validate();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        $id   = FoodNote::create($userId, $data);
        $item = FoodNote::getById($id, $userId);
        Response::json(['ok' => true, 'item' => $item], 201);
    }

    public function apiUpdate(string $id): void
    {
        $userId = Auth::userId();
        $itemId = (int)$id;
        if (!FoodNote::getById($itemId, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        $data = $this->validate();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        FoodNote::update($itemId, $userId, $data);
        Response::json(['ok' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!FoodNote::delete((int)$id, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    private function validate(): array
    {
        $name = trim(Request::input('name', ''));
        if (!$name) return ['error' => 'กรุณากรอกชื่ออาหาร/เครื่องดื่ม'];

        if (mb_strlen($name) > 255) return ['error' => 'ชื่อรายการยาวเกินไป'];
        $type     = Request::input('type', 'food');
        $reaction = Request::input('reaction', 'avoid');
        $severity = Request::input('severity', 'moderate');

        $allowedTypes    = ['food', 'drink'];
        $allowedReact    = ['allergy', 'intolerance', 'avoid', 'caution'];
        $allowedSeverity = ['mild', 'moderate', 'severe'];

        if (!in_array($type, $allowedTypes))       return ['error' => 'ประเภทไม่ถูกต้อง'];
        if (!in_array($reaction, $allowedReact))   return ['error' => 'ประเภทปฏิกิริยาไม่ถูกต้อง'];
        if (!in_array($severity, $allowedSeverity)) return ['error' => 'ระดับความรุนแรงไม่ถูกต้อง'];

        return [
            'name'     => $name,
            'type'     => $type,
            'reaction' => $reaction,
            'severity' => $severity,
            'symptoms' => mb_substr(trim(Request::input('symptoms', '')), 0, 2000) ?: null,
            'notes'    => mb_substr(trim(Request::input('notes', '')), 0, 2000) ?: null,
        ];
    }
}
