-- =====================================================
-- Migration: Demo Account
-- ทำให้ปุ่ม "ดูตัวอย่าง" ในหน้าล็อกอินใช้งานได้ โดยไม่ต้องสมัครสมาชิก
--
-- วิธีตั้งค่า (ทำครั้งเดียว):
--   1. สมัครสมาชิกปกติผ่านหน้า /register ด้วย username ที่จะใช้เป็นบัญชีตัวอย่าง
--      (ค่าเริ่มต้นด้านล่างใช้ username = 'demo') แล้วเข้าไปกรอกข้อมูลตัวอย่าง
--      (task, note, ฯลฯ) ให้ดูดีตามที่ต้องการโชว์ผู้เยี่ยมชม
--   2. แก้ค่า @demo_username ด้านล่างให้ตรงกับ username ที่สมัครไว้ (ถ้าไม่ใช่ 'demo')
--      แล้วรัน SQL นี้ทั้งไฟล์
--
-- หลังรันแล้ว บัญชีนี้จะ:
--   - เข้าถึงได้แบบอ่านอย่างเดียวผ่านปุ่ม "ดูตัวอย่าง" (route /demo) โดยไม่ต้องล็อกอิน
--   - ล็อกอินด้วยรหัสผ่านปกติ (หรือ Google) ไม่ได้อีกต่อไป (ป้องกันคนแปลกหน้าแก้ไขข้อมูลตัวอย่าง)
--     ถ้าต้องการแก้ไขข้อมูลตัวอย่างในอนาคต ให้รัน
--       UPDATE users SET is_demo = 0 WHERE username = 'demo';
--     ล็อกอินแก้ไข แล้วค่อยรัน UPDATE users SET is_demo = 1 ... ซ้ำอีกครั้ง
-- =====================================================

SET @demo_username := 'demo';

-- 1) เพิ่มคอลัมน์ is_demo ให้ users (ถ้ายังไม่มี)
SET @has_is_demo := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'is_demo'
);
SET @is_demo_sql := IF(
    @has_is_demo = 0,
    'ALTER TABLE users ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE is_demo_stmt FROM @is_demo_sql;
EXECUTE is_demo_stmt;
DEALLOCATE PREPARE is_demo_stmt;

-- 2) ตั้งค่าบัญชีที่ระบุให้เป็นบัญชีตัวอย่าง
UPDATE users SET is_demo = 1 WHERE username = @demo_username COLLATE utf8mb4_unicode_ci;

SET @demo_user_id := (SELECT id FROM users WHERE username = @demo_username COLLATE utf8mb4_unicode_ci LIMIT 1);

-- 3) สร้างลิงก์แชร์แบบอ่านอย่างเดียวให้บัญชีตัวอย่าง (ถ้ายังไม่มี)
INSERT INTO app_shares (user_id, token, label, menus, expires_at)
SELECT @demo_user_id,
       SHA2(CONCAT(RAND(), NOW(6), @demo_user_id), 256),
       'Public Demo',
       '["tasks","notes","planner","focus","exercise","food-notes","finance","subscriptions","stocks"]',
       NULL
WHERE @demo_user_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM app_shares WHERE user_id = @demo_user_id);
