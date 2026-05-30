CREATE TABLE IF NOT EXISTS `skills` (
  `id` char(36) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `target_hours` int(11) NOT NULL DEFAULT 10000,
  `color` varchar(10) NOT NULL DEFAULT '#3b82f6',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_skill_user` (`user_id`),
  CONSTRAINT `fk_skill_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `skill_logs` (
  `id` char(36) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `skill_id` char(36) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `duration_seconds` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_skilllog_user` (`user_id`),
  KEY `fk_skilllog_skill` (`skill_id`),
  CONSTRAINT `fk_skilllog_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skilllog_skill_fk` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `skill_active_timers` (
  `user_id` int(10) unsigned NOT NULL,
  `skill_id` char(36) NOT NULL,
  `start_time` datetime NOT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `fk_activetimer_skill` (`skill_id`),
  CONSTRAINT `fk_activetimer_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activetimer_skill_fk` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
