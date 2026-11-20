-- =====================================================
-- Migration: file_transfers — ข้อความ สถานะการอัปโหลด และเวลาหมดอายุที่เลือกได้
--
-- เฟส 3 (docs/requirements/feedback-2026-11.md REQ-16–19): ไฟล์อัปโหลดทีละชิ้น
-- แถวจึงมีสถานะ 'uploading' ระหว่างส่ง และ 'ready' เมื่อครบ ระหว่างอัปโหลด
-- expires_at คือเส้นตายของการอัปโหลดที่ค้าง (ขยับทุกชิ้นที่มาถึง) พอเสร็จจึงเป็น
-- เวลาหมดอายุจริง = ตอนเสร็จ + expiry_minutes
-- แถวเดิมเป็นการส่งที่เสร็จแล้วทั้งหมด จึงได้ค่าเริ่มต้น 'ready' โดยไม่ต้อง backfill
-- รันซ้ำได้: เพิ่มแต่ละคอลัมน์เฉพาะเมื่อยังไม่มี
-- =====================================================

SET @has_message := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'file_transfers' AND column_name = 'message'
);
SET @ddl := IF(@has_message = 0, 'ALTER TABLE `file_transfers` ADD COLUMN `message` TEXT NULL DEFAULT NULL AFTER `files_json`', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_status := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'file_transfers' AND column_name = 'status'
);
SET @ddl := IF(@has_status = 0, 'ALTER TABLE `file_transfers` ADD COLUMN `status` ENUM(''uploading'',''ready'') NOT NULL DEFAULT ''ready'' AFTER `message`', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_expiry := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'file_transfers' AND column_name = 'expiry_minutes'
);
SET @ddl := IF(@has_expiry = 0, 'ALTER TABLE `file_transfers` ADD COLUMN `expiry_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 10 AFTER `status`', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
