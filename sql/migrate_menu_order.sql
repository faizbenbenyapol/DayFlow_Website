-- Per-user menu visibility and sidebar order. Safe to run repeatedly.
SET @has_hidden_menus := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'user_settings'
      AND column_name = 'hidden_menus'
);
SET @hidden_menus_sql := IF(
    @has_hidden_menus = 0,
    'ALTER TABLE user_settings ADD COLUMN hidden_menus JSON DEFAULT NULL',
    'SELECT 1'
);
PREPARE hidden_menus_stmt FROM @hidden_menus_sql;
EXECUTE hidden_menus_stmt;
DEALLOCATE PREPARE hidden_menus_stmt;

SET @has_menu_order := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'user_settings'
      AND column_name = 'menu_order'
);
SET @menu_order_sql := IF(
    @has_menu_order = 0,
    'ALTER TABLE user_settings ADD COLUMN menu_order JSON DEFAULT NULL',
    'SELECT 1'
);
PREPARE menu_order_stmt FROM @menu_order_sql;
EXECUTE menu_order_stmt;
DEALLOCATE PREPARE menu_order_stmt;
