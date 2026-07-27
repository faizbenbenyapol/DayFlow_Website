-- =====================================================
-- migrate_file_transfer.sql — File Transfer (Send-Anywhere style)
-- =====================================================

CREATE TABLE IF NOT EXISTS `file_transfers` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `code`            VARCHAR(6)   NOT NULL,
    `token`           VARCHAR(64)  NOT NULL,
    `files_json`      TEXT         NOT NULL COMMENT 'JSON array [{name, path, size, mime}]',
    `total_size`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `download_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `max_downloads`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
    `expires_at`      DATETIME     NOT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY `uk_code`   (`code`),
    UNIQUE KEY `uk_token`  (`token`),
    KEY        `idx_user`  (`user_id`),
    KEY        `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
