-- =====================================================
-- Project Share Links and Guest support - Database Migration
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. เพิ่มระบบ Share Token ในตารางโครงการหลัก (Projects)
-- ใช้สำหรับการตรวจสอบและยืนยันลิงก์แชร์สาธารณะ
ALTER TABLE `projects` ADD COLUMN IF NOT EXISTS `share_token` VARCHAR(64) DEFAULT NULL;
ALTER TABLE `projects` ADD COLUMN IF NOT EXISTS `share_role` VARCHAR(50) DEFAULT 'Viewer';

-- 2. ปรับโครงสร้างตารางชิ้นงานย่อย (Project Tasks) รองรับ Guest
-- เปลี่ยนให้ user_id เป็น NULLABLE เพื่อใช้ในกรณีผู้สร้างเป็น Guest และบันทึกชื่อ guest_name
ALTER TABLE `project_tasks` MODIFY COLUMN `user_id` INT UNSIGNED NULL;
ALTER TABLE `project_tasks` ADD COLUMN IF NOT EXISTS `guest_name` VARCHAR(100) DEFAULT NULL;

-- 3. ปรับโครงสร้างตารางแชทสนทนา (Project Chats) รองรับ Guest
-- เปลี่ยนให้ user_id เป็น NULLABLE เพื่อใช้ในกรณีผู้ส่งแชทเป็น Guest และบันทึกชื่อ guest_name
ALTER TABLE `project_chats` MODIFY COLUMN `user_id` INT UNSIGNED NULL;
ALTER TABLE `project_chats` ADD COLUMN IF NOT EXISTS `guest_name` VARCHAR(100) DEFAULT NULL;

-- 4. ปรับโครงสร้างตารางกิจกรรมโครงการ (Project Activities) รองรับ Guest
-- เปลี่ยนให้ user_id เป็น NULLABLE เพื่อใช้ในกรณีผู้กระทำเป็น Guest และบันทึกชื่อ guest_name
ALTER TABLE `project_activities` MODIFY COLUMN `user_id` INT UNSIGNED NULL;
ALTER TABLE `project_activities` ADD COLUMN IF NOT EXISTS `guest_name` VARCHAR(100) DEFAULT NULL;

SET FOREIGN_KEY_CHECKS = 1;
