-- =====================================================
-- Telegram Cron Logs - Database Migration
-- =====================================================

CREATE TABLE IF NOT EXISTS `telegram_cron_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `item_type` VARCHAR(50) NOT NULL COMMENT 'e.g. planner, task, project_task, subscription',
  `item_id` INT UNSIGNED NOT NULL,
  `reference_date` DATE NOT NULL COMMENT 'Date used to avoid duplicate per day/cycle',
  `notified_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_telegram_cron` (`item_type`, `item_id`, `reference_date`),
  INDEX `idx_user_cron` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
