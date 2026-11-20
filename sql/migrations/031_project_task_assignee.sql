-- =====================================================
-- Migration: ผู้รับผิดชอบงานโปรเจกต์เชื่อมกับบัญชีผู้ใช้
--   - เพิ่ม project_tasks.assignee_user_id อ้างอิง users.id
--   - คงคอลัมน์ assignee ข้อความเดิมไว้สำหรับ backward compatibility
-- =====================================================

SET @has_assignee_user_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'project_tasks' AND column_name = 'assignee_user_id'
);

SET @add_ddl := IF(@has_assignee_user_id = 0,
    'ALTER TABLE `project_tasks`
       ADD COLUMN `assignee_user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `category`,
       ADD CONSTRAINT `fk_ptasks_assignee_user`
           FOREIGN KEY (`assignee_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
       ADD INDEX `idx_ptasks_assignee_status_due` (`assignee_user_id`, `status`, `due_date`)',
    'SELECT 1'
);

PREPARE add_stmt FROM @add_ddl;
EXECUTE add_stmt;
DEALLOCATE PREPARE add_stmt;
