CREATE TABLE IF NOT EXISTS `stock_watchlists` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `ticker`     VARCHAR(20) NOT NULL,
  `market`     VARCHAR(10) NOT NULL DEFAULT 'US',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_ticker` (`user_id`, `ticker`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
