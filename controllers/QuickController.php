<?php
require_once ROOT . '/models/Task.php';
require_once ROOT . '/models/LegacyTaskMapper.php';

class QuickController {
    /**
     * หน้าจดด่วนเดิมย้ายไปอยู่ใน /tasks#inbox แล้ว
     */
    public function index(): void
    {
        Response::redirect('/tasks#inbox');
    }

    /**
     * คืนรายการจดด่วนทั้งหมดของผู้ใช้ (inbox task ที่ไม่มี due_date)
     */
    public function apiList(): void
    {
        $items = Task::getInboxForUser(Auth::userId());
        Response::json(['items' => array_map([$this, 'formatItem'], $items)]);
    }

    /**
     * สร้างจดด่วนใหม่ โดยแยกข้อความเป็นชื่อเรื่องและรายละเอียด
     */
    public function apiCreate(): void
    {
        $content = trim((string)Request::input('content', ''));
        if ($content === '' || mb_strlen($content) > 500) {
            Response::json(['error' => 'ข้อความต้องมีความยาว 1-500 ตัวอักษร'], 422);
        }

        $split = LegacyTaskMapper::splitText($content);
        $id = Task::create(Auth::userId(), [
            'title'       => $split['title'],
            'description' => $split['description'],
            'quadrant'    => null,
            'due_date'    => null,
            'status'      => 'open',
            'position'    => 0,
        ]);

        Response::json(['ok' => true, 'id' => $id], 201);
    }

    /**
     * สลับสถานะ open/done ของจดด่วน
     *
     * @param string $id รหัสงาน
     */
    public function apiToggle(string $id): void
    {
        if (!Task::toggle((int)$id, Auth::userId())) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    /**
     * ลบจดด่วน
     *
     * @param string $id รหัสงาน
     */
    public function apiDelete(string $id): void
    {
        if (!Task::delete((int)$id, Auth::userId())) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    /**
     * แปลงแถว task เป็นรูปแบบรายการจดด่วนที่ quick.js คาดหวัง
     *
     * @param array<string, mixed> $row แถวจาก Task
     * @return array<string, mixed> แถวพร้อม id, content, is_done, created_at
     */
    private function formatItem(array $row): array
    {
        return [
            'id'         => (int)$row['id'],
            'content'    => $row['description'] ?? $row['title'],
            'is_done'    => ($row['status'] ?? 'open') === 'done' ? 1 : 0,
            'created_at' => $row['created_at'],
        ];
    }
}
