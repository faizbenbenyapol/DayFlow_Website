<?php
// เพิ่มงานใหม่ด้วยค่าเริ่มต้นเหมือน TaskController::apiCreate (quadrant 1, ยังไม่เสร็จ, ไม่ทำซ้ำ)
// คืนข้อความยืนยันพร้อม id ของงานที่สร้าง

return [
    'name'        => 'add_task',
    'description' => 'เพิ่มงานใหม่ ชื่อต้องมี ส่วนวันครบกำหนดเว้นว่างได้',
    'args'        => ['title' => 'ชื่องาน (ต้องมี)', 'due_date' => 'YYYY-MM-DD ว่างได้'],
    'confirm'     => false,
    'run'         => function (int $userId, array $args): string {
        $title = AiCommands::requireText($args, 'title', 255);
        $due   = AiCommands::optionalDate($args, 'due_date');
        $id    = Task::create($userId, [
            'title'       => $title,
            'quadrant'    => 1,
            'status'      => 'open',
            'due_date'    => $due,
            'repeat_rule' => 'none',
        ]);
        $when = $due !== null ? ' วันที่ ' . $due : '';
        return 'เพิ่มงาน "' . $title . '"' . $when . ' แล้ว (#' . $id . ')';
    },
];
