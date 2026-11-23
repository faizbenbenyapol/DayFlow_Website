<?php
// ติ๊กนิสัยของวันนี้ หรือยกเลิกติ๊กถ้าทำแล้ว
// นิสัยของบัญชีอื่นตอบเหมือน id ที่ไม่มีอยู่

return [
    'name'        => 'toggle_habit',
    'description' => 'ติ๊กนิสัยว่าทำแล้ววันนี้ หรือยกเลิกติ๊ก ต้องมี id จาก list_habits',
    'args'        => ['id' => 'รหัสนิสัย (ต้องมี)'],
    'confirm'     => false,
    'run'         => function (int $userId, array $args): string {
        $id    = AiCommands::requireId($args, 'id');
        $habit = Habit::get($id, $userId);
        if (!$habit) throw new AiCommandFailed('ไม่พบนิสัยนี้');

        $done = Habit::toggleToday($id, $userId);
        return $done
            ? 'ทำเครื่องหมายนิสัย "' . $habit['name'] . '" ว่าทำแล้ววันนี้'
            : 'ยกเลิกการทำนิสัย "' . $habit['name'] . '" ของวันนี้แล้ว';
    },
];
