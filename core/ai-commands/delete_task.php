<?php
// ลบงานถาวร จึงต้องให้ผู้ใช้ยืนยันก่อน: preview บอกชื่องานและ run ลบจริงเมื่อผู้ใช้กดยืนยัน
// งานของบัญชีอื่นตอบเหมือน id ที่ไม่มีอยู่ทั้งตอน preview และตอนลบ

return [
    'name'        => 'delete_task',
    'description' => 'ลบงานถาวร ต้องมี id จาก list_tasks และผู้ใช้ต้องยืนยันก่อน',
    'args'        => ['id' => 'รหัสงาน (ต้องมี)'],
    'confirm'     => true,
    'preview'     => function (int $userId, array $args): string {
        $id   = AiCommands::requireId($args, 'id');
        $task = Task::getById($id, $userId);
        if (!$task) throw new AiCommandFailed('ไม่พบงานนี้');
        return 'ลบงาน "' . $task['title'] . '" ใช่ไหม';
    },
    'run'         => function (int $userId, array $args): string {
        $id   = AiCommands::requireId($args, 'id');
        $task = Task::getById($id, $userId);
        if (!$task || !Task::delete($id, $userId)) throw new AiCommandFailed('ไม่พบงานนี้');
        return 'ลบงาน "' . $task['title'] . '" แล้ว';
    },
];
