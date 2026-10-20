-- =====================================================
-- Migration: Theme Colors
-- ขยาย ENUM ของ user_settings.theme ให้รองรับธีมทั้ง 6 แบบ
-- (เดิมมีแค่ 'light','dark' ทำให้เลือกธีมอื่นแล้วบันทึกไม่ได้)
--
-- Safe to run again after 027: once the column accepts 'auto' (v2's
-- light/dark/auto), going back to the six v1 themes would refuse every row
-- set to 'auto' and fail the migration, so this step leaves the column alone.
-- That happens whenever 017 runs after 027: a fresh database built by the
-- container's init scripts, a --force run, or this file's own re-apply after
-- this guard was added.
-- =====================================================

SET @theme_type := (
    SELECT COLUMN_TYPE FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_settings' AND column_name = 'theme'
);
SET @ddl := IF(
    @theme_type LIKE '%''auto''%',
    'SELECT 1',
    'ALTER TABLE `user_settings` MODIFY COLUMN `theme` ENUM(''light'',''dark'',''soft'',''lavender'',''ocean'',''peach'') NOT NULL DEFAULT ''light'''
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
