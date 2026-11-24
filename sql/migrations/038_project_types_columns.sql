-- =====================================================
-- Migration: ประเภทโปรเจกต์และคอลัมน์บอร์ดรายโปรเจกต์ (P0a slice 2)
--   - projects.type: ประเภทโปรเจกต์ (general/software/marketing/finance) โปรเจกต์เดิมเป็น general
--   - project_columns: คอลัมน์บอร์ดของแต่ละโปรเจกต์
--   - project_tasks.column_id: งานชี้คอลัมน์ของโปรเจกต์เดียวกัน (ลบคอลัมน์แล้วงานไม่หาย)
--   - seed 4 คอลัมน์ตั้งต้นให้โปรเจกต์ที่ยังไม่มีคอลัมน์ backfill column_id ตามกลุ่มของ status เดิม แล้วตั้ง status เป็นเงาของคอลัมน์
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

-- 4b. งานที่ status อยู่คนละกลุ่มกับคอลัมน์ของมัน = ถูกย้ายโดยโค้ดเก่าระหว่างถอยโค้ด (โค้ดเก่าเขียนแค่ status)
--     ให้ status ชนะ: ปล่อย column_id ว่างแล้วขั้น 5 จัดเข้าคอลัมน์ตาม status
--     รันครั้งแรกบนข้อมูลจริงไม่มีแถวไหนเข้าเงื่อนไข (column_id ยังว่างทั้งหมด) และรันซ้ำปกติก็ไม่มี (status เป็นเงาอยู่แล้ว)
UPDATE `project_tasks` t
JOIN `project_columns` c ON c.`id` = t.`column_id`
SET t.`column_id` = NULL,
    t.`updated_at` = t.`updated_at`
WHERE c.`grp` <> CASE t.`status` WHEN 'To Do' THEN 'todo' WHEN 'Done' THEN 'done' ELSE 'doing' END;

-- 5. backfill column_id ตามกลุ่มของ status เดิม เฉพาะงานที่ยังไม่มีคอลัมน์ (รอบที่ค้างกลางทางจึงถูกเก็บให้ครบ)
--    กฎเดียวกับ ProjectColumn::forLegacyStatus(): To Do = คอลัมน์แรกของ todo, In Progress = คอลัมน์แรก
--    ของ doing, Review = คอลัมน์สุดท้ายของ doing, Done = คอลัมน์แรกของ done (แรก/สุดท้ายตาม position แล้ว id)
--    ไม่จับคู่ด้วยเลข position ตายตัว เพราะโปรเจคที่โค้ดใหม่สร้าง (ซอฟต์แวร์ 5 คอลัมน์ หรือเรียงกลุ่มใหม่)
--    ใช้ position คนละชุด ซึ่งเจอเมื่อถอยโค้ดแล้วรัน --force
--    IF(...) DESC แทน -position เพราะ position เป็น UNSIGNED ติดลบไม่ได้
--    updated_at = updated_at กัน ON UPDATE CURRENT_TIMESTAMP เปลี่ยน "แก้ไขล่าสุด" ของผู้ใช้
UPDATE `project_tasks` t
SET t.`column_id` = (
        SELECT c.`id` FROM `project_columns` c
        WHERE c.`project_id` = t.`project_id`
          AND c.`grp` = CASE t.`status` WHEN 'To Do' THEN 'todo' WHEN 'Done' THEN 'done' ELSE 'doing' END
        ORDER BY IF(t.`status` = 'Review', c.`position`, 0) DESC,
                 IF(t.`status` = 'Review', c.`id`, 0) DESC,
                 c.`position`, c.`id`
        LIMIT 1
    ),
    t.`updated_at` = t.`updated_at`
WHERE t.`column_id` IS NULL;

-- 6. status เป็นเงาของคอลัมน์ (กฎเดียวกับ ProjectTask::shadowStatusSql): done = Done, todo = To Do,
--    doing คอลัมน์สุดท้าย = Review, doing อื่น = In Progress
--    ข้อมูลเดิมที่เพิ่ง backfill ตรงกฎอยู่แล้วจึงไม่มีแถวเปลี่ยน ส่วนงานที่ backfill เข้าโปรเจคที่มี doing
--    คอลัมน์เดียวจะได้ Review ตามกฎ
UPDATE `project_tasks` t
JOIN `project_columns` c ON c.`id` = t.`column_id`
SET t.`status` = CASE c.`grp`
        WHEN 'done' THEN 'Done'
        WHEN 'todo' THEN 'To Do'
        ELSE IF(c.`id` = (
                SELECT d.`id` FROM `project_columns` d
                WHERE d.`project_id` = c.`project_id` AND d.`grp` = 'doing'
                ORDER BY d.`position` DESC, d.`id` DESC
                LIMIT 1
            ), 'Review', 'In Progress')
    END,
    t.`updated_at` = t.`updated_at`;
