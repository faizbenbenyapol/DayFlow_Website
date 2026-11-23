<?php
// สรุปรายรับ/รายจ่ายของเดือนหนึ่ง และรายการล่าสุดไม่เกิน 20 รายการ
// เงินแสดงทศนิยม 2 ตำแหน่ง

require_once ROOT . '/models/Finance.php';

return [
    'name'        => 'list_finance',
    'description' => 'ดูสรุปการเงินของเดือน พร้อมรายการล่าสุดไม่เกิน 20 รายการ',
    'args'        => ['month' => 'YYYY-MM ว่างได้ (ว่าง = เดือนนี้)'],
    'confirm'     => false,
    'run'         => function (int $userId, array $args): string {
        $month   = AiCommands::optionalMonth($args, 'month', date('Y-m'));
        $summary = Finance::getMonthlySummary($userId, $month);

        $lines = [
            'เดือน ' . $month
            . ': รายรับ ' . number_format((float)$summary['income'], 2) . ' บาท'
            . ' รายจ่าย ' . number_format((float)$summary['expense'], 2) . ' บาท'
            . ' คงเหลือ ' . number_format((float)$summary['balance'], 2) . ' บาท',
        ];

        $rows = Finance::listForUser($userId, $month, '', '', '', 20);
        if ($rows === []) {
            $lines[] = 'ไม่มีรายการในเดือนนี้';
        }
        foreach ($rows as $row) {
            $type  = $row['type'] === 'income' ? 'รายรับ' : 'รายจ่าย';
            $line  = '- ' . $row['txn_date'] . ' ' . $type . ' '
                . number_format((float)$row['amount'], 2) . ' บาท';
            $desc     = trim((string)($row['description'] ?? ''));
            $category = trim((string)($row['category_name'] ?? ''));
            if ($desc !== '') $line .= ' ' . $desc;
            if ($category !== '') $line .= ' [' . $category . ']';
            $lines[] = $line;
        }
        return implode("\n", $lines);
    },
];
