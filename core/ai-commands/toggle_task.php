<?php
// สลับสถานะงานของผู้ใช้: ยังไม่เสร็จ -> เสร็จ (สร้างรอบถัดไปถ้าทำซ้ำ) หรือเสร็จ -> ยังไม่เสร็จ
// งานของบัญชีอื่นตอบเหมือน id ที่ไม่มีอยู่

return [
    'name'        => 'toggle_task',
    'description' => 'ติ๊กงานว่าทำเสร็จแล้ว หรือเปิดงานที่เสร็จแล้วกลับมาเป็นยังไม่เสร็จ ต้องมี id จาก list_tasks',
    'args'        => ['id' => 'รหัสงาน (ต้องมี)'],
    'confirm'     => false,
    'run'         => function (int $userId, array $args): string {
        $id   = AiCommands::requireId($args, 'id');
        $task = Task::getById($id, $userId);
        if (!$task) throw new AiCommandFailed('ไม่พบงานนี้');

        if ($task['status'] === 'done') {
            Task::update($id, $userId, ['status' => 'open']);
            return 'เปิดงาน "' . $task['title'] . '" กลับมาเป็นยังไม่เสร็จแล้ว';
        }
        Task::update($id, $userId, ['status' => 'done']);
        // เหมือนทางติ๊กใน TaskController::apiUpdate: งานทำซ้ำสร้างรอบถัดไปทันที
        Task::scheduleNextOccurrence(Task::getById($id, $userId) ?: $task, $userId);
        return 'ทำเครื่องหมายงาน "' . $task['title'] . '" ว่าเสร็จแล้ว';
    },
];
