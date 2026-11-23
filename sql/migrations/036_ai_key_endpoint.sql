-- =====================================================
-- Migration: AI endpoint ต่อผู้ใช้ (เฟส 5)
--
-- user_ai_keys.base_url: URL ของผู้ให้บริการแบบ OpenAI-compatible ที่ผู้ใช้ใส่เอง
--   (provider = custom) เจ้าอื่นใช้ URL คงที่ใน core/LlmClient.php คอลัมน์นี้จึงเป็น NULL
-- user_ai_keys.model: ชื่อโมเดลที่ผู้ใช้เลือก NULL = โมเดลเริ่มต้นของเจ้านั้น
-- รันซ้ำได้
-- =====================================================

SET @has_base_url := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_ai_keys' AND column_name = 'base_url'
);
SET @ddl := IF(@has_base_url = 0, 'ALTER TABLE `user_ai_keys` ADD COLUMN `base_url` VARCHAR(500) NULL DEFAULT NULL AFTER `api_key_enc`', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_model := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_ai_keys' AND column_name = 'model'
);
SET @ddl := IF(@has_model = 0, 'ALTER TABLE `user_ai_keys` ADD COLUMN `model` VARCHAR(120) NULL DEFAULT NULL AFTER `base_url`', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
