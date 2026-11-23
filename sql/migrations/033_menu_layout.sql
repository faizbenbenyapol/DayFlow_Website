-- =====================================================
-- Migration: user_settings.menu_layout — ชื่อหมวดและหมวดของเมนูที่ผู้ใช้ตั้งเอง
--
-- เก็บเฉพาะส่วนที่ต่างจากค่าตั้งต้น รูปแบบ {"names": {"life": "สุขภาพ"},
-- "groups": {"stocks": "vault"}} (docs/requirements/feedback-2026-11.md REQ-9, REQ-10)
-- NULL = ใช้ค่าตั้งต้นทั้งหมด ไม่มี backfill ผู้ใช้เดิมจึงเห็นเมนูเหมือนเดิม
-- รันซ้ำได้: เพิ่มคอลัมน์เฉพาะเมื่อยังไม่มี
-- =====================================================

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'menu_layout'
);

SET @ddl := IF(@has_col = 0, 'ALTER TABLE `user_settings` ADD COLUMN `menu_layout` JSON DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
