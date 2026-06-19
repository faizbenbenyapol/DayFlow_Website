-- =====================================================
-- Personal Life Management System - Database Schema
-- MySQL 5.7+ / MariaDB 10.3+
-- UTF8MB4, InnoDB
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- 1. USERS
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(80)  NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name`  VARCHAR(100) NOT NULL DEFAULT '',
  `avatar_path`   VARCHAR(255) DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. USER SETTINGS (one row per user)
-- =====================================================
CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id`   INT UNSIGNED PRIMARY KEY,
  `theme`     ENUM('light','dark') NOT NULL DEFAULT 'light',
  `language`  VARCHAR(10) NOT NULL DEFAULT 'th',
  `timezone`  VARCHAR(50) NOT NULL DEFAULT 'Asia/Bangkok',
  `telegram_bot_token` VARCHAR(255) DEFAULT NULL,
  `telegram_chat_id` VARCHAR(100) DEFAULT NULL,
  `telegram_notify_events` JSON DEFAULT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. DASHBOARD LAYOUT
-- =====================================================
CREATE TABLE IF NOT EXISTS `dashboard_layout` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `widget_key` VARCHAR(50)  NOT NULL,
  `position`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uq_user_widget` (`user_id`, `widget_key`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. TASKS
-- =====================================================
CREATE TABLE IF NOT EXISTS `tasks` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `title`       VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `quadrant`    TINYINT UNSIGNED NOT NULL DEFAULT 1
                COMMENT '1=DoFirst(urgent+important), 2=Schedule(important+not urgent), 3=Delegate(urgent+not important), 4=Eliminate(not urgent+not important)',
  `status`      ENUM('open','done') NOT NULL DEFAULT 'open',
  `due_date`    DATE DEFAULT NULL,
  `position`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_quadrant` (`user_id`, `quadrant`),
  INDEX `idx_user_due`      (`user_id`, `due_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. NOTES
-- =====================================================
CREATE TABLE IF NOT EXISTS `notes` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL,
  `title`         VARCHAR(255) NOT NULL DEFAULT 'ไม่มีชื่อ',
  `is_encrypted`  TINYINT(1) NOT NULL DEFAULT 0,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `encrypt_salt`  VARCHAR(64) DEFAULT NULL,
  `pinned`        TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_pinned` (`user_id`, `pinned`),
  FULLTEXT KEY `ft_title` (`title`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 6. NOTE BLOCKS
-- =====================================================
CREATE TABLE IF NOT EXISTS `note_blocks` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `note_id`    INT UNSIGNED NOT NULL,
  `type`       ENUM('text','link','checklist') NOT NULL DEFAULT 'text',
  `content`    MEDIUMTEXT NOT NULL DEFAULT '',
  `position`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_note_pos` (`note_id`, `position`),
  FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. NOTE TAGS
-- =====================================================
CREATE TABLE IF NOT EXISTS `note_tags` (
  `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `name`    VARCHAR(50)  NOT NULL,
  UNIQUE KEY `uq_user_tag` (`user_id`, `name`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 8. NOTE <-> TAG RELATIONS
-- =====================================================
CREATE TABLE IF NOT EXISTS `note_tag_relations` (
  `note_id` INT UNSIGNED NOT NULL,
  `tag_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`note_id`, `tag_id`),
  FOREIGN KEY (`note_id`) REFERENCES `notes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tag_id`)  REFERENCES `note_tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 9. CALENDAR EVENTS
-- =====================================================
CREATE TABLE IF NOT EXISTS `calendar_events` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `title`          VARCHAR(255) NOT NULL,
  `description`    TEXT DEFAULT NULL,
  `start_datetime` DATETIME NOT NULL,
  `end_datetime`   DATETIME DEFAULT NULL,
  `is_all_day`     TINYINT(1) NOT NULL DEFAULT 0,
  `color`          VARCHAR(7) NOT NULL DEFAULT '#555555',
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_start` (`user_id`, `start_datetime`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 10. DAILY TODOS
-- =====================================================
CREATE TABLE IF NOT EXISTS `daily_todos` (
  `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`   INT UNSIGNED NOT NULL,
  `todo_date` DATE NOT NULL,
  `title`     VARCHAR(255) NOT NULL,
  `is_done`   TINYINT(1) NOT NULL DEFAULT 0,
  `position`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX `idx_user_date` (`user_id`, `todo_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 11. WORKOUTS
-- =====================================================
CREATE TABLE IF NOT EXISTS `workouts` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT UNSIGNED NOT NULL,
  `workout_date` DATE NOT NULL,
  `type`         VARCHAR(100) NOT NULL,
  `duration_min` SMALLINT UNSIGNED DEFAULT NULL,
  `sets`         TINYINT UNSIGNED DEFAULT NULL,
  `reps`         TINYINT UNSIGNED DEFAULT NULL,
  `weight_kg`    DECIMAL(5,2) DEFAULT NULL,
  `notes`        TEXT DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_date` (`user_id`, `workout_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 12. FINANCE CATEGORIES
-- =====================================================
CREATE TABLE IF NOT EXISTS `finance_categories` (
  `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `name`    VARCHAR(80) NOT NULL,
  `type`    ENUM('income','expense','both') NOT NULL DEFAULT 'expense',
  UNIQUE KEY `uq_user_cat` (`user_id`, `name`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 13. FINANCES (transactions)
-- =====================================================
CREATE TABLE IF NOT EXISTS `finances` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `type`        ENUM('income','expense') NOT NULL,
  `amount`      DECIMAL(12,2) NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `txn_date`    DATE NOT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_date`     (`user_id`, `txn_date`),
  INDEX `idx_user_type`     (`user_id`, `type`),
  FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `finance_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 14. SUBSCRIPTIONS / COUNTDOWNS
-- =====================================================
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `amount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` ENUM('weekly','monthly','yearly','one_time') NOT NULL DEFAULT 'monthly',
  `next_due_date` DATE NOT NULL,
  `alert_days`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `notes`         TEXT DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_due` (`user_id`, `next_due_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 15. FILES
-- =====================================================
CREATE TABLE IF NOT EXISTS `files` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `parent_id`  INT UNSIGNED DEFAULT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `type`       ENUM('folder','file') NOT NULL DEFAULT 'file',
  `mime_type`  VARCHAR(100) DEFAULT NULL,
  `file_path`  VARCHAR(500) DEFAULT NULL,
  `file_size`  INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_parent` (`user_id`, `parent_id`),
  FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_id`) REFERENCES `files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 16. FOOD NOTES
-- =====================================================
CREATE TABLE IF NOT EXISTS `food_notes` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `type`       ENUM('food','drink') NOT NULL DEFAULT 'food',
  `reaction`   ENUM('allergy','intolerance','avoid','caution') NOT NULL DEFAULT 'avoid',
  `severity`   ENUM('mild','moderate','severe') NOT NULL DEFAULT 'moderate',
  `symptoms`   TEXT DEFAULT NULL,
  `notes`      TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_fn_user_reaction` (`user_id`, `reaction`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 17. AI KEYS & GENERATIONS
-- =====================================================
CREATE TABLE IF NOT EXISTS `user_ai_keys` (
  `user_id`     INT UNSIGNED NOT NULL,
  `provider`    VARCHAR(30) NOT NULL,
  `api_key_enc` TEXT NOT NULL,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `provider`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_generations` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `kind`            ENUM('script','video','combo') NOT NULL DEFAULT 'script',
  `keyword`         VARCHAR(300) DEFAULT NULL,
  `platform`        VARCHAR(30)  DEFAULT NULL,
  `style`           VARCHAR(50)  DEFAULT NULL,
  `language`        VARCHAR(10)  DEFAULT 'th',
  `duration_sec`    SMALLINT UNSIGNED DEFAULT 30,
  `text_provider`   VARCHAR(30)  DEFAULT NULL,
  `video_provider`  VARCHAR(30)  DEFAULT NULL,
  `prompt`          TEXT DEFAULT NULL,
  `result_json`     LONGTEXT DEFAULT NULL,
  `video_url`       VARCHAR(500) DEFAULT NULL,
  `video_job_id`    VARCHAR(150) DEFAULT NULL,
  `status`          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `error_message`   TEXT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ai_user_created` (`user_id`, `created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 18. STOCK PORTFOLIO
-- =====================================================
CREATE TABLE IF NOT EXISTS `stock_transactions` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `ticker`     VARCHAR(20)   NOT NULL,
  `market`     VARCHAR(10)   NOT NULL DEFAULT 'US',
  `side`       ENUM('buy','sell') NOT NULL,
  `quantity`   DECIMAL(18,4) NOT NULL,
  `price`      DECIMAL(18,4) NOT NULL,
  `fee`        DECIMAL(18,4) NOT NULL DEFAULT 0,
  `currency`   CHAR(3)       NOT NULL DEFAULT 'USD',
  `txn_date`   DATE          NOT NULL,
  `notes`      VARCHAR(500)  DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_stx_user_ticker` (`user_id`, `ticker`),
  KEY `idx_stx_user_date`   (`user_id`, `txn_date`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_stock_api_keys` (
  `user_id`     INT UNSIGNED NOT NULL,
  `provider`    VARCHAR(30) NOT NULL,
  `api_key_enc` TEXT NOT NULL,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `provider`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_price_cache` (
  `ticker`     VARCHAR(20)   NOT NULL PRIMARY KEY,
  `price`      DECIMAL(18,4) NOT NULL,
  `prev_close` DECIMAL(18,4) DEFAULT NULL,
  `currency`   CHAR(3)       DEFAULT NULL,
  `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 19. SKILLS (Time Tracker)
-- =====================================================
CREATE TABLE IF NOT EXISTS `skills` (
  `id` VARCHAR(36) PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `target_hours` INT UNSIGNED NOT NULL DEFAULT 10000,
  `color` VARCHAR(20) NOT NULL DEFAULT '#3b82f6',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `skill_logs` (
  `id` VARCHAR(36) PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `skill_id` VARCHAR(36) NOT NULL,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NOT NULL,
  `duration_seconds` INT UNSIGNED NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`skill_id`) REFERENCES `skills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `skill_active_timers` (
  `user_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `skill_id` VARCHAR(36) NOT NULL,
  `start_time` DATETIME NOT NULL,
  `notes` TEXT DEFAULT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`skill_id`) REFERENCES `skills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_watchlists` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `ticker`     VARCHAR(20) NOT NULL,
  `market`     VARCHAR(10) NOT NULL DEFAULT 'US',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_ticker` (`user_id`, `ticker`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
