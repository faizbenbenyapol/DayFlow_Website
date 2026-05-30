-- =====================================================
-- migrate_ai.sql — Adds AI content generator tables
-- Run once: mysql -u root mylife_db < sql/migrate_ai.sql
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
  KEY `idx_user_created` (`user_id`, `created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
