-- =====================================================
-- Project Planner - Database Migration
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. PROJECTS TABLE
CREATE TABLE IF NOT EXISTS `projects` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL,
  `name`          VARCHAR(255) NOT NULL,
  `description`   TEXT DEFAULT NULL,
  `status`        ENUM('Planning','In Progress','Review','Completed') NOT NULL DEFAULT 'Planning',
  `priority`      ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
  `due_date`      DATE DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_projects_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. PROJECT TASKS TABLE
CREATE TABLE IF NOT EXISTS `project_tasks` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id`    INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `title`         VARCHAR(255) NOT NULL,
  `status`        ENUM('To Do','In Progress','Review','Done') NOT NULL DEFAULT 'To Do',
  `priority`      ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
  `due_date`      DATE DEFAULT NULL,
  `category`      VARCHAR(50) DEFAULT NULL,
  `assignee`      VARCHAR(50) DEFAULT NULL,
  `position`      INT UNSIGNED NOT NULL DEFAULT 0,
  `checklist`     TEXT DEFAULT NULL, -- เก็บโครงสร้าง JSON ของเช็คลิสต์ย่อย e.g. [{"text":"ย่อย 1","done":false}]
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ptasks_project` (`project_id`),
  INDEX `idx_ptasks_user` (`user_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. PROJECT ACTIVITIES TABLE
CREATE TABLE IF NOT EXISTS `project_activities` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id`    INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `action`        VARCHAR(255) NOT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pactivities_proj` (`project_id`),
  INDEX `idx_pactivities_user` (`user_id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
