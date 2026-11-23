<?php
// รายการงานของผู้ใช้ในวันหนึ่ง (ค่าว่าง = วันนี้)
// คืนทีละบรรทัดเป็น #id [สถานะ] ชื่องาน และคืนข้อความว่าง ๆ เมื่อไม่มีงาน

return [
    'name'        => 'list_tasks',
    'description' => 'ดูรายการงานที่ครบกำหนดในวันหนึ่ง พร้อม id สำหรับสั่งคำสั่งอื่นต่อ',
    'args'        => ['date' => 'YYYY-MM-DD ว่างได้ (ว่าง = วันนี้)'],
    'confirm'     => false,
    'run'         => function (int $userId, array $args): string {
        $date = AiCommands::optionalDate($args, 'date', date('Y-m-d'));
        $tasks = Task::getForDate($userId, (string)$date);
        if ($tasks === []) return 'ไม่มีงานในวันนั้น';

        $lines = [];
        foreach ($tasks as $task) {
            $state   = $task['status'] === 'done' ? 'เสร็จ' : 'ยังไม่เสร็จ';
            $lines[] = '#' . (int)$task['id'] . ' [' . $state . '] ' . $task['title'];
        }
        return implode("\n", $lines);
    },
];
