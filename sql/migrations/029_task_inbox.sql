-- =====================================================
-- Migration: คอลัมน์ inbox, completed_at และบันทึกการคัดลอกงานเก่า
--
-- เตรียมตาราง tasks ให้พร้อมรับข้อมูลจาก daily_todos และ quick_items
-- ใน step 1 ของ daily-loop refactor
--   - quadrant เปลี่ยนเป็น nullable เพื่อให้งานที่ "ยังไม่จัดหมวดหมู่"
--     อยู่ใน inbox ได้
--   - completed_at บันทึกเวลาทำงานเสร็จ (ไม่ใช้ updated_at เพราะลากเรียงลำดับ
--     จะเปลี่ยนค่า และข้อมูลย้ายถิ่นฐานจะได้เวลาย้าย)
--   - legacy_source / legacy_id ชี้กลับไปยังแถวต้นทาง
--   - legacy_task_copies บันทึกว่าแถวต้นทางถูกคัดลอกแล้ว แม้งานใน tasks
--     ถูกลบไปก็ไม่คัดลอกซ้ำ
-- รันได้ซ้ำโดยไม่ผิดพลาด
-- =====================================================

CREATE TABLE IF NOT EXISTS `legacy_task_copies` (
  `legacy_source` ENUM('daily_todo','quick_item') NOT NULL,
  `legacy_id`     INT UNSIGNED NOT NULL,
  `copied_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`legacy_source`, `legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_legacy_source := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tasks' AND column_name = 'legacy_source'
);
SET @add_ddl := IF(@has_legacy_source = 0,
    'ALTER TABLE `tasks`
      ADD COLUMN `legacy_source` ENUM(''daily_todo'',''quick_item'') NULL DEFAULT NULL AFTER `quadrant`,
      ADD COLUMN `legacy_id` INT UNSIGNED NULL DEFAULT NULL AFTER `legacy_source`,
      ADD UNIQUE KEY `uq_tasks_legacy` (`legacy_source`, `legacy_id`)',
    'SELECT 1'
);
PREPARE add_stmt FROM @add_ddl;
EXECUTE add_stmt;
DEALLOCATE PREPARE add_stmt;

SET @quadrant_needs_modify := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tasks'
      AND column_name = 'quadrant'
      AND (is_nullable = 'NO' OR COALESCE(column_comment, '') = '')
);
SET @modify_ddl := IF(@quadrant_needs_modify = 1,
    'ALTER TABLE `tasks` MODIFY COLUMN `quadrant` TINYINT UNSIGNED DEFAULT 1 COMMENT ''1=DoFirst(urgent+important), 2=Schedule(important+not urgent), 3=Delegate(urgent+not important), 4=Eliminate(not urgent+not important)''',
    'SELECT 1'
);
PREPARE modify_stmt FROM @modify_ddl;
EXECUTE modify_stmt;
DEALLOCATE PREPARE modify_stmt;

SET @has_completed_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tasks' AND column_name = 'completed_at'
);
SET @completed_at_ddl := IF(@has_completed_at = 0,
    'ALTER TABLE `tasks`
       ADD COLUMN `completed_at` DATETIME NULL DEFAULT NULL AFTER `status`',
    'SELECT 1'
);
PREPARE completed_at_stmt FROM @completed_at_ddl;
EXECUTE completed_at_stmt;
DEALLOCATE PREPARE completed_at_stmt;

UPDATE tasks SET completed_at = updated_at, updated_at = updated_at WHERE status = 'done' AND completed_at IS NULL;

SET @has_day_position := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tasks' AND column_name = 'day_position'
);
SET @day_position_ddl := IF(@has_day_position = 0,
    'ALTER TABLE `tasks`
       ADD COLUMN `day_position` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `position`',
    'SELECT 1'
);
PREPARE day_position_stmt FROM @day_position_ddl;
EXECUTE day_position_stmt;
DEALLOCATE PREPARE day_position_stmt;
