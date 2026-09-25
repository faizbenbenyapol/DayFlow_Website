-- =====================================================
-- Migration: Auto theme
-- เพิ่มตัวเลือก 'auto' ให้ผู้ใช้ตั้งธีมตามระบบปฏิบัติการได้
-- (สว่างตอนกลางวัน มืดตอนกลางคืน โดยไม่ต้องมาสลับเอง)
-- =====================================================

ALTER TABLE `user_settings`
  MODIFY COLUMN `theme` ENUM('light','dark','soft','lavender','ocean','peach','auto') NOT NULL DEFAULT 'light';
