-- =====================================================
-- Migration: The desk colour (v3)
--
-- The pastel the page sits on, chosen in Settings: sage (the default), sky,
-- blush, butter, lilac, peach or stone. The values are the keys tokens.css
-- knows ([data-desk]); anything else is refused by the API.
-- Safe to run repeatedly.
-- =====================================================

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'desk'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `user_settings` ADD COLUMN `desk` VARCHAR(16) NOT NULL DEFAULT ''sage''', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
