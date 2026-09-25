-- =====================================================
-- Migration: File Shares (Real-time Share Links)
-- Run once on existing databases
-- =====================================================

CREATE TABLE IF NOT EXISTS `file_shares` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `file_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(64)  NOT NULL,
  `label`      VARCHAR(150) NOT NULL DEFAULT '',
  `permission` ENUM('view','download') NOT NULL DEFAULT 'view',
  `expires_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_token` (`token`),
  INDEX `idx_user`    (`user_id`),
  INDEX `idx_file`    (`file_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`file_id`) REFERENCES `files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
