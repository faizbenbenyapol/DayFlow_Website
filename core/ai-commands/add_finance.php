<?php
// บันทึกรายรับ/รายจ่าย หมวดจับคู่ชื่อแบบตรงตัวอักษรเป๊ะใน PHP (ไม่ใช้ collation ของ SQL
// เพราะมองข้ามวรรณยุกต์) ไม่เจอหมวดที่ตรง = บันทึกโดยไม่มีหมวด

// FinanceCategory อยู่ในไฟล์เดียวกับ Finance ซึ่ง autoloader หาไม่เจอถ้าอ้างชื่อมันก่อน
require_once ROOT . '/models/Finance.php';

return [
    'name'        => 'add_finance',
    'description' => 'บันทึกรายรับหรือรายจ่ายหนึ่งรายการ',
    'args'        => [
        'type'        => 'income หรือ expense (ต้องมี)',
        'amount'      => 'จำนวนเงินมากกว่า 0 (ต้องมี)',
        'description' => 'รายละเอียด เว้นว่างได้',
        'date'        => 'YYYY-MM-DD ว่างได้ (ว่าง = วันนี้)',
        'category'    => 'ชื่อหมวด เว้นว่างได้',
    ],
    'confirm'     => false,
    'run'         => function (int $userId, array $args): string {
        $type = $args['type'] ?? '';
        if (!is_string($type) || !in_array($type, ['income', 'expense'], true)) {
            throw new AiCommandFailed('type ต้องเป็น income หรือ expense');
        }

        $raw = $args['amount'] ?? null;
        if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric($raw))) {
            throw new AiCommandFailed('จำนวนเงินไม่ถูกต้อง');
        }
        $amount = (float)$raw;
        if (!is_finite($amount) || $amount <= 0 || $amount > 999999999.99) {
            throw new AiCommandFailed('จำนวนเงินไม่ถูกต้อง');
        }

        $description = AiCommands::optionalText($args, 'description', 1000);
        $date        = AiCommands::optionalDate($args, 'date', date('Y-m-d'));
        $category    = AiCommands::optionalText($args, 'category', 100);

        $categoryId = null;
        if ($category !== '') {
            foreach (FinanceCategory::listForUser($userId) as $row) {
                $sameType = $row['type'] === $type || $row['type'] === 'both';
                if ($sameType && trim((string)$row['name']) === $category) {
                    $categoryId = (int)$row['id'];
                    break;
                }
            }
        }

        $id = Finance::create($userId, [
            'type'        => $type,
            'amount'      => $amount,
            'category_id' => $categoryId,
            'description' => $description,
            'txn_date'    => (string)$date,
        ]);
        $label = $type === 'income' ? 'รายรับ' : 'รายจ่าย';
        return 'บันทึก' . $label . ' ' . number_format($amount, 2) . ' บาท วันที่ ' . $date
            . ' แล้ว (#' . $id . ')';
    },
];
