-- =====================================================
-- Migration: Google Drive (เฟส 4, docs/decisions/2026-11-google-drive.md)
--
-- files.drive_id: ไฟล์หรือโฟลเดอร์นี้อยู่ใน Google Drive ของเจ้าของแล้ว (id ของ Google)
--   ไฟล์ที่อยู่ใน Drive ไม่มี file_path แถวเดิมทั้งหมดอยู่บนเซิร์ฟเวอร์ จึงเป็น NULL
-- drive_links: การเชื่อม Drive ของผู้ใช้หนึ่งคน token เข้ารหัสด้วย appEncrypt()
--   ยกเลิกการเชื่อมแล้วแถวยังอยู่ (เก็บอีเมลกับโฟลเดอร์) ไฟล์เดิมจะกลับมาเมื่อเชื่อมบัญชีเดิม
-- รันซ้ำได้
-- =====================================================

SET @has_drive_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'files' AND column_name = 'drive_id'
);
SET @ddl := IF(@has_drive_id = 0, 'ALTER TABLE `files` ADD COLUMN `drive_id` VARCHAR(128) NULL DEFAULT NULL AFTER `file_path`, ADD INDEX `idx_user_drive` (`user_id`, `drive_id`)', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `drive_links` (
  `user_id`           INT UNSIGNED PRIMARY KEY,
  `google_email`      VARCHAR(255) NOT NULL,
  `refresh_token_enc` TEXT NULL,
  `access_token_enc`  TEXT NULL,
  `access_expires_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `folder_id`         VARCHAR(128) NOT NULL,
  `folder_name`       VARCHAR(255) NOT NULL,
  `state`             ENUM('connected','migrating','paused_full','revoked','disconnected') NOT NULL DEFAULT 'connected',
  `last_error`        VARCHAR(255) NULL,
  `connected_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
