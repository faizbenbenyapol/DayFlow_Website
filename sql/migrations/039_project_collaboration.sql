-- =====================================================
-- Migration: การทำงานร่วมในโปรเจค และการแจ้งเตือนทั้งแอป (P0b slice 1)
--   - project_tasks.description: คำอธิบายของงาน
--   - project_activities.task_id: บันทึกกิจกรรมที่เป็นของงานไหน (แท็บประวัติของงาน)
--   - project_task_comments: ความเห็นต่องาน
--   - project_task_files: ไฟล์แนบ = ลิงก์ไปที่ไฟล์ในคลังของคนแนบ (docs/decisions/2026-11-project-collaboration.md)
--   - notifications: รายการแจ้งเตือนในแอป ใช้ได้ทุกเมนู ไม่ผูกกับโปรเจค
-- ไฟล์นี้เพิ่มคอลัมน์และตารางอย่างเดียว ไม่แก้ข้อมูลเดิมแม้แต่แถวเดียว และรันซ้ำได้
-- =====================================================

SET NAMES utf8mb4;

-- 1. คำอธิบายของงาน
ALTER TABLE `project_tasks`
  ADD COLUMN IF NOT EXISTS `description` TEXT NULL DEFAULT NULL AFTER `title`;

-- 2. กิจกรรมผูกกับงาน: ลบงานแล้วบรรทัดยังอยู่ในฟีดของโปรเจค (SET NULL)
SET @has_activity_task := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'project_activities' AND column_name = 'task_id'
);
SET @activity_ddl := IF(@has_activity_task = 0,
    'ALTER TABLE `project_activities`
       ADD COLUMN `task_id` INT UNSIGNED NULL DEFAULT NULL AFTER `project_id`,
       ADD CONSTRAINT `fk_pactivities_task`
           FOREIGN KEY (`task_id`) REFERENCES `project_tasks`(`id`) ON DELETE SET NULL,
       ADD INDEX `idx_pactivities_task` (`task_id`, `id`)',
    'SELECT 1'
);
PREPARE activity_stmt FROM @activity_ddl;
EXECUTE activity_stmt;
DEALLOCATE PREPARE activity_stmt;

-- 3. ความเห็นต่องาน: project_id ช่วยตรวจสมาชิกภาพโดยไม่ต้อง JOIN, ผู้เขียนถูกลบบัญชี → ความเห็นยังอยู่
CREATE TABLE IF NOT EXISTS `project_task_comments` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `task_id`     INT UNSIGNED NOT NULL,
  `project_id`  INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NULL DEFAULT NULL,
  `body`        TEXT NOT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pcomments_task` (`task_id`, `id`),
  CONSTRAINT `fk_pcomments_task`    FOREIGN KEY (`task_id`)    REFERENCES `project_tasks`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pcomments_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_pcomments_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ไฟล์แนบ: คนแนบลบไฟล์ในเมนูไฟล์ → ไฟล์แนบหายตาม, index file_id ใช้นับ "แนบอยู่ใน N งาน"
CREATE TABLE IF NOT EXISTS `project_task_files` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `task_id`     INT UNSIGNED NOT NULL,
  `file_id`     INT UNSIGNED NOT NULL,
  `added_by`    INT UNSIGNED NULL DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ptask_file` (`task_id`, `file_id`),
  INDEX `idx_ptask_files_file` (`file_id`),
  CONSTRAINT `fk_ptask_files_task` FOREIGN KEY (`task_id`)  REFERENCES `project_tasks`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptask_files_file` FOREIGN KEY (`file_id`)  REFERENCES `files`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_ptask_files_user` FOREIGN KEY (`added_by`) REFERENCES `users`(`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. แจ้งเตือนในแอป: นับที่ยังไม่อ่านด้วย (user_id, read_at), ไล่ใหม่ล่าสุดด้วย (user_id, id), cron ลบเกิน 30 วันด้วย created_at
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `actor_id`    INT UNSIGNED NULL DEFAULT NULL,
  `kind`        VARCHAR(32) NOT NULL,
  `title`       VARCHAR(255) NOT NULL,
  `body`        VARCHAR(500) NULL DEFAULT NULL,
  `url`         VARCHAR(500) NULL DEFAULT NULL,
  `read_at`     DATETIME NULL DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notif_user_unread` (`user_id`, `read_at`),
  INDEX `idx_notif_user_id` (`user_id`, `id`),
  INDEX `idx_notif_created` (`created_at`),
  CONSTRAINT `fk_notif_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_actor` FOREIGN KEY (`actor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
