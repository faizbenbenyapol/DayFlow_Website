-- =====================================================
-- Migration: ประเภทโปรเจกต์และคอลัมน์บอร์ดรายโปรเจกต์ (P0a slice 2)
--   - projects.type: ประเภทโปรเจกต์ (general/software/marketing/finance) โปรเจกต์เดิมเป็น general
--   - project_columns: คอลัมน์บอร์ดของแต่ละโปรเจกต์
--   - project_tasks.column_id: งานชี้คอลัมน์ของโปรเจกต์เดียวกัน (ลบคอลัมน์แล้วงานไม่หาย)
--   - seed 4 คอลัมน์ตั้งต้นให้โปรเจกต์ที่ยังไม่มีคอลัมน์ และ backfill column_id ตาม status เดิม
-- เงื่อนไขเป็นระดับแถว (NOT EXISTS ต่อโปรเจกต์) เพราะรันซ้ำทุกไฟล์: โปรเจกต์ที่มีคอลัมน์
-- อยู่แล้วต้องไม่ถูกแตะ (รวมคอลัมน์ที่ผู้ใช้แก้เองภายหลัง) และงานที่ย้ายคอลัมน์แล้วต้องไม่ถูกเขียนทับ
-- =====================================================

SET NAMES utf8mb4;

-- 1. ประเภทโปรเจกต์ (แถวเดิมได้ค่า default 'general' ทั้งหมด)
ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `type` ENUM('general','software','marketing','finance') NOT NULL DEFAULT 'general' AFTER `description`;

-- 2. ตารางคอลัมน์บอร์ดของแต่ละโปรเจกต์
CREATE TABLE IF NOT EXISTS `project_columns` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id`  INT UNSIGNED NOT NULL,
  `name`        VARCHAR(60) NOT NULL,
  `grp`         ENUM('todo','doing','done') NOT NULL,
  `position`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_pcolumns_project` (`project_id`, `position`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. งานชี้คอลัมน์ (เพิ่มคอลัมน์ + FK + index เฉพาะเมื่อยังไม่มีคอลัมน์)
--    ON DELETE SET NULL: ลบโปรเจกต์แล้ว cascade ทั้งสองตาราง RESTRICT จะทำให้ลบไม่สำเร็จ
SET @has_column_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'project_tasks' AND column_name = 'column_id'
);

SET @add_column_ddl := IF(@has_column_id = 0,
    'ALTER TABLE `project_tasks`
       ADD COLUMN `column_id` INT UNSIGNED NULL DEFAULT NULL AFTER `status`,
       ADD CONSTRAINT `fk_ptasks_column`
           FOREIGN KEY (`column_id`) REFERENCES `project_columns`(`id`) ON DELETE SET NULL,
       ADD INDEX `idx_ptasks_project_column` (`project_id`, `column_id`, `position`)',
    'SELECT 1'
);

PREPARE add_column_stmt FROM @add_column_ddl;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;

-- 4. คอลัมน์ตั้งต้น 4 คอลัมน์ให้เฉพาะโปรเจกต์ที่ยังไม่มีคอลัมน์เลย (ทำครั้งเดียวทั้งชุด)
INSERT INTO `project_columns` (`project_id`, `name`, `grp`, `position`)
SELECT p.`id`, d.`name`, d.`grp`, d.`position`
FROM `projects` p
CROSS JOIN (
    SELECT 0 AS `position`, 'ต้องทำ' AS `name`, 'todo' AS `grp`
    UNION ALL SELECT 1, 'กำลังทำ', 'doing'
    UNION ALL SELECT 2, 'รอตรวจ', 'doing'
    UNION ALL SELECT 3, 'เสร็จแล้ว', 'done'
) d
WHERE NOT EXISTS (
    SELECT 1 FROM `project_columns` c WHERE c.`project_id` = p.`id`
);

-- 5. backfill column_id ตาม status เดิม เฉพาะงานที่ยังไม่มีคอลัมน์ (รอบที่ค้างกลางทางจึงถูกเก็บให้ครบ)
--    updated_at = updated_at กัน ON UPDATE CURRENT_TIMESTAMP เปลี่ยน "แก้ไขล่าสุด" ของผู้ใช้
UPDATE `project_tasks` t
JOIN `project_columns` c
  ON c.`project_id` = t.`project_id`
 AND c.`position` = CASE t.`status`
        WHEN 'To Do' THEN 0
        WHEN 'In Progress' THEN 1
        WHEN 'Review' THEN 2
        WHEN 'Done' THEN 3
     END
SET t.`column_id` = c.`id`,
    t.`updated_at` = t.`updated_at`
WHERE t.`column_id` IS NULL;
