-- =====================================================
-- Migration: Theme Colors
-- ขยาย ENUM ของ user_settings.theme ให้รองรับธีมทั้ง 6 แบบ
-- (เดิมมีแค่ 'light','dark' ทำให้เลือกธีมอื่นแล้วบันทึกไม่ได้)
-- =====================================================

ALTER TABLE `user_settings`
  MODIFY COLUMN `theme` ENUM('light','dark','soft','lavender','ocean','peach') NOT NULL DEFAULT 'light';
