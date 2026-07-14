<?php
require_once ROOT . '/models/Bookmark.php';
class BookmarkController {
    public function index(): void { $pageTitle = 'ลิงก์สำคัญ'; $pageStyle = 'bookmarks'; $pageScript = 'bookmarks'; require ROOT . '/views/layout/header.php'; require ROOT . '/views/bookmarks/index.php'; require ROOT . '/views/layout/footer.php'; }
    public function apiList(): void { Response::json(['bookmarks' => Bookmark::listForUser(Auth::userId())]); }
    public function apiCreate(): void { $title = trim((string)Request::input('title', '')); $url = trim((string)Request::input('url', '')); $category = trim((string)Request::input('category', 'ทั่วไป')) ?: 'ทั่วไป'; if ($title === '' || mb_strlen($title) > 180 || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) Response::json(['error' => 'กรุณากรอกชื่อและ URL ที่ถูกต้อง (http/https)'], 422); Response::json(['ok' => true, 'id' => Bookmark::create(Auth::userId(), $title, $url, mb_substr($category, 0, 80))], 201); }
    public function apiDelete(string $id): void { if (!Bookmark::delete((int)$id, Auth::userId())) Response::json(['error' => 'ไม่พบรายการ'], 404); Response::json(['ok' => true]); }
}
