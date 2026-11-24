-- =====================================================
-- Migration: เงินจากการขายหุ้น "เก็บไว้ลงทุนต่อ" หรือ "ถอนออก"
--   - stock_transactions.proceeds_action: 'reinvest' | 'withdraw' | NULL
--     NULL = รายการซื้อ หรือรายการขายที่บันทึกก่อนมีตัวเลือกนี้ ("ไม่ได้ระบุ")
--   - stock_capital_flows.sale_txn_id: รายการถอนที่ระบบสร้างจากการขาย
--     foreign key ผูกคู่ (sale_txn_id, user_id) กับ (id, user_id) ของรายการขาย
--     รายการถอนจึงชี้ได้เฉพาะรายการขายของเจ้าของเดียวกัน และลบการขายแล้วรายการถอนหายตาม
--     หนึ่งการขายมีได้หนึ่งรายการถอน (UNIQUE)
--   ไม่มี backfill: รายการขายเก่าคงเป็น NULL ไม่เดาให้ และไม่สร้างรายการถอนย้อนหลัง
--   รันซ้ำได้: เพิ่มเฉพาะส่วนที่ยังไม่มี
-- =====================================================

SET @has_proceeds_action := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_transactions' AND column_name = 'proceeds_action'
);

SET @add_action_ddl := IF(@has_proceeds_action = 0,
    'ALTER TABLE `stock_transactions`
       ADD COLUMN `proceeds_action` ENUM(''reinvest'', ''withdraw'') NULL DEFAULT NULL AFTER `fee`',
    'SELECT 1'
);

PREPARE add_action_stmt FROM @add_action_ddl;
EXECUTE add_action_stmt;
DEALLOCATE PREPARE add_action_stmt;

-- ปลายทางของ foreign key แบบสองคอลัมน์ต้องมี index ที่ตรงกัน
SET @has_owner_key := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'stock_transactions' AND index_name = 'uniq_stx_id_user'
);

SET @add_owner_key_ddl := IF(@has_owner_key = 0,
    'ALTER TABLE `stock_transactions` ADD UNIQUE KEY `uniq_stx_id_user` (`id`, `user_id`)',
    'SELECT 1'
);

PREPARE add_owner_key_stmt FROM @add_owner_key_ddl;
EXECUTE add_owner_key_stmt;
DEALLOCATE PREPARE add_owner_key_stmt;

SET @has_sale_txn_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_capital_flows' AND column_name = 'sale_txn_id'
);

SET @add_link_ddl := IF(@has_sale_txn_id = 0,
    'ALTER TABLE `stock_capital_flows`
       ADD COLUMN `sale_txn_id` INT UNSIGNED NULL DEFAULT NULL AFTER `notes`,
       ADD UNIQUE KEY `uniq_scf_sale_txn` (`sale_txn_id`),
       ADD CONSTRAINT `fk_scf_sale_txn`
           FOREIGN KEY (`sale_txn_id`, `user_id`) REFERENCES `stock_transactions`(`id`, `user_id`) ON DELETE CASCADE',
    'SELECT 1'
);

PREPARE add_link_stmt FROM @add_link_ddl;
EXECUTE add_link_stmt;
DEALLOCATE PREPARE add_link_stmt;
