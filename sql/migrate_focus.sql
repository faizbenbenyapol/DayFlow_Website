-- =====================================================
-- 20. FOCUS SESSIONS (Pomodoro Focus)
-- =====================================================
CREATE TABLE IF NOT EXISTS `focus_sessions` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT UNSIGNED NOT NULL,
  `task_id`      INT UNSIGNED DEFAULT NULL,
  `title`        VARCHAR(255) NOT NULL,
  `duration_min` INT UNSIGNED NOT NULL,
  `type`         VARCHAR(50) NOT NULL DEFAULT 'work' COMMENT 'work, short_break, long_break',
  `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_focus_user` (`user_id`),
  KEY `idx_focus_task` (`task_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
