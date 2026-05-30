-- =====================================================
-- Project Planner Collaboration & Chat - Database Migration
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. PROJECT MEMBERS TABLE (สิทธิ์สมาชิกโครงการ)
CREATE TABLE IF NOT EXISTS `project_members` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id`    INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `role`          VARCHAR(50) NOT NULL DEFAULT 'Editor', -- 'Owner', 'Editor', 'Viewer'
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_project_member` (`project_id`, `user_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. PROJECT CHATS TABLE (แชทสนทนาโครงการ)
CREATE TABLE IF NOT EXISTS `project_chats` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id`    INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `message`       TEXT NOT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pchats_project` (`project_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
