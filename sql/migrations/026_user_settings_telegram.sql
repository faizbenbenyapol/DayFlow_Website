-- Telegram settings. 001_schema.sql already has these columns; databases
-- created before they were added got them from a probe in
-- User::getSettings(), which cost an extra query on every page view.
-- Safe to run repeatedly.

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'telegram_bot_token'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `user_settings` ADD COLUMN `telegram_bot_token` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'telegram_chat_id'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `user_settings` ADD COLUMN `telegram_chat_id` VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'telegram_notify_events'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `user_settings` ADD COLUMN `telegram_notify_events` JSON DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
