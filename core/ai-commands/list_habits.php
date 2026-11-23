<?php
// รายการนิสัยของผู้ใช้ พร้อมสถานะว่าวันนี้ทำแล้วหรือยัง
// คืนทีละบรรทัดเป็น #id [สถานะวันนี้] ชื่อ

return [
    'name'        => 'list_habits',
    'description' => 'ดูรายการนิสัยทั้งหมด พร้อม id สำหรับสั่งคำสั่งอื่นต่อ',
    'args'        => [],
    'confirm'     => false,
    'run'         => function (int $userId, array $args): string {
        $habits = Habit::listForUser($userId);
        if ($habits === []) return 'ยังไม่มีนิสัยในรายการ';

        $lines = [];
        foreach ($habits as $habit) {
            $state   = (int)$habit['completed_today'] === 1 ? 'ทำแล้ววันนี้' : 'ยังไม่ทำวันนี้';
            $lines[] = '#' . (int)$habit['id'] . ' [' . $state . '] ' . $habit['name'];
        }
        return implode("\n", $lines);
    },
];
