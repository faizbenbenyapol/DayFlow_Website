-- =====================================================
-- Migration: users.first_task_at — when an account made its first task
--
-- The Today page welcomes an account that has never had a task (REQ-23).
-- Deleting that first task must not bring the welcome back (EDGE-16), so the
-- moment is kept here instead of being worked out from the tasks table.
-- Accounts that exist when this runs count as welcomed: only accounts made
-- after the release see it. The backfill runs only in the run that adds the
-- column, so `migrate.php --force` later does not mark newer accounts.
-- Safe to run repeatedly.
-- =====================================================

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'first_task_at'
);

SET @ddl := IF(@has_col = 0, 'ALTER TABLE `users` ADD COLUMN `first_task_at` TIMESTAMP NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- updated_at = updated_at: the column updates itself on every UPDATE, and this
-- is not an edit anyone made to their account.
SET @fill := IF(@has_col = 0, 'UPDATE `users` SET `first_task_at` = COALESCE(`created_at`, NOW()), `updated_at` = `updated_at`', 'SELECT 1');
PREPARE stmt FROM @fill;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
