-- Persistent device sessions. Store only a hash of the validator.
CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL,
  `selector`      CHAR(24) NOT NULL,
  `token_hash`    CHAR(64) NOT NULL,
  `user_agent`    VARCHAR(255) DEFAULT NULL,
  `ip_address`    VARCHAR(45) DEFAULT NULL,
  `expires_at`    DATETIME NOT NULL,
  `last_used_at`  DATETIME DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  KEY `idx_remember_expiry` (`expires_at`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
