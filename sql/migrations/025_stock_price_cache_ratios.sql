-- Valuation ratios on the shared price cache. Until now
-- models/StockPriceCache.php probed for pe_ratio before every read and ran
-- these ALTERs when it was missing. Safe to run repeatedly; written without
-- ADD COLUMN IF NOT EXISTS so it also runs on MySQL.

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_price_cache' AND column_name = 'pe_ratio'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `stock_price_cache` ADD COLUMN `pe_ratio` DECIMAL(10,2) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_price_cache' AND column_name = 'forward_pe'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `stock_price_cache` ADD COLUMN `forward_pe` DECIMAL(10,2) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_price_cache' AND column_name = 'peg_ratio'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `stock_price_cache` ADD COLUMN `peg_ratio` DECIMAL(10,2) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_price_cache' AND column_name = 'p_fcf_ratio'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `stock_price_cache` ADD COLUMN `p_fcf_ratio` DECIMAL(10,2) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_price_cache' AND column_name = 'eps'
);
SET @ddl := IF(@has_col = 0, 'ALTER TABLE `stock_price_cache` ADD COLUMN `eps` DECIMAL(10,2) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
