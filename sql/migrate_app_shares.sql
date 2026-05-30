-- =====================================================
-- Migration: App Shares
-- สร้างตารางสำหรับระบบแชร์เมนู (Module Shares)
-- =====================================================

CREATE TABLE IF NOT EXISTS `app_shares` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `label` VARCHAR(255) NOT NULL,
  `menus` JSON NOT NULL,
  `expires_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
