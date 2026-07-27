CREATE TABLE IF NOT EXISTS `habits` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `color` VARCHAR(20) NOT NULL DEFAULT '#6366f1',
  `target_days` TINYINT UNSIGNED NOT NULL DEFAULT 7,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_habits_user` (`user_id`, `is_archived`),
  CONSTRAINT `fk_habits_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `habit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `habit_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `log_date` DATE NOT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_habit_day` (`habit_id`, `log_date`),
  KEY `idx_habit_logs_user_date` (`user_id`, `log_date`),
  CONSTRAINT `fk_habit_logs_habit` FOREIGN KEY (`habit_id`) REFERENCES `habits`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_habit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
