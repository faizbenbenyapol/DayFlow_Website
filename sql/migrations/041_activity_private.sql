-- =====================================================
-- Migration: เพิ่มคอลัมน์ private ใน project_activities (D-5)
--   - project_activities.private: TINYINT(1) NOT NULL DEFAULT 0 AFTER action
--   - ใช้ทำเครื่องหมายบรรทัดกิจกรรมที่เป็นข้อมูลลับ เช่น ชื่อไฟล์แนบ
--     หรือชื่อและค่าของช่องข้อมูลเพิ่มเติม เพื่อให้แขกที่เปิดลิงก์แชร์เห็นเฉพาะบรรทัดที่ไม่ลับ
-- ไม่มี backfill: บรรทัดเก่าก่อนหน้านี้ถือว่าไม่ลับ (private=0)
-- ไฟล์นี้เพิ่มคอลัมน์อย่างเดียว ไม่แก้ข้อมูลเดิม และรันซ้ำได้
-- =====================================================

SET NAMES utf8mb4;

ALTER TABLE `project_activities`
  ADD COLUMN IF NOT EXISTS `private` TINYINT(1) NOT NULL DEFAULT 0 AFTER `action`;
