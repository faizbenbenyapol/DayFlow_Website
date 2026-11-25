-- =====================================================
-- Migration: ช่องข้อมูลเพิ่มเติมต่อโปรเจค (P0b slice 7, D-6 แบบ A)
--   - project_fields: นิยามช่อง (ชื่อ ชนิด ตัวเลือก ลำดับ) สูงสุด 10 ช่องต่อโปรเจค (ตรวจในโค้ด)
--   - project_tasks.custom: ค่าที่กรอก เป็น JSON object {"<field id>": ค่า} อยู่ในแถวงาน
--     โหลดบอร์ดจึงยังเป็น query เดียว กรอง/เรียงทำในเบราว์เซอร์
-- ไฟล์นี้เพิ่มตารางและคอลัมน์อย่างเดียว ไม่แก้ข้อมูลเดิม และรันซ้ำได้
-- =====================================================

SET NAMES utf8mb4;

-- 1. นิยามช่อง: ลบโปรเจคแล้วช่องหายตาม, options ใช้กับชนิด select เท่านั้น (JSON array ของข้อความ)
CREATE TABLE IF NOT EXISTS `project_fields` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id`  INT UNSIGNED NOT NULL,
  `name`        VARCHAR(60) NOT NULL,
  `kind`        ENUM('text','number','date','select','person','check') NOT NULL,
  `options`     TEXT NULL DEFAULT NULL,
  `position`    INT NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pfields_project` (`project_id`, `position`),
  CONSTRAINT `fk_pfields_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ค่าของช่องในแต่ละงาน: ไม่มีค่า = ไม่มี key, ลบช่องแล้ว JSON_REMOVE ทุกงานของโปรเจค
ALTER TABLE `project_tasks`
  ADD COLUMN IF NOT EXISTS `custom` TEXT NULL DEFAULT NULL AFTER `checklist`;
