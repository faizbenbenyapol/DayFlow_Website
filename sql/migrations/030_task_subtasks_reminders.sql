-- =====================================================
-- Migration: งานย่อยและเวลาเตือนสำหรับงานส่วนตัว
--
-- งานส่วนตัว (tasks) ได้รับ checklist ย่อย (task_subtasks) และเวลาเตือน
-- เดียว (remind_at) พร้อม reminded_at สำหรับ cron บันทึกว่าส่งเตือนแล้ว
-- รันได้ซ้ำโดยไม่ผิดพลาด
-- =====================================================

CREATE TABLE IF NOT EXISTS `task_subtasks` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `task_id`     INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `title`       VARCHAR(255) NOT NULL,
  `is_done`     TINYINT(1) NOT NULL DEFAULT 0,
  `position`    INT NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_task_position` (`task_id`, `position`),
  FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `tasks`
  ADD COLUMN IF NOT EXISTS `remind_at`    DATETIME DEFAULT NULL AFTER `repeat_until`,
  ADD COLUMN IF NOT EXISTS `reminded_at`  DATETIME DEFAULT NULL AFTER `remind_at`;

CREATE INDEX IF NOT EXISTS `idx_tasks_remind_at` ON `tasks` (`remind_at`);
