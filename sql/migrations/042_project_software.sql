-- =====================================================
-- Migration: โครงสร้างโปรเจกต์ชนิดซอฟต์แวร์ (สปรินต์ รีลีส งานย่อย และรหัสงาน)
--   - project_sprints / project_sprint_days: สปรินต์และยอดรวมรายวันสำหรับ burndown
--     บังคับด้วย DB ว่าโปรเจกต์หนึ่งมีสปรินต์ active ได้ทีละตัว (unique บนคอลัมน์ generated)
--   - project_releases: รีลีสของโปรเจกต์ พร้อม changelog
--   - project_task_blocks: งานที่บล็อกกัน (blocker ต้องเสร็จก่อน blocked) กัน self-block ด้วย CHECK
--   - project_task_links / project_components / project_task_components: ลิงก์ภายนอก
--     และคอมโพเนนต์ของงาน (ชื่อคอมโพเนนต์เทียบแบบ binary เพื่อให้ไม้โทต่างกันนับเป็นคนละชื่อ)
--   - projects: คำนำหน้ารหัสงาน (key_prefix), ตัวนับเลขงานถัดไป, สวิตช์สปรินต์ และสเกลแต้ม
--   - project_tasks: เลขงานในโปรเจกต์ (number), ชนิดงาน, งานแม่, แต้ม, สปรินต์, ลำดับ backlog,
--     รีลีส, วันที่เริ่ม, ข้อมูลบั๊ก และความเชื่อมโยงกับคอมโพเนนต์
--   - backfill เลขงานเดิมตามลำดับ id ในแต่ละโปรเจกต์ แล้วตั้งตัวนับและคำนำหน้าให้โปรเจกต์เดิม
-- ไฟล์นี้รันซ้ำได้: ทุก ALTER ถูกห่อด้วยเงื่อนไข "คอลัมน์ยังไม่มี" และ backfill ทุกตัว
-- รันเฉพาะรอบที่คอลัมน์ถูกเพิ่มจริง (ธงถูกคำนวณก่อน ALTER แล้วใช้เป็นเงื่อนไข WHERE)
-- จึงไม่เขียนทับค่าที่ผู้ใช้แก้ภายหลัง และรันรอบสองไม่เปลี่ยนอะไรเลย
-- backfill บนตารางที่มี ON UPDATE CURRENT_TIMESTAMP ใส่ updated_at = updated_at
-- เพื่อไม่ให้ "แก้ไขล่าสุด" ที่ผู้ใช้เห็นขยับ
-- =====================================================

SET NAMES utf8mb4;

-- =====================================================
-- 1. ตารางสปรินต์และรีลีส (ต้องสร้างก่อนเพิ่ม FK ใน project_tasks)
-- =====================================================

-- 1.1 สปรินต์: active_project เป็นคอลัมน์ generated ที่มีค่าเฉพาะสปรินต์ state=active
--     unique บนคอลัมน์นี้จึงบังคับที่ระดับ DB ว่าโปรเจกต์หนึ่งมีสปรินต์ active ได้ตัวเดียว
--     (NULL หลายแถวไม่ชนกัน จึงมีสปรินต์ planned/closed ได้ไม่จำกัด)
CREATE TABLE IF NOT EXISTS `project_sprints` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id`       INT UNSIGNED NOT NULL,
  `name`             VARCHAR(60) NOT NULL,
  `goal`             VARCHAR(255) NULL DEFAULT NULL,
  `start_date`       DATE NULL DEFAULT NULL,
  `end_date`         DATE NULL DEFAULT NULL,
  `state`            ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
  `committed_points` INT UNSIGNED NULL DEFAULT NULL,
  `completed_points` INT UNSIGNED NULL DEFAULT NULL,
  `started_at`       TIMESTAMP NULL DEFAULT NULL,
  `closed_at`        TIMESTAMP NULL DEFAULT NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `active_project`   INT UNSIGNED AS (IF(`state` = 'active', `project_id`, NULL)) PERSISTENT,
  UNIQUE KEY `uq_psprints_active` (`active_project`),
  INDEX `idx_psprints_project` (`project_id`, `state`),
  CONSTRAINT `fk_psprints_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.2 ยอดรวมรายวันของสปรินต์ (จุดที่อยู่ในขอบเขตเทียบกับจุดที่ทำเสร็จ) ใช้วาด burndown
--     หนึ่งแถวต่อหนึ่งวันของสปรินต์ ลบสปรินต์แล้วหายตาม
CREATE TABLE IF NOT EXISTS `project_sprint_days` (
  `sprint_id`    INT UNSIGNED NOT NULL,
  `day`          DATE NOT NULL,
  `scope_points` INT UNSIGNED NOT NULL DEFAULT 0,
  `done_points`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`sprint_id`, `day`),
  CONSTRAINT `fk_psdays_sprint` FOREIGN KEY (`sprint_id`) REFERENCES `project_sprints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.3 รีลีสของโปรเจกต์: state active = รีลีสที่กำลังเตรียมอยู่, released_at = เวลาที่ปล่อยจริง
CREATE TABLE IF NOT EXISTS `project_releases` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id`   INT UNSIGNED NOT NULL,
  `name`         VARCHAR(60) NOT NULL,
  `release_date` DATE NULL DEFAULT NULL,
  `state`        ENUM('planned','active','released') NOT NULL DEFAULT 'planned',
  `released_at`  TIMESTAMP NULL DEFAULT NULL,
  `changelog`    TEXT NULL DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_preleases_project` (`project_id`, `state`),
  CONSTRAINT `fk_preleases_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. ตารางความสัมพันธ์ของงาน (dependency, ลิงก์, คอมโพเนนต์)
-- =====================================================

-- 2.1 งานที่บล็อกกัน: blocker_id ต้องเสร็จก่อน blocked_id
--     CHECK กันแถวที่งานบล็อกตัวเอง (MariaDB 11.4 บังคับ CHECK จริง)
CREATE TABLE IF NOT EXISTS `project_task_blocks` (
  `blocker_id` INT UNSIGNED NOT NULL,
  `blocked_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`blocker_id`, `blocked_id`),
  INDEX `idx_pblocks_blocked` (`blocked_id`),
  CONSTRAINT `fk_pblocks_blocker` FOREIGN KEY (`blocker_id`) REFERENCES `project_tasks`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pblocks_blocked` FOREIGN KEY (`blocked_id`) REFERENCES `project_tasks`(`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_pblocks_self` CHECK (`blocker_id` <> `blocked_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 ลิงก์ภายนอกของงาน (PR, เอกสาร ฯลฯ) แยกตารางเพื่อให้มีได้หลายลิงก์ต่องาน
CREATE TABLE IF NOT EXISTS `project_task_links` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `task_id`    INT UNSIGNED NOT NULL,
  `url`        VARCHAR(2000) NOT NULL,
  `label`      VARCHAR(120) NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_plinks_task` (`task_id`),
  CONSTRAINT `fk_plinks_task` FOREIGN KEY (`task_id`) REFERENCES `project_tasks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.3 คอมโพเนนต์ของโปรเจกต์: ชื่อเก็บแบบ utf8mb4_bin เพื่อให้ไม้โท/รูปวรรณยุกต์ที่ต่างกัน
--     นับเป็นคนละชื่อ (ผู้ใช้ตั้งชื่อใกล้กันได้) unique ต่อโปรเจกต์กันชื่อซ้ำ
CREATE TABLE IF NOT EXISTS `project_components` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT UNSIGNED NOT NULL,
  `name`       VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pcomponents_name` (`project_id`, `name`),
  CONSTRAINT `fk_pcomponents_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.4 งานอยู่ในคอมโพเนนต์ใด (many-to-many) index ฝั่ง component ไว้ค้นงานตามคอมโพเนนต์
CREATE TABLE IF NOT EXISTS `project_task_components` (
  `task_id`      INT UNSIGNED NOT NULL,
  `component_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`task_id`, `component_id`),
  INDEX `idx_ptcomp_component` (`component_id`),
  CONSTRAINT `fk_ptcomp_task` FOREIGN KEY (`task_id`) REFERENCES `project_tasks`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptcomp_component` FOREIGN KEY (`component_id`) REFERENCES `project_components`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. คอลัมน์ใหม่ของ projects
-- =====================================================

-- 3.0 ธง "ยังไม่มีคอลัมน์ key_prefix" คำนวณก่อน ALTER แล้วคงค่าไว้ใช้เป็นเงื่อนไข backfill
--     รอบสองธงจะเป็น 1 ทำให้ ALTER ข้ามและ backfill ไม่แตะแถวใดเลย
SET @flag_projects := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = 'key_prefix'
);

-- 3.1 key_prefix = คำนำหน้ารหัสงาน (เช่น DF-12), next_task_number = เลขงานถัดไปของโปรเจกต์
--     use_sprints = เปิดใช้บอร์ดสปรินต์หรือไม่, point_scale = ชุดตัวเลข story point (fib/ten)
SET @ddl_projects := IF(@flag_projects = 0,
    'ALTER TABLE `projects`
       ADD COLUMN `key_prefix` VARCHAR(6) NULL DEFAULT NULL,
       ADD COLUMN `next_task_number` INT UNSIGNED NOT NULL DEFAULT 1,
       ADD COLUMN `use_sprints` TINYINT(1) NOT NULL DEFAULT 1,
       ADD COLUMN `point_scale` ENUM(''fib'',''ten'') NOT NULL DEFAULT ''fib''',
    'SELECT 1'
);

PREPARE s FROM @ddl_projects;
EXECUTE s;
DEALLOCATE PREPARE s;

-- =====================================================
-- 4. คอลัมน์ใหม่ของ project_tasks
-- =====================================================

-- 4.0 ธง "ยังไม่มีคอลัมน์ number" คำนวณก่อน ALTER (ใช้ทั้งกับ ALTER และ backfill A/B)
SET @flag_ptasks := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'project_tasks' AND column_name = 'number'
);

-- 4.1 เพิ่มคอลัมน์และความสัมพันธ์ทั้งหมดในครั้งเดียว เฉพาะเมื่อยังไม่มีคอลัมน์ number
--     - number: เลขงานภายในโปรเจกต์ unique ต่อโปรเจกต์ (ค่า NULL ชั่วคราวก่อน backfill ข้อ 5)
--     - kind/parent_id: แยก epic/story/task/bug/sub และผูกงานย่อยกับงานแม่
--     - sprint_id/release_id: ON DELETE SET NULL เพื่อไม่ให้การลบสปรินต์/รีลีสพางานหายไป
--     - backlog_rank: ลำดับใน backlog (ค่ามาก/น้อยขึ้นกับโค้ดเรียง)
--     - severity/repro_steps/expected/actual/environment: ข้อมูลบั๊ก
--     - fk_ptasks_parent เป็น FK อ้างตารางตัวเอง (งานแม่) จึงต้องมี index บน parent_id
SET @ddl_ptasks := IF(@flag_ptasks = 0,
    'ALTER TABLE `project_tasks`
       ADD COLUMN `number` INT UNSIGNED NULL DEFAULT NULL AFTER `project_id`,
       ADD COLUMN `kind` ENUM(''epic'',''story'',''task'',''bug'',''sub'') NOT NULL DEFAULT ''task'',
       ADD COLUMN `parent_id` INT UNSIGNED NULL DEFAULT NULL,
       ADD COLUMN `story_points` TINYINT UNSIGNED NULL DEFAULT NULL,
       ADD COLUMN `sprint_id` INT UNSIGNED NULL DEFAULT NULL,
       ADD COLUMN `backlog_rank` INT NOT NULL DEFAULT 0,
       ADD COLUMN `release_id` INT UNSIGNED NULL DEFAULT NULL,
       ADD COLUMN `start_date` DATE NULL DEFAULT NULL,
       ADD COLUMN `severity` ENUM(''critical'',''high'',''medium'',''low'') NULL DEFAULT NULL,
       ADD COLUMN `repro_steps` TEXT NULL,
       ADD COLUMN `expected` TEXT NULL,
       ADD COLUMN `actual` TEXT NULL,
       ADD COLUMN `environment` VARCHAR(255) NULL DEFAULT NULL,
       ADD UNIQUE KEY `uq_ptasks_number` (`project_id`, `number`),
       ADD INDEX `idx_ptasks_sprint` (`project_id`, `sprint_id`),
       ADD INDEX `idx_ptasks_parent` (`parent_id`),
       ADD CONSTRAINT `fk_ptasks_parent` FOREIGN KEY (`parent_id`) REFERENCES `project_tasks`(`id`) ON DELETE SET NULL,
       ADD CONSTRAINT `fk_ptasks_sprint` FOREIGN KEY (`sprint_id`) REFERENCES `project_sprints`(`id`) ON DELETE SET NULL,
       ADD CONSTRAINT `fk_ptasks_release` FOREIGN KEY (`release_id`) REFERENCES `project_releases`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);

PREPARE s FROM @ddl_ptasks;
EXECUTE s;
DEALLOCATE PREPARE s;

-- =====================================================
-- 5. Backfill ข้อมูลเดิม (รันเฉพาะรอบที่คอลัมน์เพิ่งถูกเพิ่มจริง)
-- =====================================================

-- 5.A เลขงานเดิม: เรียงตาม id ภายในโปรเจกต์เดียวกัน เริ่มที่ 1
--     ทำใน derived table (materialize ก่อน) แล้ว join กลับเพื่อเลี่ยงข้อห้าม
--     อัปเดตตารางเป้าหมายจากตารางเดียวกันโดยตรง
--     updated_at = updated_at กัน ON UPDATE CURRENT_TIMESTAMP เปลี่ยน "แก้ไขล่าสุด" ของผู้ใช้
UPDATE `project_tasks` t
JOIN (
    SELECT `id`, ROW_NUMBER() OVER (PARTITION BY `project_id` ORDER BY `id`) AS `rn`
    FROM `project_tasks`
) ranked ON ranked.`id` = t.`id`
SET t.`number` = ranked.`rn`,
    t.`updated_at` = t.`updated_at`
WHERE @flag_ptasks = 0 AND t.`number` IS NULL;

-- 5.B ตัวนับเลขงานถัดไป: ต่อจากเลขมากสุดของโปรเจกต์ หรือ 1 เมื่อยังไม่มีงาน
--     ต้องรันหลัง 5.A และใช้ธงเดียวกับ 5.A เพราะค่านี้ขึ้นกับเลขที่เพิ่ง backfill
UPDATE `projects` p
SET p.`next_task_number` = 1 + COALESCE((
        SELECT MAX(t.`number`) FROM `project_tasks` t WHERE t.`project_id` = p.`id`
    ), 0),
    p.`updated_at` = p.`updated_at`
WHERE @flag_ptasks = 0;

-- 5.C คำนำหน้ารหัสงานของโปรเจกต์เดิม: ตัวพิมพ์ใหญ่ ตัดทุกอักขระที่ไม่ใช่ A-Z
--     (เทียบด้วย utf8mb4_bin เพื่อให้อักษรไทย/อักษรมีวรรณยุกต์ถูกตัดออก ไม่ใช่ถูกยุบเป็นตัว latin)
--     เก็บ 4 ตัวแรก ถ้าเหลือไม่ถึง 2 ตัวใช้ PRJ แทน เพื่อให้รหัสงานอ่านออกเสมอ
UPDATE `projects` p
SET p.`key_prefix` = IF(
        CHAR_LENGTH(REGEXP_REPLACE(UPPER(p.`name`) COLLATE utf8mb4_bin, '[^A-Z]', '')) < 2,
        'PRJ',
        LEFT(REGEXP_REPLACE(UPPER(p.`name`) COLLATE utf8mb4_bin, '[^A-Z]', ''), 4)
    ),
    p.`updated_at` = p.`updated_at`
WHERE @flag_projects = 0;
