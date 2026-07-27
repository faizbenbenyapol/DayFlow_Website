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
