-- =====================================================
-- Migration: Settings for the v2 look
--
-- The six themes (light, dark, soft, lavender, ocean, peach) become three
-- choices: light, dark and auto. Anyone on one of the four removed palettes
-- is moved to light. Also adds the switch for the weekday colour and the
-- slots a user picks for the mobile tab bar.
-- Safe to run repeatedly.
-- =====================================================

-- Move people off the removed palettes first; the ENUM below cannot hold them.
UPDATE `user_settings` SET `theme` = 'light' WHERE `theme` IN ('soft', 'lavender', 'ocean', 'peach');

ALTER TABLE `user_settings`
  MODIFY COLUMN `theme` ENUM('light','dark','auto') NOT NULL DEFAULT 'light';

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'day_color'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `user_settings` ADD COLUMN `day_color` TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'mobile_tabs'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `user_settings` ADD COLUMN `mobile_tabs` JSON DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
