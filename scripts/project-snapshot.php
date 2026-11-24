<?php
// CLI-only: the web server must never run this, even if it is reachable.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

// =====================================================
// สแนปช็อตข้อมูลโปรเจกต์ (อ่านอย่างเดียว)
// =====================================================
//   php scripts/project-snapshot.php
//
// พิมพ์ JSON ของทุกตารางที่ชื่อ projects หรือ project_* พร้อมทุกแถว
// ใช้เทียบข้อมูลก่อนและหลังรัน migration บนสำเนาฐานข้อมูล สคริปต์นี้อ่าน
// ฐานข้อมูลเท่านั้น (ไม่มี INSERT/UPDATE/DELETE/DDL) และ DB_NAME มาจาก
// environment เหมือน scripts/migrate.php

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';

// =====================================================
// ค้นหาตาราง
// =====================================================

/**
 * หาชื่อตารางข้อมูลโปรเจกต์ในฐานข้อมูลที่เชื่อมต่ออยู่
 *
 * รับ: ไม่มี (ใช้ DATABASE() ของ connection ปัจจุบัน)
 * คืน: array<int, string> ชื่อตารางที่เรียงตามตัวอักษร
 * ผลข้างเคียง: อ่าน information_schema เท่านั้น ไม่แตะข้อมูลผู้ใช้
 */
function projectTableNames(): array
{
    $names = [];
    $rows = DB::conn()->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND (TABLE_NAME = 'projects' OR TABLE_NAME LIKE 'project\\_%')
          ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rows as $name) {
        $names[] = (string)$name;
    }
    return $names;
}

/**
 * ใส่ backtick ให้ชื่อตารางก่อนต่อเข้า SQL
 *
 * รับ: ชื่อตาราง
 * คืน: ชื่อตารางที่ครอบ backtick แล้ว
 * ผลข้างเคียง: โยน RuntimeException ถ้าชื่อมีอักขระที่ไม่คาดคิด
 */
function quoteTable(string $table): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        throw new RuntimeException("Unexpected table name: {$table}");
    }
    return '`' . $table . '`';
}

// =====================================================
// เก็บและพิมพ์ข้อมูล
// =====================================================

/**
 * อ่านทุกแถวของทุกตารางโปรเจกต์
 *
 * รับ: ไม่มี
 * คืน: array{database: string, tables: array<string, array>} โดยค่าในแต่ละ
 *       แถวเป็นชนิดที่ PDO คืนมา (string/int/null) ตรง ๆ
 * ผลข้างเคียง: อ่านฐานข้อมูลหนึ่ง SELECT ต่อหนึ่งตาราง ไม่เขียนอะไรเลย
 */
function snapshot(): array
{
    $tables = [];
    foreach (projectTableNames() as $table) {
        $tables[$table] = DB::conn()->query(
            'SELECT * FROM ' . quoteTable($table) . ' ORDER BY id'
        )->fetchAll();
    }

    return ['database' => DB_NAME, 'tables' => $tables];
}

$json = json_encode(snapshot(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, 'Cannot encode snapshot: ' . json_last_error_msg() . "\n");
    exit(1);
}

echo $json, "\n";
