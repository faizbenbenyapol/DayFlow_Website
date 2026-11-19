<?php
// =====================================================
// controllers/TaskSubtaskController.php
// =====================================================

require_once ROOT . '/models/Task.php';
require_once ROOT . '/models/TaskSubtask.php';

class TaskSubtaskController
{
    /**
     * คืนรายการงานย่อยของงานหนึ่งใบ ถ้างานไม่ใช่ของผู้ใช้จะตอบ 404
     *
     * @param string $id รหัสงานหลัก
     */
    public function apiList(string $id): void
    {
        $userId = Auth::userId();
        $taskId = (int)$id;

        if (!Task::getById($taskId, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }

        Response::json(['ok' => true, 'subtasks' => TaskSubtask::listForTask($taskId, $userId)]);
    }

    /**
     * สร้างงานย่อยใหม่ต่อท้ายงานหลัก จำกัดไม่เกิน 100 รายการ
     *
     * @param string $id รหัสงานหลัก
     */
    public function apiCreate(string $id): void
    {
        $userId = Auth::userId();
        $taskId = (int)$id;

        if (!Task::getById($taskId, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }

        $title = trim((string)Request::input('title', ''));
        if ($title === '') Response::json(['error' => 'กรุณากรอกชื่องานย่อย'], 422);
        if (mb_strlen($title) > 255) Response::json(['error' => 'ชื่องานย่อยยาวเกิน 255 ตัวอักษร'], 422);

        if (TaskSubtask::countForTask($taskId, $userId) >= 100) {
            Response::json(['error' => 'งานย่อยเต็มแล้ว (100 รายการ)'], 422);
        }

        $subtask = TaskSubtask::create($taskId, $userId, $title);
        Response::json(['ok' => true, 'subtask' => $subtask], 201);
    }

    /**
     * แก้ไขชื่อหรือสถานะของงานย่อย ถ้าไม่ใช่ของผู้ใช้จะตอบ 404
     *
     * @param string $sid รหัสงานย่อย
     */
    public function apiUpdate(string $sid): void
    {
        $userId = Auth::userId();
        $subtaskId = (int)$sid;

        if (!TaskSubtask::find($subtaskId, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }

        $data = [];
        $title = Request::input('title');
        if ($title !== null) {
            $title = trim((string)$title);
            if ($title === '') Response::json(['error' => 'กรุณากรอกชื่องานย่อย'], 422);
            if (mb_strlen($title) > 255) Response::json(['error' => 'ชื่องานย่อยยาวเกิน 255 ตัวอักษร'], 422);
            $data['title'] = $title;
        }

        $isDone = Request::input('is_done');
        if ($isDone !== null) {
            $data['is_done'] = (bool)$isDone;
        }

        $subtask = TaskSubtask::update($subtaskId, $userId, $data);
        Response::json(['ok' => true, 'subtask' => $subtask]);
    }

    /**
     * ลบงานย่อย ถ้าไม่ใช่ของผู้ใช้จะตอบ 404
     *
     * @param string $sid รหัสงานย่อย
     */
    public function apiDelete(string $sid): void
    {
        $userId = Auth::userId();
        $subtaskId = (int)$sid;

        if (!TaskSubtask::delete($subtaskId, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    /**
     * จัดลำดับงานย่อยตาม array ids ที่ส่งมา
     *
     * @param string $id รหัสงานหลัก
     */
    public function apiReorder(string $id): void
    {
        $userId = Auth::userId();
        $taskId = (int)$id;

        if (!Task::getById($taskId, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }

        $ids = Request::json()['ids'] ?? [];
        if (!is_array($ids) || count($ids) > 200) {
            Response::json(['error' => 'ข้อมูลการเรียงงานย่อยไม่ถูกต้อง'], 422);
        }
        foreach ($ids as $i) {
            if (!is_int($i) || $i < 1) {
                Response::json(['error' => 'ข้อมูลการเรียงงานย่อยไม่ถูกต้อง'], 422);
            }
        }

        TaskSubtask::reorder($taskId, $userId, $ids);
        Response::json(['ok' => true]);
    }
}
