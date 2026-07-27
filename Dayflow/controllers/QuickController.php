<?php
require_once ROOT . '/models/QuickItem.php';
class QuickController {
    public function index(): void { $pageTitle = 'จดด่วน'; $pageStyle = 'quick'; $pageScript = 'quick'; require ROOT . '/views/layout/header.php'; require ROOT . '/views/quick/index.php'; require ROOT . '/views/layout/footer.php'; }
    public function apiList(): void { Response::json(['items' => QuickItem::listForUser(Auth::userId())]); }
    public function apiCreate(): void { $content = trim((string)Request::input('content', '')); if ($content === '' || mb_strlen($content) > 500) Response::json(['error' => 'ข้อความต้องมีความยาว 1-500 ตัวอักษร'], 422); Response::json(['ok' => true, 'id' => QuickItem::create(Auth::userId(), $content)], 201); }
    public function apiToggle(string $id): void { if (!QuickItem::toggle((int)$id, Auth::userId())) Response::json(['error' => 'ไม่พบรายการ'], 404); Response::json(['ok' => true]); }
    public function apiDelete(string $id): void { if (!QuickItem::delete((int)$id, Auth::userId())) Response::json(['error' => 'ไม่พบรายการ'], 404); Response::json(['ok' => true]); }
}
